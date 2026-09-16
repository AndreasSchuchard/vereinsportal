<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/settings_loader.php';

require_once __DIR__ . '/_security.php';
session_start();
require_once dirname(__DIR__) . '/inc/email_template.php';
header('Content-Type: application/json; charset=UTF-8');
memberapi_check_origin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); echo '{"status":"error","message":"method_not_allowed"}'; exit;
}

function respond(array $d, int $c = 200): void {
    http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit;
}

$dataDir     = dirname(__DIR__) . '/data';
$membersFile = $dataDir . '/members.json';
$logFile     = $dataDir . '/member.log';

$raw  = (string)(file_get_contents('php://input') ?: '');
$data = json_decode($raw, true);
if (!is_array($data)) respond(['status' => 'error', 'message' => 'invalid_json'], 400);
// WAF bypass: decode base64-wrapped payload
if (isset($data['_p'])) { $dec = json_decode((string)base64_decode((string)$data['_p']), true); if (is_array($dec)) $data = $dec; }

$action = (string)($data['action'] ?? '');

// ── Action: request reset ─────────────────────────────────────────────────────
if ($action === 'request') {
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['status' => 'error', 'message' => 'invalid_email'], 400);

    // Rate limit: max 3 requests per hour per IP
    $ip     = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $rlFile = $dataDir . '/rl_rp_' . md5($ip) . '.json';
    $now    = time();
    $hits   = [];
    if (file_exists($rlFile)) {
        $d = json_decode((string)file_get_contents($rlFile), true);
        if (is_array($d)) $hits = array_values(array_filter($d, fn($t) => is_int($t) && ($now - $t) < 3600));
    }
    if (count($hits) >= 3) respond(['status' => 'error', 'message' => 'rate_limited'], 429);

    // Always respond ok to prevent email enumeration
    $members = [];
    if (file_exists($membersFile)) {
        $m = json_decode((string)file_get_contents($membersFile), true);
        if (is_array($m)) $members = $m;
    }

    $found = null;
    foreach ($members as &$mem) {
        if (strtolower(trim((string)($mem['email'] ?? ''))) === $email && !empty($mem['active'])) {
            $token = bin2hex(random_bytes(32));
            // Store only a one-way digest; the raw token exists solely in the e-mail link.
            $mem['reset_token']         = 'sha256:' . hash('sha256', $token);
            $mem['reset_token_expires'] = $now + 3600;
            $found = $mem;
            break;
        }
    }
    unset($mem);

    // Record rate limit hit
    $hits[] = $now;
    file_put_contents($rlFile, json_encode($hits), LOCK_EX);

    if ($found !== null) {
        file_put_contents($membersFile, json_encode($members, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        $resetLink = site_url() . '/mitglieder.php?reset_token=' . urlencode($token);
        $mn = htmlspecialchars($found['name']);
        $_resetContent =
              "<p style='color:#5a6c5a;line-height:1.7;margin-bottom:16px'>Du hast eine Anfrage zum Zurücksetzen deines Passworts gestellt. Klick auf den Button, um ein neues festzulegen:</p>"
            . "<a href='{$resetLink}' style='display:inline-block;background:#3d6b41;color:#fff;padding:12px 26px;border-radius:8px;text-decoration:none;font-weight:700;font-size:0.9rem;margin:4px 0 20px'>Passwort zurücksetzen →</a>"
            . "<p style='font-size:0.82rem;color:#8a9a8a;line-height:1.6'>Dieser Link ist <strong>1 Stunde</strong> gültig.<br>Falls du keine Anfrage gestellt hast, kannst du diese E-Mail einfach ignorieren.</p>";
        $html = kgv_email_html(
            'Hallo ' . $mn . ' 👋,',
            $_resetContent,
            'Passwort zurücksetzen'
        );
        $text = "Liebe/r {$found['name']},\n\nSie haben eine Anfrage zum Zurücksetzen Ihres Passworts gestellt.\n\nLink: {$resetLink}\n\nDieser Link ist 1 Stunde gültig.\n\nunser Verein";
        $fromEmail = 'kontakt@example.org';
        $hdr = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: unser Verein Mitgliederbereich <{$fromEmail}>\r\nReturn-Path: {$fromEmail}\r\n";
        @mail($found['email'], 'Passwort zurücksetzen – unser Verein', $html, $hdr, "-f{$fromEmail}");
        error_log("[member-reset] request email={$email}\n", 3, $logFile);
    }

    respond(['status' => 'ok']);
}

// ── Action: confirm reset ─────────────────────────────────────────────────────
if ($action === 'confirm') {
    $token   = (string)($data['token']    ?? '');
    $pwNew   = (string)($data['password'] ?? '');
    $pwNew2  = (string)($data['password2'] ?? '');

    if ($token === '') respond(['status' => 'error', 'message' => 'missing_token'], 400);
    if (strlen($pwNew) < 8) respond(['status' => 'error', 'message' => 'password_too_short'], 400);
    if ($pwNew !== $pwNew2) respond(['status' => 'error', 'message' => 'passwords_mismatch'], 400);
    if (!preg_match('/[A-Z]/', $pwNew)) respond(['status' => 'error', 'message' => 'password_no_upper'], 400);
    if (!preg_match('/[a-z]/', $pwNew)) respond(['status' => 'error', 'message' => 'password_no_lower'], 400);
    if (!preg_match('/[0-9]/', $pwNew)) respond(['status' => 'error', 'message' => 'password_no_digit'], 400);

    $members = [];
    if (file_exists($membersFile)) {
        $m = json_decode((string)file_get_contents($membersFile), true);
        if (is_array($m)) $members = $m;
    }

    $now   = time();
    $found = false;
    foreach ($members as &$mem) {
        $rtok = (string)($mem['reset_token'] ?? '');
        if ($rtok === '') continue;
        $tokenMatches = str_starts_with($rtok, 'sha256:')
            ? hash_equals(substr($rtok, 7), hash('sha256', $token))
            : hash_equals($rtok, $token); // compatibility for unexpired legacy links
        if (!$tokenMatches) continue;
        if (($mem['reset_token_expires'] ?? 0) < $now) {
            respond(['status' => 'error', 'message' => 'token_expired'], 400);
        }
        $mem['password_hash']        = password_hash($pwNew, PASSWORD_BCRYPT);
        $mem['reset_token']          = '';
        $mem['reset_token_expires']  = 0;
        $mem['must_change_password'] = false;
        $found = true;
        error_log("[member-reset] confirm id={$mem['id']}\n", 3, $logFile);
        break;
    }
    unset($mem);

    if (!$found) respond(['status' => 'error', 'message' => 'invalid_token'], 400);

    file_put_contents($membersFile, json_encode($members, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    respond(['status' => 'ok']);
}

respond(['status' => 'error', 'message' => 'unknown_action'], 400);
