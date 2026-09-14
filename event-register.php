<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors',     '1');
require_once __DIR__ . '/inc/events.php';
require_once __DIR__ . '/inc/email_template.php';

function _redirect(string $slug, string $err = ''): void {
    $loc = '/event/' . rawurlencode($slug);
    if ($err !== '') $loc .= '?error=' . rawurlencode($err);
    header('Location: ' . $loc);
    exit;
}
function _send_mail(string $to, string $subject, string $text, string $html, string $fromName, string $fromEmail, string $replyTo = '', array $bccList = []): bool {
    foreach (array_merge([$to, $subject, $fromEmail, $replyTo], $bccList) as $h) {
        if (preg_match('/[\r\n]/', $h)) return false;
    }
    $rt   = $replyTo !== '' ? $replyTo : $fromEmail;
    $bAlt = 'a_' . md5(uniqid('', true));
    $altBlock  = "--{$bAlt}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$text}\r\n\r\n";
    $altBlock .= "--{$bAlt}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n--{$bAlt}--";
    $h  = "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$bAlt}\"\r\n";
    $h .= "From: {$fromName} <{$fromEmail}>\r\nReply-To: {$rt}\r\nReturn-Path: {$fromEmail}\r\n";
    if (!empty($bccList)) {
        $h .= "Bcc: " . implode(', ', $bccList) . "\r\n";
    }
    return mail($to, $subject, $altBlock, $h, "-f{$fromEmail}");
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }

// Origin/Referer-Check (analog Member-API)
$allowedOrigins = ['https://kgv461.de', 'https://www.kgv461.de'];
$origin  = (string)($_SERVER['HTTP_ORIGIN']  ?? '');
$referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) { http_response_code(403); exit('Forbidden'); }
if ($origin === '' && $referer !== '') {
    $ok = false;
    foreach ($allowedOrigins as $a) if (str_starts_with($referer, $a . '/')) { $ok = true; break; }
    if (!$ok) { http_response_code(403); exit('Forbidden'); }
}

$slug = trim((string)($_POST['slug'] ?? ''));
if ($slug === '') { http_response_code(400); exit('Missing slug'); }
$event = kgv_event_by_slug($slug);
if (!$event) { http_response_code(404); exit('Event nicht gefunden'); }
if (!kgv_event_is_active($event)) {
    _redirect($slug, kgv_event_deadline_passed($event) ? 'closed' : 'inactive');
}

// Honeypot
if (trim((string)($_POST['__website'] ?? '')) !== '') {
    // Bot — silently OK aussehen lassen, aber nichts speichern
    _redirect($slug, 'spam');
}

// Rate-Limit pro IP
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if ($ip !== '' && !kgv_event_rate_check($event['id'] ?? $slug, $ip)) {
    _redirect($slug, 'rate');
}

// Felder
$fullname = trim(strip_tags((string)($_POST['fullname'] ?? '')));
$email    = strtolower(trim((string)($_POST['email'] ?? '')));
$phone    = trim(strip_tags((string)($_POST['phone'] ?? '')));
$guests   = max(1, min(50, (int)($_POST['guests'] ?? 1)));
$catering = trim(strip_tags((string)($_POST['catering'] ?? '')));
$consent  = !empty($_POST['consent']);

if (!$consent)                                          _redirect($slug, 'consent');
if ($fullname === '' || $phone === '' || $email === '') _redirect($slug, 'missing');
if (!filter_var($email, FILTER_VALIDATE_EMAIL))         _redirect($slug, 'invalid_email');

// Quelle ermitteln (eingeloggte Mitglieder vs. public)
require_once __DIR__ . '/inc/session.php';
kgv_start_existing_session();
$source = !empty($_SESSION['kgv_member']) ? 'member' : 'public';

// Anmeldung anlegen
$cancelToken = bin2hex(random_bytes(16));
$registration = [
    'id'           => 'reg_' . time() . '_' . bin2hex(random_bytes(4)),
    'submitted_at' => date('Y-m-d H:i:s'),
    'fullname'     => mb_substr($fullname, 0, 120),
    'email'        => mb_substr($email, 0, 120),
    'phone'        => mb_substr($phone, 0, 40),
    'guests'       => $guests,
    'catering'     => !empty($event['show_catering']) ? mb_substr($catering, 0, 120) : '',
    'cancel_token' => $cancelToken,
    'cancelled_at' => null,
    'source'       => $source,
    'ip_hash'      => substr(hash('sha256', $ip . '|' . ($event['id'] ?? '')), 0, 16),
];

// In data/events.json speichern
$all = kgv_events_all();
$idx = kgv_event_index_by_id($all, $event['id'] ?? '');
if ($idx < 0) { http_response_code(500); exit('Storage error'); }
$all[$idx]['registrations'] = $all[$idx]['registrations'] ?? [];
$all[$idx]['registrations'][] = $registration;
if (!kgv_events_save($all)) { http_response_code(500); exit('Storage write error'); }

// ── Bestätigungs-Mail an Anmelder ─────────────────────────────────────────
$fromEmail = 'kontakt@example.org';
$fromName  = 'KGV Musterstadt e.V.';
$cancelUrl = 'https://kgv461.de/event-cancel.php?id=' . urlencode($registration['id']) . '&t=' . urlencode($cancelToken);

$catRow = (!empty($event['show_catering']) && $catering !== '')
    ? "<tr><td style='padding:10px;border-bottom:1px solid #e0e0e0;font-weight:bold;width:40%'>Buffet-Beitrag:</td>"
    .   "<td style='padding:10px;border-bottom:1px solid #e0e0e0;font-style:italic'>" . htmlspecialchars($catering) . "</td></tr>"
    : '';

$_evTitle    = (string)($event['title']    ?? '');
$_evSubtitle = (string)($event['subtitle'] ?? '');

$custContent =
      "<p>vielen Dank! Deine Anmeldung für unsere Veranstaltung <strong>"
    . htmlspecialchars($_evTitle) . "</strong> ist erfolgreich bei uns eingegangen. 🎉</p>"
    . "<p><em>Hinweis: Dies ist eine Eingangsbestätigung. Eine verbindliche Zusage erfolgt noch durch den Festausschuss — wir melden uns kurzfristig bei dir.</em></p>"
    . "<p>Deine Angaben im Überblick:</p>"
    . "<table style='width:100%;border-collapse:collapse;margin:18px 0;background:#f9fbf7;border-radius:8px;overflow:hidden'>"
    .   "<tr><td style='padding:10px;border-bottom:1px solid #e0e0e0;font-weight:bold;width:40%'>Veranstaltung:</td>"
    .   "<td style='padding:10px;border-bottom:1px solid #e0e0e0'>" . htmlspecialchars($_evTitle) . ($_evSubtitle !== '' ? '<br><span style="color:#5a6c5a;font-size:0.9em">' . htmlspecialchars($_evSubtitle) . '</span>' : '') . "</td></tr>"
    .   "<tr><td style='padding:10px;border-bottom:1px solid #e0e0e0;font-weight:bold'>Name:</td><td style='padding:10px;border-bottom:1px solid #e0e0e0'>" . htmlspecialchars($fullname) . "</td></tr>"
    .   "<tr><td style='padding:10px;border-bottom:1px solid #e0e0e0;font-weight:bold'>E-Mail:</td><td style='padding:10px;border-bottom:1px solid #e0e0e0'>" . htmlspecialchars($email) . "</td></tr>"
    .   "<tr><td style='padding:10px;border-bottom:1px solid #e0e0e0;font-weight:bold'>Telefon:</td><td style='padding:10px;border-bottom:1px solid #e0e0e0'>" . htmlspecialchars($phone) . "</td></tr>"
    .   "<tr><td style='padding:10px;border-bottom:1px solid #e0e0e0;font-weight:bold'>Personen:</td><td style='padding:10px;border-bottom:1px solid #e0e0e0;font-weight:bold;color:#3d6b41'>{$guests}</td></tr>"
    .   $catRow
    . "</table>"
    . "<p style='font-size:0.88rem;color:#5a6c5a'>Falls du deine Anmeldung doch zurückziehen musst, kannst du das hier tun:<br>"
    .   "<a href='" . htmlspecialchars($cancelUrl) . "' style='color:#c62828'>→ Anmeldung stornieren</a></p>"
    . "<p style='margin-bottom:0'>Wir freuen uns auf eine tolle Veranstaltung!</p>";

$custText =
      "Hallo {$fullname},\n\n"
    . "vielen Dank! Deine Anmeldung für '{$_evTitle}' ist eingegangen.\n"
    . "Hinweis: Dies ist eine Eingangsbestätigung. Eine verbindliche Zusage erfolgt noch durch den Festausschuss.\n\n"
    . "Deine Angaben:\n"
    . "- Veranstaltung: {$_evTitle}" . ($_evSubtitle !== '' ? " ({$_evSubtitle})" : '') . "\n"
    . "- Name: {$fullname}\n- E-Mail: {$email}\n- Telefon: {$phone}\n- Personen: {$guests}\n"
    . ((!empty($event['show_catering']) && $catering !== '') ? "- Buffet-Beitrag: {$catering}\n" : '')
    . "\nStornierung: {$cancelUrl}\n\n"
    . "Freundliche Grüße\nDer Festausschuss\nKGV Musterstadt e.V.";

// HTML-Mail mit kgv_email_html (vom Festausschuss)
$sigName  = 'Andreas Schuchard';
$sigEmail = 'events@example.org';
$sigPhone = '01577 77 57 988';
$sigRole  = 'Festausschuss';

$html = kgv_email_html(
    'Hallo ' . htmlspecialchars($fullname) . ' 👋,',
    $custContent,
    'Anmeldebestätigung · ' . $_evTitle,
    $sigName, $sigPhone, $sigEmail, $sigRole
);
@_send_mail($email, 'Anmeldung eingegangen · ' . $_evTitle, $custText, $html, $fromName, $fromEmail, $sigEmail);

// ── Notification an Festausschuss (To) + Vorstand (BCC) ──────────────────
$ccF = (json_decode((string)@file_get_contents(__DIR__ . '/data/content.json'), true) ?: []);
$bccList = [];
foreach (($ccF['vorstand'] ?? []) as $v) {
    $em = trim((string)($v['email'] ?? ''));
    if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL) && strcasecmp($em, 'events@example.org') !== 0) {
        $bccList[] = $em;
    }
}
$bccList   = array_values(array_unique($bccList));
$notifyMain = 'events@example.org';

$adminSubj = '[KGV Musterstadt · Anmeldung] ' . $_evTitle . ' — ' . $fullname . ' (' . $guests . ' Pers.)';
$adminTxt  = "Neue Anmeldung für '{$_evTitle}'\n\n"
           . "Name: {$fullname}\nE-Mail: {$email}\nTelefon: {$phone}\nPersonen: {$guests}\n"
           . ((!empty($event['show_catering']) && $catering !== '') ? "Buffet-Beitrag: {$catering}\n" : '')
           . "\nQuelle: {$source}\nAnmelde-Zeit: " . $registration['submitted_at'] . "\n\n"
           . "Backoffice-Link: https://kgv461.de/intern/?tab=events&id=" . urlencode($event['id'] ?? '');
$adminHtml = "<p style='color:#5a6c5a;line-height:1.7'>Neue Anmeldung für <strong>" . htmlspecialchars($_evTitle) . "</strong>:</p>"
           . "<table style='border-collapse:collapse;font-size:0.92rem'>"
           .   "<tr><td style='padding:5px 14px 5px 0;color:#5a6c5a'>Name</td><td><strong>" . htmlspecialchars($fullname) . "</strong></td></tr>"
           .   "<tr><td style='padding:5px 14px 5px 0;color:#5a6c5a'>E-Mail</td><td><a href='mailto:" . htmlspecialchars($email) . "'>" . htmlspecialchars($email) . "</a></td></tr>"
           .   "<tr><td style='padding:5px 14px 5px 0;color:#5a6c5a'>Telefon</td><td><a href='tel:" . htmlspecialchars($phone) . "'>" . htmlspecialchars($phone) . "</a></td></tr>"
           .   "<tr><td style='padding:5px 14px 5px 0;color:#5a6c5a'>Personen</td><td><strong style='color:#3d6b41'>{$guests}</strong></td></tr>"
           . ((!empty($event['show_catering']) && $catering !== '') ? "<tr><td style='padding:5px 14px 5px 0;color:#5a6c5a'>Buffet</td><td>" . htmlspecialchars($catering) . "</td></tr>" : '')
           .   "<tr><td style='padding:5px 14px 5px 0;color:#5a6c5a'>Quelle</td><td>" . htmlspecialchars($source) . "</td></tr>"
           . "</table>"
           . "<p style='margin-top:18px'><a href='https://kgv461.de/intern/?tab=events&id=" . urlencode($event['id'] ?? '') . "' style='display:inline-block;background:#3d6b41;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;font-weight:600'>→ Im Backoffice ansehen</a></p>";

$adminHtmlWrap = kgv_email_html('Hallo Vorstand 👋,', $adminHtml, 'Neue Veranstaltungs-Anmeldung',
    $sigName, $sigPhone, $sigEmail, $sigRole);

$notifyOk = _send_mail($notifyMain, $adminSubj, $adminTxt, $adminHtmlWrap, $fromName, $fromEmail, $email, $bccList);
if (!$notifyOk) {
    @error_log("[event] notify-mail FAILED: ev={$event['id']} to={$notifyMain} bcc=" . implode(',', $bccList) . "\n", 3, __DIR__ . '/data/admin.log');
}

// Log
@error_log("[event] register: {$event['id']} '{$fullname}' <{$email}> guests={$guests} source={$source}\n", 3, __DIR__ . '/data/admin.log');

// Erfolg
header('Location: /event/' . rawurlencode($slug) . '?ok=1&rid=' . urlencode($registration['id']));
exit;
