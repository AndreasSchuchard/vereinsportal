<?php
declare(strict_types=1);
session_start();

$_isSuperAdmin  = !empty($_SESSION['kgv_admin']);
$_memberRoles   = $_SESSION['kgv_member']['roles'] ?? [];
$_bookingRoles      = ['vorstand', 'buchung', 'schriftfuehrer', 'web'];
$_isMemberAdmin     = !$_isSuperAdmin && !empty(array_intersect($_memberRoles, $_bookingRoles));
$_canDeleteBooking  = $_isSuperAdmin || !empty(array_intersect($_memberRoles, ['vorstand', 'buchung']));

if (!$_isSuperAdmin && !$_isMemberAdmin) {
    header('Location: /intern/');
    exit;
}

// CSRF-Check (akzeptiert auch GET-CSRF für SF-PDF-Downloads)
$csrf = $_SESSION['csrf'] ?? '';
$_csrfIncoming = (string)($_POST['csrf'] ?? $_GET['csrf'] ?? '');
if ($csrf === '' || !hash_equals($csrf, $_csrfIncoming)) {
    die('Ungültiger CSRF-Token.');
}

define('BOOKINGS_FILE', dirname(__DIR__) . '/data/bookings.json');
require_once dirname(__DIR__) . '/inc/email_template.php';
require_once dirname(__DIR__) . '/inc/vorabinfo_pdf.php';
require_once dirname(__DIR__) . '/inc/local_qr.php';
define('CONTENT_FILE',  dirname(__DIR__) . '/data/content.json');
define('LOG_FILE',      dirname(__DIR__) . '/data/admin.log');
define('TODOS_FILE',    dirname(__DIR__) . '/data/todos.json');
define('TODO_IMG_DIR',  dirname(__DIR__) . '/data/todo_img');

$fromEmail = 'kontakt@example.org';
$fromName  = 'KGV Musterstadt e.V.';

// Einstellungen und E-Mail-Vorlagen aus content.json laden
$_contentData  = file_exists(CONTENT_FILE) ? (json_decode((string)file_get_contents(CONTENT_FILE), true) ?: []) : [];
$_cfg          = $_contentData['settings'] ?? [];
$cfgIban       = $_cfg['iban']         ?? '';
$cfgKontoInhaber = $_cfg['kontoinhaber'] ?? 'KGV Musterstadt e.V.';
$cfgBank       = $_cfg['bank']         ?? '';
// Buchungs-Kontakt: Auto-Lookup im Vorstand (Kassier/Vermietung), Fallback auf settings.kontakt_*
// Sodass Buchungsmails immer von Nicole signiert sind, unabhängig davon, wer im Backoffice bestätigt.
require_once dirname(__DIR__) . '/inc/email_template.php';
$_cfgBookingContact = kgv_get_booking_contact($_contentData);
$cfgKontaktName  = $_cfgBookingContact['name'];
$cfgKontaktRolle = $_cfgBookingContact['rolle'];
$cfgTelefon      = $_cfgBookingContact['phone'];
$cfgEmail        = $_cfgBookingContact['email'];
$cfgZahlungsziel = (int)($_cfg['zahlungsziel_wochen'] ?? 4);
$_etDefault = [
    'confirm_subject' => 'Buchungsbestätigung – KGV Musterstadt Vereinshaus am {datum}',
    'confirm_body'    => "Liebe/r {name},\n\nwir freuen uns, Ihre Buchungsanfrage hiermit verbindlich zu bestätigen!\n\nZeitraum: {datum}\n\nRaummiete gesamt: {betrag}\nEndreinigung (verpflichtend, besenrein übergeben): {endreinigung}\nKaution (Rückerstattung nach Veranstaltung): {kaution}\nZu überweisen gesamt: {gesamt}\n\nEmpfänger: {kontoinhaber}\nIBAN: {iban}\n\nKontakt: {kontakt_name} · {telefon} · {email_kontakt}\n\nMit freundlichen Grüßen\n{kontakt_name}\nKGV Musterstadt e.V.",
    'reject_subject'  => 'Zu Ihrer Anfrage – KGV Musterstadt Vereinshaus am {datum}',
    'reject_body'     => "Liebe/r {name},\n\nvielen Dank für Ihre Anfrage zur Nutzung unseres Vereinshauses am {datum}.\n\nLeider können wir Ihnen diesen Zeitraum nicht anbieten.\n\nFür alternative Terminanfragen:\n{kontakt_name} · {telefon} · {email_kontakt}\n\nMit freundlichen Grüßen\n{kontakt_name}\nKGV Musterstadt e.V.",
];
$cfgEmailTpl = array_merge($_etDefault, $_contentData['email_templates'] ?? []);
unset($_contentData, $_cfg, $_etDefault);

function send_mail_simple(string $to, string $subject, string $text, string $html,
                           string $fromName, string $fromEmail, string $replyTo = '',
                           array $inlineImages = [], array $attachments = []): bool {
    // CRLF injection prevention
    foreach ([$to, $subject, $fromEmail, $replyTo] as $h) {
        if (preg_match('/[\r\n]/', $h)) return false;
    }
    $rt   = $replyTo !== '' ? $replyTo : $fromEmail;
    $bAlt = 'a_' . md5(uniqid('', true));
    $bRel = 'r_' . md5(uniqid('', true));
    $bMix = 'm_' . md5(uniqid('', true));

    // Build the inner content block (alternative + optional inline images)
    $altBlock  = "--{$bAlt}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$text}\r\n\r\n";
    $altBlock .= "--{$bAlt}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n--{$bAlt}--";

    if (empty($inlineImages)) {
        $innerType = "multipart/alternative; boundary=\"{$bAlt}\"";
        $innerBody = $altBlock;
    } else {
        $relBody = "--{$bRel}\r\nContent-Type: multipart/alternative; boundary=\"{$bAlt}\"\r\n\r\n{$altBlock}\r\n\r\n";
        foreach ($inlineImages as $img) {
            $cid  = $img['cid'];
            $mime = $img['mime'] ?? 'image/png';
            $name = $img['name'] ?? 'image.png';
            $b64  = chunk_split(base64_encode($img['data']));
            $relBody .= "--{$bRel}\r\nContent-Type: {$mime}; name=\"{$name}\"\r\n";
            $relBody .= "Content-Transfer-Encoding: base64\r\nContent-ID: <{$cid}>\r\n";
            $relBody .= "Content-Disposition: inline; filename=\"{$name}\"\r\n\r\n{$b64}\r\n";
        }
        $relBody .= "--{$bRel}--";
        $innerType = "multipart/related; boundary=\"{$bRel}\"";
        $innerBody = $relBody;
    }

    if (empty($attachments)) {
        $h  = "MIME-Version: 1.0\r\n";
        $h .= "Content-Type: {$innerType}\r\n";
        $h .= "From: {$fromName} <{$fromEmail}>\r\nReply-To: {$rt}\r\nReturn-Path: {$fromEmail}\r\n";
        return mail($to, $subject, $innerBody, $h, "-f{$fromEmail}");
    }

    // With attachments — wrap in multipart/mixed
    $h  = "MIME-Version: 1.0\r\n";
    $h .= "Content-Type: multipart/mixed; boundary=\"{$bMix}\"\r\n";
    $h .= "From: {$fromName} <{$fromEmail}>\r\nReply-To: {$rt}\r\nReturn-Path: {$fromEmail}\r\n";

    $body  = "--{$bMix}\r\nContent-Type: {$innerType}\r\n\r\n{$innerBody}\r\n\r\n";
    foreach ($attachments as $att) {
        $fname = $att['filename'] ?? 'attachment';
        $mime  = $att['mime']     ?? 'application/octet-stream';
        $b64   = chunk_split(base64_encode($att['data']));
        $body .= "--{$bMix}\r\n";
        $body .= "Content-Type: {$mime}; name=\"{$fname}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$fname}\"\r\n\r\n";
        $body .= $b64 . "\r\n";
    }
    $body .= "--{$bMix}--";
    return mail($to, $subject, $body, $h, "-f{$fromEmail}");
}

function formatDatesDE(array $b): string {
    $dates = $b['dates'] ?? (isset($b['date']) ? [$b['date']] : []);
    if (count($dates) === 0) return '—';
    if (count($dates) === 1) {
        $d = DateTime::createFromFormat('Y-m-d', $dates[0]);
        $fmt = new IntlDateFormatter('de_DE', IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'Europe/Berlin');
        return $fmt && $d ? $fmt->format($d) : ($d ? $d->format('d.m.Y') : $dates[0]);
    }
    $fmt   = new IntlDateFormatter('de_DE', IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'Europe/Berlin');
    $first = DateTime::createFromFormat('Y-m-d', $dates[0]);
    $last  = DateTime::createFromFormat('Y-m-d', end($dates));
    $f     = $fmt && $first ? $fmt->format($first) : ($first ? $first->format('d.m.Y') : $dates[0]);
    $l     = $fmt && $last  ? $fmt->format($last)  : ($last  ? $last->format('d.m.Y')  : end($dates));
    return "{$f} – {$l} (" . count($dates) . " Tage)";
}

function applyTemplate(string $tpl, array $vars): string {
    foreach ($vars as $k => $v) {
        $tpl = str_replace('{' . $k . '}', (string)$v, $tpl);
    }
    return $tpl;
}

$bookingId    = trim((string)($_POST['booking_id']    ?? ''));
$action       = trim((string)($_POST['action']       ?? ''));
$extrasJson     = trim((string)($_POST['extras']         ?? '[]'));
$adminNote      = trim((string)($_POST['admin_note']     ?? ''));
$adminMessage   = trim((string)($_POST['admin_message']  ?? ''));
$isMemberTarif  = ($_POST['is_member_tarif'] ?? '0') === '1';

$extras = [];
$decoded = json_decode($extrasJson, true);
if (is_array($decoded)) {
    foreach ($decoded as $e) {
        if (!isset($e['label']) || (string)$e['label'] === '') continue;
        $extras[] = [
            'label' => trim((string)$e['label']),
            'qty'   => max(1, (int)($e['qty'] ?? 1)),
            'price' => max(0.0, (float)($e['price'] ?? 0)),
        ];
    }
}

// ── Todo-Handler ──────────────────────────────────────────────────────────
$_todoRoles    = ['vorstand', 'web'];
$_canWriteTodo = $_isSuperAdmin || !empty(array_intersect($_memberRoles, $_todoRoles));
$_todoWho      = $_isSuperAdmin ? 'SuperAdmin' : (string)($_SESSION['kgv_member']['name'] ?? '');

function _loadTodos(): array {
    if (!file_exists(TODOS_FILE)) return [];
    $d = json_decode((string)file_get_contents(TODOS_FILE), true);
    return is_array($d) ? $d : [];
}
function _saveTodos(array $t): void {
    file_put_contents(TODOS_FILE, json_encode($t, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function _uploadTodoImgs(string $todoId): array {
    if (!isset($_FILES['todo_images']) || empty($_FILES['todo_images']['name'][0]) && empty($_FILES['todo_images']['name'])) return [];
    if (!is_dir(TODO_IMG_DIR)) mkdir(TODO_IMG_DIR, 0755, true);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $saved = [];
    $f = $_FILES['todo_images'];
    $n = is_array($f['name']) ? count($f['name']) : 1;
    for ($i = 0; $i < $n; $i++) {
        $err  = is_array($f['error'])    ? $f['error'][$i]    : $f['error'];
        $tmp  = is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'];
        $size = is_array($f['size'])     ? $f['size'][$i]     : $f['size'];
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) continue;
        if ($size > 5 * 1024 * 1024) continue;
        $mime = mime_content_type($tmp);
        if (!isset($allowed[$mime])) continue;
        $fname = $todoId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
        if (move_uploaded_file($tmp, TODO_IMG_DIR . '/' . $fname)) $saved[] = $fname;
    }
    return $saved;
}

// ── Schriftführerin-Cockpit: Action-Dispatch ──────────────────────────────
// Sichtbar für SuperAdmin + Schriftführer + Web-Rolle (vorstand bleibt vorerst aus).
$_sfActionPost = $action;
$_sfActionGet  = trim((string)($_GET['action'] ?? ''));
$_sfActionAny  = $_sfActionPost !== '' ? $_sfActionPost : $_sfActionGet;
if (str_starts_with($_sfActionAny, 'sf_') || $_sfActionAny === 'save_member_since') {
    $_canSfAct = $_isSuperAdmin
        || in_array('schriftfuehrer', $_memberRoles, true)
        || in_array('web', $_memberRoles, true);
    if (!$_canSfAct) { http_response_code(403); exit('Schriftführung-Cockpit: keine Berechtigung.'); }
    require_once dirname(__DIR__) . '/inc/sf_actions.php';
    if (sf_handle_action($_sfActionAny, (string)($_POST['csrf'] ?? $_GET['csrf'] ?? ''))) {
        exit; // handler hat redirected/exited
    }
}

if ($action === 'add_todo') {
    if (!$_canWriteTodo) { header('Location: /intern/?tab=todos'); exit; }
    $text = trim((string)($_POST['todo_text'] ?? ''));
    if ($text !== '') {
        $todos  = _loadTodos();
        $newId  = 'todo_' . time() . '_' . bin2hex(random_bytes(4));
        $imgs   = _uploadTodoImgs($newId);
        $todos[] = [
            'id'         => $newId,
            'text'       => $text,
            'images'     => $imgs,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => $_todoWho,
            'done'       => false,
            'done_at'    => null,
            'done_by'    => null,
        ];
        _saveTodos($todos);
        kgv_log_action('Todo erstellt', mb_substr($text, 0, 60));

        // ── Notification an Webmaster ──────────────────────────────────────────
        $_notifyTo      = 'andreas@wolf-hamburg.net';
        $_notifySubject = '🆕 Neues Todo im Backoffice – ' . mb_substr($text, 0, 50) . (mb_strlen($text) > 50 ? '…' : '');
        $_notifyText    = "Hallo Andreas,\n\nim Backoffice wurde ein neues Todo erstellt.\n\n"
                        . "Erstellt von: {$_todoWho}\n"
                        . "Datum: " . date('d.m.Y H:i') . "\n\n"
                        . "─────────────────────\n"
                        . $text . "\n"
                        . "─────────────────────\n\n"
                        . "Direktlink: https://kgv461.de/intern/?tab=todos\n";
        $_notifyHtml    = kgv_email_html(
            'Hallo Andreas 👋,',
              "<p style='color:#5a6c5a;line-height:1.7;margin-bottom:14px'>im Backoffice wurde ein neues Todo erstellt:</p>"
            . "<table style='font-size:0.9rem;color:#5a6c5a;margin-bottom:16px'>"
            .   "<tr><td style='padding:4px 12px 4px 0'>Von</td><td style='font-weight:600;color:#2d3e2d'>" . htmlspecialchars($_todoWho) . "</td></tr>"
            .   "<tr><td style='padding:4px 12px 4px 0'>Datum</td><td style='font-weight:600;color:#2d3e2d'>" . date('d.m.Y H:i') . "</td></tr>"
            . "</table>"
            . "<div style='background:#f5f7f2;border-left:4px solid #3d6b41;border-radius:6px;padding:14px 18px;margin-bottom:18px;color:#2d3e2d;line-height:1.6;white-space:pre-wrap'>"
            . htmlspecialchars($text)
            . "</div>"
            . "<p style='margin-bottom:8px'><a href='https://kgv461.de/intern/?tab=todos' style='display:inline-block;background:#3d6b41;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:600'>→ Zum Backoffice</a></p>",
            'Neues Todo – KGV Musterstadt',
            'KGV Musterstadt e.V.', '', 'kontakt@example.org', 'Webmaster-Notification'
        );
        @send_mail_simple($_notifyTo, $_notifySubject, $_notifyText, $_notifyHtml,
                          'KGV Musterstadt e.V.', 'kontakt@example.org', 'kontakt@example.org');
    }
    header('Location: /intern/?tab=todos'); exit;
}

if ($action === 'add_todo_img') {
    if (!$_canWriteTodo) { header('Location: /intern/?tab=todos'); exit; }
    $todoId = trim((string)($_POST['todo_id'] ?? ''));
    if ($todoId !== '') {
        $todos = _loadTodos();
        foreach ($todos as &$td) {
            if ($td['id'] !== $todoId) continue;
            $td['images'] = array_merge($td['images'] ?? [], _uploadTodoImgs($todoId));
            break;
        }
        unset($td);
        _saveTodos($todos);
    }
    header('Location: /intern/?tab=todos'); exit;
}

if ($action === 'add_todo_note') {
    if (!$_canWriteTodo) { header('Location: /intern/?tab=todos'); exit; }
    $todoId   = trim((string)($_POST['todo_id']   ?? ''));
    $noteText = trim((string)($_POST['note_text'] ?? ''));
    if ($todoId !== '' && $noteText !== '') {
        $todos = _loadTodos();
        foreach ($todos as &$td) {
            if ($td['id'] !== $todoId) continue;
            if (!isset($td['notes'])) $td['notes'] = [];
            $td['notes'][] = [
                'text' => $noteText,
                'by'   => $_todoWho,
                'at'   => date('Y-m-d H:i:s'),
            ];
            break;
        }
        unset($td);
        _saveTodos($todos);
    }
    header('Location: /intern/?tab=todos'); exit;
}

if ($action === 'toggle_todo') {
    if (!$_canWriteTodo) { header('Location: /intern/?tab=todos'); exit; }
    $todoId = trim((string)($_POST['todo_id'] ?? ''));
    if ($todoId !== '') {
        $todos = _loadTodos();
        foreach ($todos as &$td) {
            if ($td['id'] !== $todoId) continue;
            if (empty($td['done'])) {
                $td['done']    = true;
                $td['done_at'] = date('Y-m-d H:i:s');
                $td['done_by'] = $_todoWho;
                kgv_log_action('Todo erledigt', mb_substr($td['text'] ?? '', 0, 60));
            } else {
                $td['done']    = false;
                $td['done_at'] = null;
                $td['done_by'] = null;
                kgv_log_action('Todo reaktiviert', $todoId);
            }
            break;
        }
        unset($td);
        _saveTodos($todos);
    }
    header('Location: /intern/?tab=todos'); exit;
}

if ($action === 'delete_todo') {
    if (!$_canWriteTodo) { header('Location: /intern/?tab=todos'); exit; }
    $todoId = trim((string)($_POST['todo_id'] ?? ''));
    if ($todoId !== '') {
        $todos = _loadTodos();
        foreach ($todos as $td) {
            if ($td['id'] !== $todoId) continue;
            foreach ($td['images'] ?? [] as $img) {
                $p = TODO_IMG_DIR . '/' . basename($img);
                if (file_exists($p)) @unlink($p);
            }
            kgv_log_action('Todo gelöscht', mb_substr($td['text'] ?? '', 0, 60));
            break;
        }
        _saveTodos(array_values(array_filter($todos, fn($t) => $t['id'] !== $todoId)));
    }
    header('Location: /intern/?tab=todos'); exit;
}

// ── Manuelle Buchung anlegen ───────────────────────────────────────────────
if ($action === 'create_booking') {
    $cbName    = trim(strip_tags((string)($_POST['cb_name']    ?? '')));
    $cbEmail   = strtolower(trim((string)($_POST['cb_email']   ?? '')));
    $cbPhone   = trim(strip_tags((string)($_POST['cb_phone']   ?? '')));
    $cbPurpose = trim(strip_tags((string)($_POST['cb_purpose'] ?? '')));
    $cbGuests  = max(1, (int)($_POST['cb_guests'] ?? 1));
    $cbNote       = trim(strip_tags((string)($_POST['cb_note']      ?? '')));
    $cbIsMember   = !empty($_POST['cb_is_member']) && $_POST['cb_is_member'] === '1';
    $cbStatus     = in_array($_POST['cb_status'] ?? '', ['pending','confirmed'], true) ? $_POST['cb_status'] : 'confirmed';
    $_cbPrices    = (file_exists(CONTENT_FILE) ? (json_decode((string)file_get_contents(CONTENT_FILE), true) ?: []) : [])['prices'] ?? [];
    $_cbMieteBase = (float)($_cbPrices['miete'] ?? 300);
    $cbRaummiete  = $cbIsMember ? round($_cbMieteBase / 2, 2) : $_cbMieteBase;
    $cbDates   = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['cb_dates'] ?? '')))));
    // validate dates — strict roundtrip, sodass '2026-13-45' o.ä. nicht akzeptiert wird
    $_tz = new DateTimeZone('Europe/Berlin');
    $cbDates = array_values(array_filter($cbDates, function($d) use ($_tz) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $d, $_tz);
        return $dt && $dt->format('Y-m-d') === $d;
    }));
    if ($cbName !== '' && count($cbDates) > 0) {
        $bookings = file_exists(BOOKINGS_FILE) ? (json_decode((string)file_get_contents(BOOKINGS_FILE), true) ?: []) : [];
        $now = date('Y-m-d H:i:s');
        $newB = [
            'id'         => 'b_' . uniqid('', true),
            'name'       => $cbName,
            'email'      => $cbEmail,
            'phone'      => $cbPhone,
            'purpose'    => $cbPurpose,
            'guests'     => $cbGuests,
            'dates'      => $cbDates,
            'extras'         => $extras,
            'status'         => $cbStatus,
            'admin_note'     => $cbNote,
            'is_member_tarif'=> $cbIsMember,
            'raummiete'      => $cbRaummiete,
            'created_at'     => $now,
            'updated_at'     => $now,
            'manual'         => true,
        ];
        if ($cbStatus === 'confirmed') {
            $newB['paid_at'] = !empty($_POST['cb_paid']) ? $now : null;
        }
        $bookings[] = $newB;
        file_put_contents(BOOKINGS_FILE, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        kgv_log_action('Buchung manuell angelegt', $cbName . ' · ' . implode(', ', array_map(fn($d) => (new DateTime($d))->format('d.m.Y'), $cbDates)));
        error_log("[admin] create_booking manual: {$cbName}\n", 3, LOG_FILE);
    }
    header('Location: /intern/?tab=bookings');
    exit;
}

// Entfernt automatisch gesperrte Vor-/Folgetage einer Buchung aus content.json.
// Matcht eindeutig über Booking-ID-Suffix `[id]` im reason; Fallback: Name-Match (Legacy-Einträge).
function removeBookingBufferDay(array $bDates, string $bookingId = '', string $bookingName = ''): void {
    if (empty($bDates)) return;
    sort($bDates);
    $bufferBefore = (new DateTimeImmutable($bDates[0]))->modify('-1 day')->format('Y-m-d');
    $bufferAfter  = (new DateTimeImmutable(end($bDates)))->modify('+1 day')->format('Y-m-d');
    if (!file_exists(CONTENT_FILE)) return;
    $cData = json_decode((string)file_get_contents(CONTENT_FILE), true) ?: [];
    $before = count($cData['blocked_dates'] ?? []);
    $cData['blocked_dates'] = array_values(array_filter(
        $cData['blocked_dates'] ?? [],
        function($bd) use ($bufferBefore, $bufferAfter, $bookingId, $bookingName) {
            $date = $bd['date'] ?? '';
            if (!in_array($date, [$bufferBefore, $bufferAfter], true)) return true;
            $reason = $bd['reason'] ?? '';
            if (!str_starts_with($reason, 'Vorbereitung/Übergabe:')
                && !str_starts_with($reason, 'Aufräumen/Übergabe:')) return true;
            if ($bookingId !== '' && str_contains($reason, '[' . $bookingId . ']')) return false;
            if ($bookingName !== '' && str_contains($reason, ': ' . $bookingName . ' (')) return false;
            return true;
        }
    ));
    if (count($cData['blocked_dates']) !== $before) {
        file_put_contents(CONTENT_FILE, json_encode($cData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

// ── Buchung löschen ───────────────────────────────────────────────────────
if ($action === 'delete_booking') {
    if (!$_canDeleteBooking) {
        header('Location: /intern/');
        exit;
    }
    if ($bookingId !== '') {
        $bookings = [];
        if (file_exists(BOOKINGS_FILE)) {
            $raw = json_decode((string)file_get_contents(BOOKINGS_FILE), true);
            if (is_array($raw)) $bookings = $raw;
        }
        // Vortag-Sperre aus content.json entfernen
        foreach ($bookings as $_db) {
            if (($_db['id'] ?? '') === $bookingId) { removeBookingBufferDay($_db['dates'] ?? [], $bookingId, (string)($_db['name'] ?? '')); break; }
        }
        $bookings = array_values(array_filter($bookings, fn($b) => ($b['id'] ?? '') !== $bookingId));
        file_put_contents(BOOKINGS_FILE, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        error_log("[admin] delete_booking: {$bookingId}\n", 3, LOG_FILE);
        kgv_log_action('Buchung gelöscht', $bookingId);
    }
    header('Location: /intern/?tab=bookings');
    exit;
}

if ($bookingId === '' || !in_array($action, ['confirm', 'reject', 'mark_paid', 'unmark_paid', 'mark_kaution_returned', 'settle_kaution', 'update_note', 'update_email'], true)) {
    header('Location: /intern/');
    exit;
}

// Load bookings
$bookings = [];
if (file_exists(BOOKINGS_FILE)) {
    $raw = json_decode((string)file_get_contents(BOOKINGS_FILE), true);
    if (is_array($raw)) $bookings = $raw;
}

$found = false;
foreach ($bookings as &$b) {
    if (($b['id'] ?? '') !== $bookingId) continue;
    $found = true;
    if ($action === 'update_note') {
        $b['admin_note'] = trim((string)($_POST['admin_note'] ?? ''));
        error_log("[admin] update_note: {$bookingId}\n", 3, LOG_FILE);
        break;
    }
    if ($action === 'mark_paid') {
        $b['paid_at'] = date('Y-m-d H:i:s');
        $found = true;
        break;
    }
    if ($action === 'unmark_paid') {
        unset($b['paid_at']);
        $found = true;
        break;
    }
    if ($action === 'update_email') {
        $newEmail = strtolower(trim((string)($_POST['new_email'] ?? '')));
        if (filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $b['email'] = $newEmail;
            $found = true;
            file_put_contents(BOOKINGS_FILE, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            kgv_log_action('E-Mail korrigiert', $bookingId . ' → ' . $newEmail);
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'email' => $newEmail]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Ungültige E-Mail-Adresse']);
        }
        exit;
    }
    if ($action === 'mark_kaution_returned') {
        $b['kaution_returned_at'] = date('Y-m-d H:i:s');
        $found = true;
        break;
    }

    if ($action === 'settle_kaution') {
        // Preise aus content.json laden
        $_sContent    = file_exists(CONTENT_FILE) ? (json_decode((string)file_get_contents(CONTENT_FILE), true) ?: []) : [];
        $_stromKwh    = (float)($_sContent['prices']['strom_kwh'] ?? 0.35);
        $_kautionBtg  = (float)($_sContent['prices']['kaution']   ?? 200.0);

        $kwh             = max(0.0, (float)str_replace(',', '.', (string)($_POST['kwh'] ?? '0')));
        $electricityCost = round($kwh * $_stromKwh, 2);

        // Schadenspositionen
        $damageDescs   = (array)($_POST['damage_desc']   ?? []);
        $damageAmounts = (array)($_POST['damage_amount'] ?? []);
        $damages = [];
        $damagesTotal = 0.0;
        foreach ($damageDescs as $_di => $_dd) {
            $_dd = trim(strip_tags($_dd));
            $_da = max(0.0, (float)str_replace(',', '.', (string)($damageAmounts[$_di] ?? '0')));
            if ($_dd !== '' && $_da > 0) {
                $damages[]     = ['desc' => $_dd, 'amount' => $_da];
                $damagesTotal += $_da;
            }
        }

        $totalDeductions = round($electricityCost + $damagesTotal, 2);
        $returnedAmount  = max(0.0, round($_kautionBtg - $totalDeductions, 2));
        $settleNote      = trim(strip_tags((string)($_POST['settle_note'] ?? '')));
        $_who            = $_isSuperAdmin ? 'SuperAdmin' : (string)($_SESSION['kgv_member']['name'] ?? '');

        $b['kaution_returned_at'] = date('Y-m-d H:i:s');
        $b['kaution_settlement']  = [
            'kwh'              => $kwh,
            'kwh_price'        => $_stromKwh,
            'electricity_cost' => $electricityCost,
            'damages'          => $damages,
            'total_deductions' => $totalDeductions,
            'kaution_betrag'   => $_kautionBtg,
            'returned_amount'  => $returnedAmount,
            'note'             => $settleNote,
            'settled_at'       => date('Y-m-d H:i:s'),
            'settled_by'       => $_who,
        ];

        // E-Mail an Mieter
        $_sEmail = $b['email'] ?? '';
        $_sName  = $b['name']  ?? '';
        $_sDates = formatDatesDE($b);
        if (filter_var($_sEmail, FILTER_VALIDATE_EMAIL)) {
            $_sSubject = 'Kautionsabrechnung – Vereinshaus KGV Musterstadt';
            $_sText    = "Liebe/r {$_sName},\n\nvielen herzlichen Dank für Ihre Veranstaltung in unserem Vereinshaus!\n\n";
            $_sText   .= "Hier die Abrechnung Ihrer Kaution:\n\n";
            $_sText   .= "Kaution:          " . number_format($_kautionBtg, 2, ',', '.') . " €\n";
            if ($kwh > 0) $_sText .= "− Strom (" . number_format($kwh, 2, ',', '.') . " kWh × " . number_format($_stromKwh, 2, ',', '.') . " €): −" . number_format($electricityCost, 2, ',', '.') . " €\n";
            foreach ($damages as $_dmg) {
                $_sText .= "− " . $_dmg['desc'] . ": −" . number_format($_dmg['amount'], 2, ',', '.') . " €\n";
            }
            $_sText .= "────────────────\n";
            $_sText .= "Rückzahlung:      " . number_format($returnedAmount, 2, ',', '.') . " €\n";
            if ($settleNote !== '') $_sText .= "\n{$settleNote}\n";
            $_sText .= "\nMit freundlichen Grüßen\n" . $cfgKontaktName . "\nKGV Musterstadt e.V.";

            // HTML-Version
            $_dmgRowsHtml = '';
            foreach ($damages as $_dmg) {
                $_dmgRowsHtml .= "<tr><td style='padding:5px 0;color:#c62828;font-size:0.9rem;border-top:1px solid #fce4e4'>− " . htmlspecialchars($_dmg['desc']) . "</td><td style='padding:5px 0;color:#c62828;text-align:right;border-top:1px solid #fce4e4'>−" . number_format($_dmg['amount'], 2, ',', '.') . " €</td></tr>";
            }
            $_sContent2 =
                  "<div style='background:#e8f5e9;border-radius:8px;padding:14px 18px;margin-bottom:22px'>"
                . "<p style='margin:0;font-weight:700;color:#2e7d32;font-size:1.02rem'>💚 Vielen Dank für Ihren Besuch!</p>"
                . "<p style='margin:4px 0 0;font-size:0.85rem;color:#5a6c5a'>Kautionsabrechnung für Ihre Veranstaltung am " . htmlspecialchars($_sDates) . "</p>"
                . "</div>"
                . ($settleNote !== '' ? "<p style='color:#2d3e2d;line-height:1.7;margin-bottom:20px'>" . nl2br(htmlspecialchars($settleNote)) . "</p>" : '')
                . "<div style='background:#f5f7f2;border-radius:8px;padding:16px 20px;margin-bottom:20px;border-left:4px solid #6a1b9a'>"
                . "<p style='margin:0 0 12px;font-size:0.72rem;font-weight:700;color:#6a1b9a;text-transform:uppercase;letter-spacing:.08em'>Kautionsabrechnung</p>"
                . "<table style='width:100%;border-collapse:collapse'>"
                . "<tr><td style='padding:6px 0;color:#5a6c5a;font-size:0.9rem'>Kaution</td><td style='padding:6px 0;font-weight:600;color:#2d3e2d;text-align:right'>" . number_format($_kautionBtg, 2, ',', '.') . " €</td></tr>"
                . ($kwh > 0 ? "<tr><td style='padding:5px 0;color:#c62828;font-size:0.9rem;border-top:1px solid #fce4e4'>− Strom (" . number_format($kwh, 2, ',', '.') . " kWh × " . number_format($_stromKwh, 2, ',', '.') . " €/kWh)</td><td style='padding:5px 0;color:#c62828;text-align:right;border-top:1px solid #fce4e4'>−" . number_format($electricityCost, 2, ',', '.') . " €</td></tr>" : '')
                . $_dmgRowsHtml
                . "<tr style='background:rgba(106,27,154,0.05)'><td style='padding:10px 0 6px;font-weight:700;color:#3d6b41;font-size:1.05rem;border-top:2px solid #ce93d8'>Rückzahlung an Sie</td><td style='padding:10px 0 6px;font-weight:700;color:#3d6b41;text-align:right;font-size:1.2rem;border-top:2px solid #ce93d8'>" . number_format($returnedAmount, 2, ',', '.') . " €</td></tr>"
                . "</table></div>"
                . "<p style='font-size:0.82rem;color:#5a6c5a;line-height:1.6'>Bei Fragen stehen wir Ihnen gerne zur Verfügung.<br>"
                . "<strong>" . htmlspecialchars($cfgKontaktName) . "</strong> · " . htmlspecialchars($cfgTelefon) . " · " . htmlspecialchars($cfgEmail) . "</p>";

            // Buchungs-Kontakt für Kautions-Abrechnung (statt eingeloggtem Vorstand)
            $_sHtml = kgv_email_html(
                'Hallo ' . htmlspecialchars($_sName) . ' 👋,',
                $_sContent2,
                'Kautionsabrechnung',
                $cfgKontaktName, $cfgTelefon, $cfgEmail, $cfgKontaktRolle
            );
            send_mail_simple($_sEmail, $_sSubject, $_sText, $_sHtml, $fromName, $fromEmail, $fromEmail);
        }

        kgv_log_action('Kaution abgerechnet', $_sName . ' · zurück: ' . number_format($returnedAmount, 2, ',', '.') . ' €');
        $found = true;
        break;
    }

    $b['status']     = ($action === 'confirm') ? 'confirmed' : 'rejected';
    $b['updated_at'] = date('Y-m-d H:i:s');
    if ($action === 'confirm') {
        $b['extras']          = $extras;
        $b['admin_note']      = $adminNote;
        $b['admin_message']   = $adminMessage;
        $b['is_member_tarif'] = $isMemberTarif;
        // Mietpreis dynamisch aus content.json (statt hardcoded 150/300)
        $_pricesB             = (file_exists(CONTENT_FILE) ? (json_decode((string)file_get_contents(CONTENT_FILE), true) ?: []) : [])['prices'] ?? [];
        $_mieteB              = (float)($_pricesB['miete'] ?? 300);
        $b['raummiete']       = $isMemberTarif ? round($_mieteB / 2, 2) : $_mieteB;
    }

    $name         = $b['name']  ?? '';
    $email        = $b['email'] ?? '';
    $phone        = $b['phone'] ?? '';
    $purpose      = $b['purpose'] ?? '';
    $guests       = (int)($b['guests'] ?? 0);
    $dateFormatted = formatDatesDE($b);
    $dates        = $b['dates'] ?? (isset($b['date']) ? [$b['date']] : []);
    $dateShort    = count($dates) > 0
        ? (new DateTime($dates[0]))->format('d.m.Y')
        : '';

    if ($action === 'confirm') {
        // ---------------------------------------------------------------
        // Bestätigungs-E-Mail an Buchenden
        // ---------------------------------------------------------------
        // Preise aus content.json laden (alle dynamisch — keine hardcoded Werte)
        $_cfPrices       = (file_exists(CONTENT_FILE) ? (json_decode((string)file_get_contents(CONTENT_FILE), true) ?: []) : [])['prices'] ?? [];
        $mieteBase       = (float)($_cfPrices['miete']                 ?? 300.0);
        $endreinigungExt = (float)($_cfPrices['endreinigung']          ?? 50.0);
        $endreinigungMem = (float)($_cfPrices['endreinigung_mitglied'] ?? 0.0);
        $kautionBtg      = (float)($_cfPrices['kaution']               ?? 200.0);

        // Kostenberechnung — Mitgliedstarif = Miete / 2 + eigene Endreinigung
        $raummiete    = $isMemberTarif ? round($mieteBase / 2, 2) : $mieteBase;
        $endreinigung = $isMemberTarif ? $endreinigungMem        : $endreinigungExt;
        $extrasTotal = $raummiete;
        $extrasTextLines = '';
        $extrasHtmlRows  = '';
        foreach ($extras as $ex) {
            $sub  = $ex['price'] * $ex['qty'];
            $extrasTotal += $sub;
            $extrasTextLines .= $ex['label'] . ($ex['qty'] > 1 ? ' × ' . $ex['qty'] : '') . ': ' . number_format($sub, 2, ',', '.') . " EUR\n";
            $extrasHtmlRows  .= "<tr><td style='padding:5px 0;color:#5a6c5a;font-size:0.9rem;border-top:1px solid #e0ead6;'>"
                             . htmlspecialchars($ex['label']) . ($ex['qty'] > 1 ? ' × ' . $ex['qty'] : '')
                             . "</td><td style='padding:5px 0;font-weight:600;color:#2d3e2d;border-top:1px solid #e0ead6;text-align:right;'>"
                             . number_format($sub, 2, ',', '.') . " €</td></tr>";
        }
        $grandTotal = $extrasTotal + $endreinigung + $kautionBtg;

        $tplVars = [
            'name'          => $name,
            'datum'         => $dateFormatted,
            'betrag'        => number_format($extrasTotal, 2, ',', '.') . ' EUR',
            'endreinigung'  => number_format($endreinigung, 2, ',', '.') . ' EUR',
            'kaution'       => number_format($kautionBtg,   2, ',', '.') . ' EUR',
            'zahlungsziel'  => $cfgZahlungsziel . ' Wochen',
            'gesamt'        => number_format($grandTotal,  2, ',', '.') . ' EUR',
            'iban'          => $cfgIban,
            'kontoinhaber'  => $cfgKontoInhaber,
            'kontakt_name'  => $cfgKontaktName,
            'telefon'       => $cfgTelefon,
            'email_kontakt' => $cfgEmail,
        ];
        $subject = applyTemplate($cfgEmailTpl['confirm_subject'], $tplVars);
        $text    = applyTemplate($cfgEmailTpl['confirm_body'],    $tplVars);
        if ($extrasTextLines !== '') {
            $text .= "\n\nExtras:\n" . $extrasTextLines;
        }
        if ($adminMessage !== '') {
            $text = $adminMessage . "\n\n" . $text;
        }

        // ── EPC/GiroCode QR generieren ──────────────────────────────────────
        $epcIban      = preg_replace('/\s+/', '', $cfgIban); // IBAN ohne Leerzeichen
        $epcAmount    = 'EUR' . number_format($grandTotal, 2, '.', '');
        $epcReference = 'Vereinshaus ' . $dateShort . ' | ' . $name;
        $epcData      = implode("\n", [
            'BCD',        // Service Tag
            '002',        // Version
            '1',          // Encoding: UTF-8
            'SCT',        // SEPA Credit Transfer
            '',           // BIC (optional, leer für SEPA-Inland)
            $cfgKontoInhaber,
            $epcIban,
            $epcAmount,
            '',           // Purpose code (leer)
            '',           // Structured reference (leer)
            $epcReference,
        ]);
        $epcQrHtml   = '';
        $qrInlineImg = [];
        if ($epcIban !== '') {
            try {
                $qrImg = kgv_qr_png($epcData, 180);
                $qrCid       = 'qr_' . bin2hex(random_bytes(8)) . '@kgv461';
                $qrInlineImg = [['cid' => $qrCid, 'data' => $qrImg, 'mime' => 'image/png', 'name' => 'girocode.png']];
                $epcQrHtml =
                    "<div style='background:#f8faf8;border-radius:10px;padding:20px;margin-bottom:20px;"
                  . "border:1px solid #d4e6c3;text-align:center'>"
                  . "<p style='margin:0 0 6px;font-size:0.72rem;font-weight:700;color:#3d6b41;"
                  .    "text-transform:uppercase;letter-spacing:.08em'>Direkt mit Banking-App bezahlen</p>"
                  . "<p style='margin:0 0 14px;font-size:0.82rem;color:#5a6c5a;line-height:1.5'>"
                  .    "QR-Code mit deiner Banking-App scannen →<br>Überweisung wird automatisch befüllt</p>"
                  . "<img src='cid:{$qrCid}' width='160' height='160'"
                  .    " alt='GiroCode QR' style='display:block;margin:0 auto 14px;border:6px solid #fff;"
                  .    "border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.12)'>"
                  . "<p style='margin:0;font-size:0.75rem;color:#8a9a8a;line-height:1.4'>"
                  .    "Unterstützt von Sparkasse, Volksbank, DKB, ING, Commerzbank u.v.m.</p>"
                  . "</div>";
            } catch (Throwable $e) {
                error_log('[girocode] ' . $e->getMessage(), 3, LOG_FILE);
            }
        }

        // IBAN formatiert für Anzeige (Gruppen zu 4)
        $epcIbanFormatted = trim(chunk_split($epcIban, 4, ' '));

        // Sandras Custom-Text aus dem confirm_body-Template — Anrede entfernen, da Greeting bereits oben
        $_confTextNoGreeting = preg_replace('/^\s*Liebe\/r\s+' . preg_quote($name, '/') . '\s*,?\s*\r?\n+/u', '', $text) ?? $text;

        $_confirmContent =
              "<div style='background:#e8f5e9;border-radius:8px;padding:14px 18px;margin-bottom:22px'>"
            . "<p style='margin:0;font-weight:700;color:#2e7d32;font-size:1.02rem'>✓ Deine Buchung ist bestätigt!</p>"
            . "<p style='margin:4px 0 0;font-size:0.85rem;color:#5a6c5a'>Verbindliche Buchungsbestätigung</p></div>"
            . "<div style='color:#5a6c5a;line-height:1.7;margin-bottom:20px;white-space:pre-wrap'>"
            . nl2br(htmlspecialchars($_confTextNoGreeting), false)
            . "</div>"
            . ($adminMessage !== '' ? "<div style='background:#e8f5e9;border-radius:8px;padding:14px 18px;margin-bottom:20px;border-left:4px solid #3d6b41'><p style='margin:0;color:#2d3e2d;line-height:1.7'>" . nl2br(htmlspecialchars($adminMessage)) . "</p></div>" : '')

            // Buchungsdetails
            . "<div style='background:#f5f7f2;border-radius:8px;padding:16px 20px;margin-bottom:20px;border-left:4px solid #3d6b41'>"
            . "<p style='margin:0 0 10px;font-size:0.72rem;font-weight:700;color:#5a8c5e;text-transform:uppercase;letter-spacing:.08em'>Buchungsdetails</p>"
            . "<table style='width:100%;border-collapse:collapse'>"
            . "<tr><td style='padding:6px 0;color:#5a6c5a;width:90px;font-size:0.9rem'>Zeitraum</td><td style='padding:6px 0;font-weight:700;color:#2d3e2d'>" . htmlspecialchars($dateFormatted) . "</td></tr>"
            . ($purpose !== '' ? "<tr><td style='padding:6px 0;color:#5a6c5a;font-size:0.9rem;border-top:1px solid #e8f0e0'>Anlass</td><td style='padding:6px 0;color:#2d3e2d;border-top:1px solid #e8f0e0'>" . htmlspecialchars($purpose) . "</td></tr>" : '')
            . ($guests > 0 ? "<tr><td style='padding:6px 0;color:#5a6c5a;font-size:0.9rem;border-top:1px solid #e8f0e0'>Personen</td><td style='padding:6px 0;color:#2d3e2d;border-top:1px solid #e8f0e0'>{$guests}</td></tr>" : '')
            . "</table></div>"

            // Kostenaufstellung
            . "<div style='background:#fff8e1;border-radius:8px;padding:16px 20px;margin-bottom:20px;border-left:4px solid #f9a825'>"
            . "<p style='margin:0 0 10px;font-size:0.72rem;font-weight:700;color:#f57f17;text-transform:uppercase;letter-spacing:.08em'>Kostenaufstellung</p>"
            . ($isMemberTarif ? "<div style='display:inline-block;background:#e8f5e9;color:#2e7d32;font-size:0.75rem;font-weight:700;padding:3px 10px;border-radius:20px;margin-bottom:10px'>✓ Mitgliedstarif · 50% Rabatt</div>" : '')
            . "<table style='width:100%;border-collapse:collapse'>"
            . "<tr><td style='padding:5px 0;color:#5a6c5a;font-size:0.9rem'>" . ($isMemberTarif ? 'Raummiete <span style="color:#2e7d32;font-size:0.8rem">(Mitgliedstarif)</span>' : 'Raummiete') . "</td><td style='padding:5px 0;font-weight:600;color:#2d3e2d;text-align:right'>" . number_format($raummiete, 2, ',', '.') . " €</td></tr>"
            . $extrasHtmlRows
            . "<tr><td style='padding:8px 0 0;color:#2d3e2d;font-weight:700;border-top:2px solid #e0c000'>Raummiete gesamt</td><td style='padding:8px 0 0;font-weight:700;color:#2d3e2d;border-top:2px solid #e0c000;text-align:right;font-size:1.05rem'>" . number_format($extrasTotal, 2, ',', '.') . " €</td></tr>"
            . ($endreinigung > 0
                ? "<tr><td style='padding:5px 0;color:#5a6c5a;font-size:0.9rem;border-top:1px solid #f5e090'>+ Endreinigung <span style='color:#8a9a8a;font-size:0.78rem'>(verpflichtend, das Vereinshaus wird besenrein übergeben)</span></td><td style='padding:5px 0;font-weight:600;color:#2d3e2d;border-top:1px solid #f5e090;text-align:right'>" . number_format($endreinigung, 2, ',', '.') . " €</td></tr>"
                : "<tr><td style='padding:5px 0;color:#8a9a8a;font-size:0.85rem;border-top:1px solid #f5e090'>+ Endreinigung <span style='color:#2e7d32;font-weight:700'>(Mitglied — frei!)</span></td><td style='padding:5px 0;color:#2e7d32;font-weight:700;font-size:0.85rem;border-top:1px solid #f5e090;text-align:right'>0,00 €</td></tr>")
            . "<tr><td style='padding:5px 0;color:#8a9a8a;font-size:0.85rem;border-top:1px solid #f5e090'>+ Kaution (wird nach der Veranstaltung zurückerstattet)</td><td style='padding:5px 0;color:#8a9a8a;font-size:0.85rem;border-top:1px solid #f5e090;text-align:right'>" . number_format($kautionBtg, 2, ',', '.') . " €</td></tr>"
            . "<tr style='background:rgba(249,168,37,0.08)'>"
            .   "<td style='padding:10px 8px 10px 0;color:#3d6b41;font-weight:700;font-size:1.05rem'>Zu überweisen gesamt</td>"
            .   "<td style='padding:10px 0 10px 8px;font-weight:700;color:#3d6b41;text-align:right;font-size:1.2rem;border-top:2px solid #f9a825'>" . number_format($grandTotal, 2, ',', '.') . " €</td>"
            . "</tr>"
            . "</table>"
            . "<p style='margin:10px 0 0;font-size:0.8rem;color:#8a9a8a'>⚠ Bitte bis spätestens {$cfgZahlungsziel} Wochen vor der Veranstaltung überweisen.</p>"
            . "</div>"

            // QR Code
            . $epcQrHtml

            // Überweisungsdaten
            . "<div style='background:#f5f7f2;border-radius:8px;padding:16px 20px;margin-bottom:8px;border-left:4px solid #3d6b41'>"
            . "<p style='margin:0 0 12px;font-size:0.72rem;font-weight:700;color:#5a8c5e;text-transform:uppercase;letter-spacing:.08em'>Überweisungsdaten</p>"
            . "<table style='width:100%;border-collapse:collapse'>"
            . "<tr><td style='padding:6px 0;color:#5a6c5a;font-size:0.85rem;width:110px;vertical-align:top'>Empfänger</td>"
            .     "<td style='padding:6px 0;color:#2d3e2d;font-weight:600'>" . htmlspecialchars($cfgKontoInhaber) . "</td></tr>"
            . "<tr><td style='padding:6px 0;color:#5a6c5a;font-size:0.85rem;border-top:1px solid #e8f0e0;vertical-align:top'>IBAN</td>"
            .     "<td style='padding:6px 0;color:#2d3e2d;font-weight:600;font-family:monospace;letter-spacing:.05em;border-top:1px solid #e8f0e0'>" . htmlspecialchars($epcIbanFormatted) . "</td></tr>"
            . ($cfgBank !== '' ? "<tr><td style='padding:6px 0;color:#5a6c5a;font-size:0.85rem;border-top:1px solid #e8f0e0'>Bank</td><td style='padding:6px 0;color:#2d3e2d;border-top:1px solid #e8f0e0'>" . htmlspecialchars($cfgBank) . "</td></tr>" : '')
            . "<tr><td style='padding:6px 0;color:#5a6c5a;font-size:0.85rem;border-top:1px solid #e8f0e0;vertical-align:top'>Betrag</td>"
            .     "<td style='padding:6px 0;color:#3d6b41;font-weight:700;font-size:1.05rem;border-top:1px solid #e8f0e0'>" . number_format($grandTotal, 2, ',', '.') . " €</td></tr>"
            . "<tr><td style='padding:6px 0;color:#5a6c5a;font-size:0.85rem;border-top:1px solid #e8f0e0;vertical-align:top'>Verwendungszweck</td>"
            .     "<td style='padding:6px 0;color:#2d3e2d;font-weight:600;border-top:1px solid #e8f0e0'>" . htmlspecialchars($epcReference) . "</td></tr>"
            . "</table>"
            . "</div>";
        // Buchungs-Kontakt als Signatur (statt eingeloggtem Vorstand)
        $html = kgv_email_html(
            'Hallo ' . htmlspecialchars($name) . ' 👋,',
            $_confirmContent,
            'Buchungsbestätigung – Vereinshaus',
            $cfgKontaktName, $cfgTelefon, $cfgEmail, $cfgKontaktRolle
        );

        // Vorabinfo-PDF generieren und anhängen (immer — Preis im PDF berücksichtigt Mitgliedstarif)
        $_vorabAttachments = [];
        try {
            $_vContent  = file_exists(CONTENT_FILE) ? (json_decode((string)file_get_contents(CONTENT_FILE), true) ?: []) : [];
            $_vPrices   = $_vContent['prices']   ?? [];
            $_vSettings = $_vContent['settings'] ?? [];
            $_vPdf = generate_vorabinfo_pdf($b, $_vPrices, $_vSettings);
            $_vorabAttachments[] = [
                'filename' => 'Vorabinfo_Vereinshausmietung.pdf',
                'mime'     => 'application/pdf',
                'data'     => $_vPdf,
            ];
        } catch (\Throwable $_ve) {
            error_log("[intern] vorabinfo PDF error: " . $_ve->getMessage() . "\n", 3, LOG_FILE);
        }
        send_mail_simple($email, $subject, $text, $html, $fromName, $fromEmail, $fromEmail, $qrInlineImg, $_vorabAttachments);
        error_log("[admin] confirmed: {$bookingId} email={$email}\n", 3, LOG_FILE);
        kgv_log_action('Buchung bestätigt', $name . ' · ' . $dateFormatted);

        // ── Vortag (Übergabe/Vorbereitung) + Folgetag (Aufräumen) automatisch sperren ──
        if (!empty($dates)) {
            sort($dates);
            $_bufferBefore = (new DateTimeImmutable($dates[0]))->modify('-1 day')->format('Y-m-d');
            $_bufferAfter  = (new DateTimeImmutable(end($dates)))->modify('+1 day')->format('Y-m-d');
            $_cData       = file_exists(CONTENT_FILE) ? (json_decode((string)file_get_contents(CONTENT_FILE), true) ?: []) : [];
            $_allBookings = file_exists(BOOKINGS_FILE) ? (json_decode((string)file_get_contents(BOOKINGS_FILE), true) ?: []) : [];
            $_changed = false;
            foreach ([
                [$_bufferBefore, 'Vorbereitung/Übergabe: '],
                [$_bufferAfter,  'Aufräumen/Übergabe: '],
            ] as $_bufSpec) {
                [$_bDate, $_reasonPrefix] = $_bufSpec;
                $_alreadyBlocked = false;
                foreach ($_cData['blocked_dates'] ?? [] as $_bd) {
                    if (($_bd['date'] ?? '') === $_bDate) { $_alreadyBlocked = true; break; }
                }
                if (!$_alreadyBlocked) {
                    foreach ($_allBookings as $_ob) {
                        if (($_ob['id'] ?? '') === $bookingId) continue;
                        if (!in_array(($_ob['status'] ?? ''), ['confirmed', 'pending'], true)) continue;
                        foreach ($_ob['dates'] ?? [] as $_od) {
                            if ($_od === $_bDate) { $_alreadyBlocked = true; break 2; }
                        }
                    }
                }
                if (!$_alreadyBlocked) {
                    $_cData['blocked_dates'][] = [
                        'date'   => $_bDate,
                        'reason' => $_reasonPrefix . $name . ' (' . $dateShort . ') [' . $bookingId . ']',
                    ];
                    $_changed = true;
                    error_log("[intern] Buffer-Tag gesperrt: {$_bDate} ({$_reasonPrefix}) für Buchung {$bookingId}\n", 3, LOG_FILE);
                }
            }
            if ($_changed) {
                file_put_contents(CONTENT_FILE, json_encode($_cData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
        }

    } else {
        // ---------------------------------------------------------------
        // Absage-E-Mail an Buchenden
        // ---------------------------------------------------------------
        $tplVarsReject = [
            'name'          => $name,
            'datum'         => $dateFormatted,
            'kontakt_name'  => $cfgKontaktName,
            'telefon'       => $cfgTelefon,
            'email_kontakt' => $cfgEmail,
        ];
        $subject = applyTemplate($cfgEmailTpl['reject_subject'], $tplVarsReject);
        $text    = applyTemplate($cfgEmailTpl['reject_body'],    $tplVarsReject);

        // HTML-Body aus dem editierbaren reject_body-Template (statt hartkodiert)
        $_rejTextNoGreeting = preg_replace('/^\s*Liebe\/r\s+' . preg_quote($name, '/') . '\s*,?\s*\r?\n+/u', '', $text) ?? $text;
        $_rejectContent = "<div style='color:#5a6c5a;line-height:1.7;margin-bottom:18px;white-space:pre-wrap'>"
                        . nl2br(htmlspecialchars($_rejTextNoGreeting), false)
                        . "</div>";
        // Buchungs-Kontakt als Signatur (statt eingeloggtem Vorstand)
        $html = kgv_email_html(
            'Hallo ' . htmlspecialchars($name) . ',',
            $_rejectContent,
            'Zu deiner Anfrage – Vereinshaus',
            $cfgKontaktName, $cfgTelefon, $cfgEmail, $cfgKontaktRolle
        );

        send_mail_simple($email, $subject, $text, $html, $fromName, $fromEmail, $fromEmail);
        error_log("[admin] rejected: {$bookingId} email={$email}\n", 3, LOG_FILE);
        kgv_log_action('Buchung abgelehnt', $name . ' · ' . $dateFormatted);
        removeBookingBufferDay($dates, $bookingId, $name);
    }
    break;
}
unset($b);

if ($found) {
    file_put_contents(BOOKINGS_FILE, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

header('Location: /intern/?tab=bookings');
exit;
