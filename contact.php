<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

$fromEmail  = 'kontakt@example.org';
$fromName   = 'unser Verein';
$logFile    = __DIR__ . '/data/contact.log';
$dataDir    = __DIR__ . '/data';

$_ccf = __DIR__ . '/data/content.json';
$_cc  = file_exists($_ccf) ? (json_decode((string)file_get_contents($_ccf), true) ?: []) : [];
$_en  = $_cc['settings']['email_notifications'] ?? [];
$emailNotifEnabled = (bool)($_en['enabled'] ?? true);
$adminEmail  = filter_var(trim((string)($_en['contact_to'] ?? '')), FILTER_VALIDATE_EMAIL) ? trim((string)$_en['contact_to']) : 'vorstand@example.org';
$adminEmailCC= filter_var(trim((string)($_en['contact_cc'] ?? '')), FILTER_VALIDATE_EMAIL) ? trim((string)$_en['contact_cc']) : '';

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
    $file = $dir . '/rl_c_' . md5($ip) . '.json';
    $now  = time();
    $hits = [];
    if (file_exists($file)) {
        $d = json_decode((string)file_get_contents($file), true);
        if (is_array($d)) $hits = array_values(array_filter($d, fn($t) => is_int($t) && ($now - $t) < 3600));
    }
    if (count($hits) >= 10) return false;
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

// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(['status' => 'error', 'message' => 'method_not_allowed'], 405);

$ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
if (!is_dir($dataDir)) mkdir($dataDir, 0700, true);
if (!rate_limit_ok($ip, $dataDir)) respond(['status' => 'error', 'message' => 'rate_limited'], 429);

$raw  = (string)(file_get_contents('php://input') ?: '');
$data = json_decode($raw, true);
if (!is_array($data)) respond(['status' => 'error', 'message' => 'invalid_json'], 400);

$name    = sanitize((string)($data['name']    ?? ''));
$email   = sanitize((string)($data['email']   ?? ''));
$subject = sanitize((string)($data['subject'] ?? 'Kontaktanfrage'));
$message = sanitize((string)($data['message'] ?? ''));

if ($name === '' || $email === '' || $message === '') {
    respond(['status' => 'error', 'message' => 'missing_fields'], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['status' => 'error', 'message' => 'invalid_email'], 400);
}

$adminSubject = "Kontaktanfrage unser Verein: " . ($subject !== '' ? $subject : 'Allgemeine Anfrage');
$adminText    = "Neue Kontaktanfrage\n\nName: {$name}\nEmail: {$email}\nBetreff: {$subject}\n\nNachricht:\n{$message}";
$adminHtml    = "<!DOCTYPE html><html><body style='background:#f5f7f2;font-family:Arial,sans-serif;padding:20px;'>"
              . "<div style='max-width:540px;margin:0 auto;background:#fff;border-radius:12px;border:1px solid #d4e6c3;overflow:hidden;'>"
              . "<div style='background:#3d6b41;padding:20px 24px;'><p style='margin:0;color:#fff;font-weight:700;'>Neue Kontaktanfrage</p></div>"
              . "<div style='padding:24px;'>"
              . "<table style='width:100%;border-collapse:collapse;'>"
              . "<tr><td style='padding:8px 0;color:#5a6c5a;width:80px;'>Name</td><td style='padding:8px 0;font-weight:600;'>" . htmlspecialchars($name) . "</td></tr>"
              . "<tr><td style='padding:8px 0;color:#5a6c5a;border-top:1px solid #e8f0e0;'>E-Mail</td><td style='padding:8px 0;border-top:1px solid #e8f0e0;'><a href='mailto:" . htmlspecialchars($email) . "' style='color:#3d6b41;'>" . htmlspecialchars($email) . "</a></td></tr>"
              . ($subject !== '' ? "<tr><td style='padding:8px 0;color:#5a6c5a;border-top:1px solid #e8f0e0;'>Betreff</td><td style='padding:8px 0;border-top:1px solid #e8f0e0;'>" . htmlspecialchars($subject) . "</td></tr>" : '')
              . "</table>"
              . "<div style='margin-top:16px;background:#f5f7f2;border-radius:8px;padding:16px;'>"
              . "<p style='margin:0;white-space:pre-wrap;color:#2d3e2d;'>" . htmlspecialchars($message) . "</p>"
              . "</div></div></div></body></html>";

if ($emailNotifEnabled) {
    send_mail_simple($adminEmail, $adminSubject, $adminText, $adminHtml, $fromName, $fromEmail, $email);
    if ($adminEmailCC !== '') {
        send_mail_simple($adminEmailCC, $adminSubject, $adminText, $adminHtml, $fromName, $fromEmail, $email);
    }
}

// Anfrage in contacts.json archivieren
$contactsFile = $dataDir . '/contacts.json';
$contacts = [];
if (file_exists($contactsFile)) {
    $existing = json_decode((string)file_get_contents($contactsFile), true);
    if (is_array($existing)) $contacts = $existing;
}
$contacts[] = [
    'id'      => uniqid('c_', true),
    'date'    => date('Y-m-d H:i:s'),
    'name'    => $name,
    'email'   => $email,
    'subject' => $subject,
    'message' => $message,
    'status'  => 'new',
];
file_put_contents($contactsFile, json_encode($contacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

error_log("[contact] from={$email} name={$name}\n", 3, $logFile);
respond(['status' => 'ok']);
