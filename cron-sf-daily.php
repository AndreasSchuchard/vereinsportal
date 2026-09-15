<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/settings_loader.php';

/**
 * Cron-Endpoint für das Daily-Briefing der Schriftführerin.
 * Wird via Strato-Cronjob täglich morgens aufgerufen.
 * Token-geschützt (sf_config()['cron_token']).
 *
 * URL: https://verein.example.org/cron-sf-daily.php?t=<TOKEN>
 */
ini_set('display_errors', '0');
ini_set('log_errors',     '1');

require_once __DIR__ . '/inc/schriftfuehrung.php';

$cfg = sf_config();
$expectedToken = (string)($cfg['cron_token'] ?? '');
$givenToken    = (string)($_GET['t'] ?? '');

if ($expectedToken === '' || !hash_equals($expectedToken, $givenToken)) {
    http_response_code(403);
    exit('Forbidden');
}

if (empty($cfg['daily_enabled'])) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Daily-Briefing ist deaktiviert (Settings im Cockpit).\n";
    exit;
}

$to = trim((string)($cfg['daily_to'] ?? ''));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Kein gültiger Empfänger konfiguriert.\n";
    exit;
}

$cc = trim((string)($cfg['daily_to_cc'] ?? ''));

// Briefing bauen
$briefing = sf_daily_briefing();
$html = kgv_email_html(
    'Hallo Sandra 👋,',
    sf_daily_briefing_render_html($briefing),
    'Daily-Briefing · ' . date('d.m.Y'),
    'Schriftführung-Cockpit', '', 'kontakt@example.org', 'Automatisches Briefing'
);

// Text-Variante
$txt = "Hallo Sandra,\n\nhier dein KGV-Briefing für " . date('d.m.Y') . ":\n\n";
if (!empty($briefing['bdays_today'])) {
    $txt .= "🎂 Geburtstag heute:\n";
    foreach ($briefing['bdays_today'] as $b) $txt .= "  - " . $b['name'] . "\n";
    $txt .= "\n";
}
if (!empty($briefing['bdays_week'])) {
    $txt .= "🎂 Geburtstage 7 Tage:\n";
    foreach ($briefing['bdays_week'] as $b) $txt .= "  - " . $b['name'] . " (in " . $b['days_until'] . " Tg)\n";
    $txt .= "\n";
}
if (!empty($briefing['annivs'])) {
    $txt .= "🏅 Jubiläen 30 Tage:\n";
    foreach ($briefing['annivs'] as $a) $txt .= "  - " . $a['name'] . " (" . $a['years'] . "J)\n";
    $txt .= "\n";
}
if (!empty($briefing['due_notes'])) {
    $txt .= "🔥 Fällige Wiedervorlagen:\n";
    foreach ($briefing['due_notes'] as $n) $txt .= "  - " . ($n['text'] ?? '') . "\n";
    $txt .= "\n";
}
if (!empty($briefing['new_apps']))     $txt .= "📥 Neue Anträge: " . count($briefing['new_apps']) . "\n";
if (!empty($briefing['new_contacts'])) $txt .= "📨 Offene Kontaktanfragen: " . count($briefing['new_contacts']) . "\n";

$subject = 'KGV Musterstadt — Briefing ' . date('d.m.Y');
$boundary = 'b_' . md5(uniqid('', true));
$body  = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$txt}\r\n\r\n";
$body .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n--{$boundary}--";
$headers   = "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
$headers  .= "From: KGV Musterstadt e.V. <kontakt@example.org>\r\nReply-To: kontakt@example.org\r\nReturn-Path: kontakt@example.org\r\n";
if ($cc !== '') $headers .= "Cc: {$cc}\r\n";

$ok = @mail($to, $subject, $body, $headers, '-fkontakt@example.org');

@error_log("[cron-sf-daily] sent={$to} cc={$cc} ok=" . ($ok ? 'yes' : 'no') . "\n", 3, __DIR__ . '/data/admin.log');

header('Content-Type: text/plain; charset=UTF-8');
echo "Daily-Briefing\n";
echo "Empfänger: {$to}" . ($cc !== '' ? " (CC: {$cc})" : '') . "\n";
echo "Status: " . ($ok ? 'OK' : 'FAIL') . "\n";
echo "Heute:\n";
echo "  Geburtstage heute:  " . count($briefing['bdays_today']) . "\n";
echo "  Geburtstage 7 Tg:   " . count($briefing['bdays_week']) . "\n";
echo "  Jubiläen 30 Tg:     " . count($briefing['annivs']) . "\n";
echo "  Fällige Notizen:    " . count($briefing['due_notes']) . "\n";
echo "  Neue Anträge:       " . count($briefing['new_apps']) . "\n";
echo "  Offene Kontakte:    " . count($briefing['new_contacts']) . "\n";
