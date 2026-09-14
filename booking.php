<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/session.php';
kgv_start_existing_session();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/inc/email_template.php';
$fromEmail    = 'kontakt@example.org';
$fromName     = 'KGV Musterstadt e.V.';
$bookingsFile = __DIR__ . '/data/bookings.json';
$logFile      = __DIR__ . '/data/booking.log';
$dataDir      = __DIR__ . '/data';

$_ccf = __DIR__ . '/data/content.json';
$_cc  = file_exists($_ccf) ? (json_decode((string)file_get_contents($_ccf), true) ?: []) : [];
$_en  = $_cc['settings']['email_notifications'] ?? [];
$emailNotifEnabled = (bool)($_en['enabled'] ?? true);
$adminEmail = filter_var(trim((string)($_en['booking_to'] ?? '')), FILTER_VALIDATE_EMAIL) ? trim((string)$_en['booking_to']) : 'vorstand@example.org';
$ccEmail    = filter_var(trim((string)($_en['booking_cc'] ?? '')), FILTER_VALIDATE_EMAIL) ? trim((string)$_en['booking_cc']) : '';

// Mitgliedertarif: aus Session erkennen
$isMemberBooking = !empty($_SESSION['kgv_member']);
$_prices     = $_cc['prices'] ?? [];
$_mieteBase  = (float)($_prices['miete'] ?? 300);
$raummiete   = $isMemberBooking ? round($_mieteBase / 2, 2) : $_mieteBase;
unset($_prices, $_mieteBase);

function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitize(string $v): string {
    return trim(strip_tags($v));
}

function rate_limit_ok(string $ip, string $dir): bool {
    if ($ip === '') return true;
    $file = $dir . '/rl_' . md5($ip) . '.json';
    $now  = time();
    $hits = [];
    if (file_exists($file)) {
        $d = json_decode((string)file_get_contents($file), true);
        if (is_array($d)) $hits = array_values(array_filter($d, fn($t) => is_int($t) && ($now - $t) < 3600));
    }
    if (count($hits) >= 5) return false;
    $hits[] = $now;
    file_put_contents($file, json_encode($hits), LOCK_EX);
    return true;
}

function send_mail_simple(string $to, string $subject, string $text, string $html,
                           string $fromName, string $fromEmail, string $replyTo = ''): bool {
    // CRLF injection prevention
    foreach ([$to, $subject, $fromEmail, $replyTo] as $h) {
        if (preg_match('/[\r\n]/', $h)) return false;
    }
    $b  = 'b_' . md5(uniqid('', true));
    $rt = $replyTo !== '' ? $replyTo : $fromEmail;
    $h  = "MIME-Version: 1.0\r\n";
    $h .= "Content-Type: multipart/alternative; boundary=\"{$b}\"\r\n";
    $h .= "From: {$fromName} <{$fromEmail}>\r\n";
    $h .= "Reply-To: {$rt}\r\n";
    $h .= "Return-Path: {$fromEmail}\r\n";
    $body  = "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$text}\r\n\r\n";
    $body .= "--{$b}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n--{$b}--";
    return mail($to, $subject, $body, $h, "-f{$fromEmail}");
}

function applyTemplate(string $tpl, array $vars): string {
    foreach ($vars as $k => $v) {
        $tpl = str_replace('{' . $k . '}', (string)$v, $tpl);
    }
    return $tpl;
}

function formatDateDE(string $dateStr): string {
    $obj = DateTime::createFromFormat('Y-m-d', $dateStr);
    if (!$obj) return $dateStr;
    $fmt = new IntlDateFormatter('de_DE', IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'Europe/Berlin');
    return $fmt ? $fmt->format($obj) : $obj->format('d.m.Y');
}

// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(['status' => 'error', 'message' => 'method_not_allowed'], 405);

$ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
if (!is_dir($dataDir)) mkdir($dataDir, 0700, true);
if (!rate_limit_ok($ip, $dataDir)) respond(['status' => 'error', 'message' => 'rate_limited'], 429);

$raw  = (string)(file_get_contents('php://input') ?: '');
$data = json_decode($raw, true);
if (!is_array($data)) respond(['status' => 'error', 'message' => 'invalid_json'], 400);

// wafFetch (Mitgliederbereich) kodiert Payload als {_p: base64(JSON)} — dekodieren
if (isset($data['_p']) && is_string($data['_p'])) {
    $dec = json_decode(base64_decode($data['_p']), true);
    if (is_array($dec)) $data = $dec;
}

$name    = sanitize((string)($data['name']    ?? ''));
$email   = sanitize((string)($data['email']   ?? ''));
$phone   = sanitize((string)($data['phone']   ?? ''));
$purpose = sanitize((string)($data['purpose'] ?? ''));
$guests  = (int)($data['guests'] ?? 0);

// Accept 'dates' (array) or legacy 'date' (string)
$datesRaw = $data['dates'] ?? (isset($data['date']) ? [$data['date']] : []);
if (!is_array($datesRaw) || count($datesRaw) === 0) {
    respond(['status' => 'error', 'message' => 'missing_dates'], 400);
}

$dates = [];
$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
foreach ($datesRaw as $d) {
    $ds  = sanitize((string)$d);
    $dt  = DateTimeImmutable::createFromFormat('Y-m-d', $ds, new DateTimeZone('Europe/Berlin'));
    // Strict format check: createFromFormat kann erfolgreiche Umwandlung mit falschem Datum liefern
    if (!$dt || $dt->format('Y-m-d') !== $ds) {
        respond(['status' => 'error', 'message' => 'invalid_date'], 400);
    }
    if ($dt < $today) {
        respond(['status' => 'error', 'message' => 'date_in_past'], 400);
    }
    $dates[] = $ds;
}
sort($dates);

// Validate required fields
if ($name === '' || $email === '' || $phone === '') {
    respond(['status' => 'error', 'message' => 'missing_fields'], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['status' => 'error', 'message' => 'invalid_email'], 400);
}

// Load bookings
$bookings = [];
if (file_exists($bookingsFile)) {
    $d = json_decode((string)file_get_contents($bookingsFile), true);
    if (is_array($d)) $bookings = $d;
}

// Check if any requested date is already confirmed
$confirmedDates = [];
foreach ($bookings as $b) {
    if (($b['status'] ?? '') !== 'confirmed') continue;
    $bDates = $b['dates'] ?? (isset($b['date']) ? [$b['date']] : []);
    foreach ($bDates as $bd) $confirmedDates[] = $bd;
}
$conflicts = array_intersect($dates, $confirmedDates);
if (count($conflicts) > 0) {
    respond(['status' => 'error', 'message' => 'date_unavailable'], 409);
}

// Check admin-defined blocked dates / ranges
$_ccf = __DIR__ . '/data/content.json';
$_cc  = file_exists($_ccf) ? (json_decode((string)file_get_contents($_ccf), true) ?: []) : [];
$_tz  = new DateTimeZone('Europe/Berlin');
foreach ($_cc['blocked_dates'] ?? [] as $_bd) {
    if (in_array($_bd['date'] ?? '', $dates, true))
        respond(['status' => 'error', 'message' => 'date_blocked'], 409);
}
foreach ($_cc['blocked_ranges'] ?? [] as $_br) {
    $_dtF = DateTimeImmutable::createFromFormat('Y-m-d', $_br['from'] ?? '', $_tz);
    $_dtT = DateTimeImmutable::createFromFormat('Y-m-d', $_br['to']   ?? '', $_tz);
    if (!$_dtF || !$_dtT) continue;
    foreach ($dates as $_d) {
        $_dtD = DateTimeImmutable::createFromFormat('Y-m-d', $_d, $_tz);
        if ($_dtD && $_dtD >= $_dtF && $_dtD <= $_dtT)
            respond(['status' => 'error', 'message' => 'date_blocked'], 409);
    }
}
// Extract contact settings + email templates for use in emails below
$_bcfg = $_cc['settings'] ?? [];
$_etDef = [
    'inquiry_subject' => 'Ihre Buchungsanfrage – KGV Musterstadt Vereinshaus am {datum}',
    'inquiry_body'    => "Liebe/r {name},\n\nvielen Dank für Ihre Buchungsanfrage für den {datum}.\n\nWir haben Ihre Anfrage erhalten und werden uns schnellstmöglich bei Ihnen melden.\n\nMit freundlichen Grüßen\nKGV Musterstadt e.V.",
];
$_bEmailTpl     = array_merge($_etDef, $_cc['email_templates'] ?? []);
// Buchungs-Kontakt: Auto-Lookup im Vorstand (Kassier/Vermietung), Fallback auf settings.kontakt_*
$_bContact      = kgv_get_booking_contact($_cc);
$_bKontaktName  = $_bContact['name'];
$_bKontaktRolle = $_bContact['rolle'];
$_bTelefon      = $_bContact['phone'];
$_bEmail        = $_bContact['email'];
unset($_ccf, $_cc, $_tz, $_bd, $_br, $_dtF, $_dtT, $_d, $_dtD, $_bcfg, $_etDef, $_bContact);

// Create booking record
$id = 'bkg_' . time() . '_' . bin2hex(random_bytes(3));
$booking = [
    'id'         => $id,
    'status'     => 'pending',
    'dates'      => $dates,
    'name'       => $name,
    'email'      => $email,
    'phone'      => $phone,
    'purpose'    => $purpose,
    'guests'     => $guests,
    'created_at'      => date('Y-m-d H:i:s'),
    'ip'              => md5($ip),
    'extras'          => [],
    'is_member_tarif' => $isMemberBooking,
    'raummiete'       => $raummiete,
];

$bookings[] = $booking;
file_put_contents($bookingsFile, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

// Format dates for display
$dateFormatted = count($dates) === 1
    ? formatDateDE($dates[0])
    : formatDateDE($dates[0]) . ' – ' . formatDateDE(end($dates)) . ' (' . count($dates) . ' Tage)';
$dateShort = count($dates) === 1
    ? (new DateTime($dates[0]))->format('d.m.Y')
    : (new DateTime($dates[0]))->format('d.m.Y') . '–' . (new DateTime(end($dates)))->format('d.m.Y');

// ---------------------------------------------------------------------------
// E-Mail 1: Bestätigung an Anfragenden
// ---------------------------------------------------------------------------
$_bTplVars   = ['name' => $name, 'datum' => $dateFormatted, 'kontakt_name' => $_bKontaktName, 'telefon' => $_bTelefon, 'email_kontakt' => $_bEmail];
$custSubject = applyTemplate($_bEmailTpl['inquiry_subject'], $_bTplVars);
$custText    = applyTemplate($_bEmailTpl['inquiry_body'],    $_bTplVars);
unset($_bEmailTpl, $_bTplVars);

// HTML-Body kommt aus dem im Backoffice editierbaren Template (inquiry_body).
// Plain-Text wurde oben bereits via applyTemplate erzeugt ($custText).
// Wir konvertieren den gleichen Text zu HTML, sodass Sandras Edit im Test-Tab
// und in der echten Anfrage-Mail identisch erscheint.
$_custTextNoGreeting = preg_replace('/^\s*Liebe\/r\s+' . preg_quote($name, '/') . '\s*,?\s*\r?\n+/u', '', $custText) ?? $custText;
$_custContent =
      "<div style='color:#5a6c5a;line-height:1.7;margin-bottom:18px;white-space:pre-wrap'>"
    . nl2br(htmlspecialchars($_custTextNoGreeting), false)
    . "</div>"
    . "<div style='background:#f5f7f2;border-radius:8px;padding:16px 20px;margin-bottom:18px;border-left:4px solid #3d6b41'>"
    . "<p style='margin:0 0 8px;font-size:0.72rem;font-weight:700;color:#5a8c5e;text-transform:uppercase;letter-spacing:.08em'>Ihre Anfrage</p>"
    . "<p style='margin:0;color:#2d3e2d;font-weight:700;font-size:1.02rem'>" . htmlspecialchars($dateFormatted) . "</p>"
    . ($purpose !== '' ? "<p style='margin:4px 0 0;color:#5a6c5a;font-size:0.9rem'>Anlass: " . htmlspecialchars($purpose) . "</p>" : '')
    . ($guests > 0 ? "<p style='margin:4px 0 0;color:#5a6c5a;font-size:0.9rem'>Personen: {$guests}</p>" : '')
    . "</div>";
$custHtml = kgv_email_html(
    'Hallo ' . htmlspecialchars($name) . ' 👋,',
    $_custContent,
    'Anfrage erhalten – Vereinshaus',
    $_bKontaktName, $_bTelefon, $_bEmail, $_bKontaktRolle
);

send_mail_simple($email, $custSubject, $custText, $custHtml, $fromName, $fromEmail, $adminEmail);

// ---------------------------------------------------------------------------
// E-Mail 2: Benachrichtigung ans Backoffice
// ---------------------------------------------------------------------------
$adminSubject = "Neue Buchungsanfrage [{$dateShort}]: {$name}";
$adminText = "Neue Buchungsanfrage eingegangen!\n\n"
           . "Zeitraum: {$dateFormatted}\n"
           . "Name:  {$name}\n"
           . "Email: {$email}\n"
           . "Tel:   {$phone}\n"
           . ($purpose !== '' ? "Anlass: {$purpose}\n" : '')
           . ($guests > 0  ? "Personen: {$guests}\n" : '')
           . "\nBooking-ID: {$id}\n\n"
           . "Backoffice: https://kgv461.de/intern/";

$adminHtml = "<!DOCTYPE html><html><body style='background:#f5f7f2;font-family:Arial,sans-serif;padding:20px;'>"
           . "<div style='max-width:560px;margin:0 auto;background:#fff;border-radius:12px;border:1px solid #d4e6c3;overflow:hidden;'>"
           . "<div style='background:#3d6b41;padding:20px 24px;'>"
           . "<p style='margin:0;font-size:16px;font-weight:700;color:#fff;'>Neue Buchungsanfrage</p>"
           . "</div>"
           . "<div style='padding:24px;'>"
           . "<table style='width:100%;border-collapse:collapse;'>"
           . "<tr><td style='padding:8px 0;color:#5a6c5a;width:100px;'>Zeitraum</td><td style='padding:8px 0;font-weight:600;color:#2d3e2d;'>" . htmlspecialchars($dateFormatted) . "</td></tr>"
           . "<tr><td style='padding:8px 0;color:#5a6c5a;border-top:1px solid #e8f0e0;'>Name</td><td style='padding:8px 0;font-weight:600;color:#2d3e2d;border-top:1px solid #e8f0e0;'>" . htmlspecialchars($name) . "</td></tr>"
           . "<tr><td style='padding:8px 0;color:#5a6c5a;border-top:1px solid #e8f0e0;'>E-Mail</td><td style='padding:8px 0;color:#2d3e2d;border-top:1px solid #e8f0e0;'><a href='mailto:" . htmlspecialchars($email) . "' style='color:#3d6b41;'>" . htmlspecialchars($email) . "</a></td></tr>"
           . "<tr><td style='padding:8px 0;color:#5a6c5a;border-top:1px solid #e8f0e0;'>Telefon</td><td style='padding:8px 0;color:#2d3e2d;border-top:1px solid #e8f0e0;'>" . htmlspecialchars($phone) . "</td></tr>"
           . ($purpose !== '' ? "<tr><td style='padding:8px 0;color:#5a6c5a;border-top:1px solid #e8f0e0;'>Anlass</td><td style='padding:8px 0;color:#2d3e2d;border-top:1px solid #e8f0e0;'>" . htmlspecialchars($purpose) . "</td></tr>" : '')
           . ($guests > 0  ? "<tr><td style='padding:8px 0;color:#5a6c5a;border-top:1px solid #e8f0e0;'>Personen</td><td style='padding:8px 0;color:#2d3e2d;border-top:1px solid #e8f0e0;'>{$guests}</td></tr>" : '')
           . "</table>"
           . "<div style='margin-top:24px;text-align:center;'>"
           . "<a href='https://kgv461.de/intern/' style='display:inline-block;background:#3d6b41;color:#fff;padding:12px 28px;border-radius:50px;text-decoration:none;font-weight:600;'>Zum Backoffice</a>"
           . "</div>"
           . "</div></div></body></html>";

error_log("[booking] notif_enabled=" . ($emailNotifEnabled ? '1' : '0') . " admin_to={$adminEmail} cc={$ccEmail}\n", 3, $logFile);
if ($emailNotifEnabled) {
    $r1 = send_mail_simple($adminEmail, $adminSubject, $adminText, $adminHtml, $fromName, $fromEmail, $email);
    error_log("[booking] mail_to={$adminEmail} result=" . ($r1 ? 'ok' : 'fail') . "\n", 3, $logFile);
    if ($ccEmail !== '') {
        $r2 = send_mail_simple($ccEmail, $adminSubject, $adminText, $adminHtml, $fromName, $fromEmail, $email);
        error_log("[booking] mail_cc={$ccEmail} result=" . ($r2 ? 'ok' : 'fail') . "\n", 3, $logFile);
    }
}

error_log("[booking] new: {$id} dates=" . implode(',', $dates) . " name={$name} email={$email}\n", 3, $logFile);

respond(['status' => 'ok', 'id' => $id]);
