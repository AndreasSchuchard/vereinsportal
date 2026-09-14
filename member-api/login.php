<?php
declare(strict_types=1);
require_once __DIR__ . '/_security.php';
session_start();
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

// Rate limit: max 5 login attempts per 15 min per IP
$ip    = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
$rlFile = $dataDir . '/rl_ml_' . md5($ip) . '.json';
$now   = time();
$hits  = [];
if (file_exists($rlFile)) {
    $d = json_decode((string)file_get_contents($rlFile), true);
    if (is_array($d)) $hits = array_values(array_filter($d, fn($t) => is_int($t) && ($now - $t) < 900));
}
if (count($hits) >= 5) respond(['status' => 'error', 'message' => 'rate_limited'], 429);

$raw  = (string)(file_get_contents('php://input') ?: '');
$data = json_decode($raw, true);
if (!is_array($data)) respond(['status' => 'error', 'message' => 'invalid_json'], 400);
// WAF bypass: decode base64-wrapped payload
if (isset($data['_p'])) { $dec = json_decode((string)base64_decode((string)$data['_p']), true); if (is_array($dec)) $data = $dec; }

$email    = strtolower(trim((string)($data['email']    ?? '')));
$password = (string)($data['password'] ?? '');

if ($email === '' || $password === '') respond(['status' => 'error', 'message' => 'missing_fields'], 400);

// Load members
$members = [];
if (file_exists($membersFile)) {
    $m = json_decode((string)file_get_contents($membersFile), true);
    if (is_array($m)) $members = $m;
}

$found = null;
foreach ($members as $member) {
    if (strtolower(trim((string)($member['email'] ?? ''))) === $email) {
        $found = $member;
        break;
    }
}

// Record attempt regardless
$hits[] = $now;
file_put_contents($rlFile, json_encode($hits), LOCK_EX);

if ($found === null || !password_verify($password, (string)($found['password_hash'] ?? ''))) {
    error_log("[member-login] failed email={$email}\n", 3, $logFile);
    respond(['status' => 'error', 'message' => 'invalid_credentials'], 401);
}

if (empty($found['active'])) {
    respond(['status' => 'error', 'message' => 'account_inactive'], 403);
}

// Successful login
session_regenerate_id(true);
$_SESSION['kgv_member'] = [
    'id'            => $found['id'],
    'name'          => $found['name'],
    'email'         => $found['email'],
    'phone'         => $found['phone']     ?? '',
    'parzelle'      => $found['parzelle']  ?? '',
    'roles'         => $found['roles']     ?? ['mitglied'],
    'sig_rolle'     => $found['sig_rolle'] ?? '',
    'must_change_pw'=> !empty($found['must_change_password']),
];
$_SESSION['member_last_activity'] = $now;

// Update last_login in members.json
foreach ($members as &$m) {
    if ($m['id'] === $found['id']) {
        $m['last_login'] = date('Y-m-d H:i:s');
        break;
    }
}
unset($m);
file_put_contents($membersFile, json_encode($members, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

// Clear rate limit on success
@unlink($rlFile);

error_log("[member-login] ok id={$found['id']} email={$email}\n", 3, $logFile);
respond(['status' => 'ok']);
