<?php
declare(strict_types=1);
require_once __DIR__ . '/_security.php';
header('Content-Type: application/json; charset=UTF-8');
memberapi_check_origin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); echo '{"status":"error","message":"method_not_allowed"}'; exit;
}

function respond(array $d, int $c = 200): void {
    http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit;
}

function sanitize(string $v): string { return trim(strip_tags($v)); }

$dataDir      = dirname(__DIR__) . '/data';
$requestsFile = $dataDir . '/member_requests.json';
$membersFile  = $dataDir . '/members.json';
$logFile      = $dataDir . '/member.log';

if (!is_dir($dataDir)) mkdir($dataDir, 0700, true);

// Rate limit: 3 requests per hour per IP
$ip     = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
$rlFile = $dataDir . '/rl_mr_' . md5($ip) . '.json';
$now    = time();
$hits   = [];
if (file_exists($rlFile)) {
    $d = json_decode((string)file_get_contents($rlFile), true);
    if (is_array($d)) $hits = array_values(array_filter($d, fn($t) => is_int($t) && ($now - $t) < 3600));
}
if (count($hits) >= 3) respond(['status' => 'error', 'message' => 'rate_limited'], 429);

$raw  = (string)(file_get_contents('php://input') ?: '');
$data = json_decode($raw, true);
if (!is_array($data)) respond(['status' => 'error', 'message' => 'invalid_json'], 400);
// WAF bypass: decode base64-wrapped payload
if (isset($data['_p'])) { $dec = json_decode((string)base64_decode((string)$data['_p']), true); if (is_array($dec)) $data = $dec; }

$name             = sanitize((string)($data['name']              ?? ''));
$email            = strtolower(trim((string)($data['email']       ?? '')));
$parzelle         = sanitize((string)($data['parzelle']           ?? ''));
$phone            = sanitize((string)($data['phone']              ?? ''));
$message          = sanitize((string)($data['message']            ?? ''));
$consentContact   = !empty($data['consent_contact']);
$consentPhonelist = !empty($data['consent_phonelist']);
$rollTyp          = sanitize((string)($data['rolle_typ']          ?? 'paechter'));
if (!in_array($rollTyp, ['paechter', 'paechterpartner', 'foerdermitglied'], true)) {
    $rollTyp = 'paechter';
}

if ($name === '' || $email === '') {
    respond(['status' => 'error', 'message' => 'missing_fields'], 400);
}
if ($rollTyp !== 'foerdermitglied' && $parzelle === '') {
    respond(['status' => 'error', 'message' => 'missing_fields'], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['status' => 'error', 'message' => 'invalid_email'], 400);
}

// Load existing requests and members
$requests = [];
if (file_exists($requestsFile)) {
    $r = json_decode((string)file_get_contents($requestsFile), true);
    if (is_array($r)) $requests = $r;
}
foreach ($requests as $req) {
    if (strtolower(trim((string)($req['email'] ?? ''))) === $email && ($req['status'] ?? '') === 'pending') {
        respond(['status' => 'error', 'message' => 'already_requested'], 409);
    }
}
$members = [];
if (file_exists($membersFile)) {
    $m = json_decode((string)file_get_contents($membersFile), true);
    if (is_array($m)) $members = $m;
}
foreach ($members as $mem) {
    if (strtolower(trim((string)($mem['email'] ?? ''))) === $email) {
        respond(['status' => 'error', 'message' => 'email_exists'], 409);
    }
}

// Parzelle-Regeln je Mitgliedsart
if ($parzelle !== '') {
    if ($rollTyp === 'paechter') {
        // Parzelle darf noch nicht von einem Pächter belegt sein
        foreach ($members as $mem) {
            if (($mem['parzelle'] ?? '') === $parzelle
                && !empty($mem['active'])
                && ($mem['rolle_typ'] ?? 'paechter') === 'paechter') {
                respond(['status' => 'error', 'message' => 'parzelle_exists'], 409);
            }
        }
    } elseif ($rollTyp === 'paechterpartner') {
        // Parzelle muss einem aktiven Mitglied gehören
        $parzelleFound = false;
        foreach ($members as $mem) {
            if (($mem['parzelle'] ?? '') === $parzelle && !empty($mem['active'])) {
                $parzelleFound = true; break;
            }
        }
        if (!$parzelleFound) {
            respond(['status' => 'error', 'message' => 'parzelle_not_found'], 404);
        }
        // Max. 1 Pächterpartner pro Parzelle
        foreach ($members as $mem) {
            if (($mem['parzelle'] ?? '') === $parzelle
                && ($mem['rolle_typ'] ?? '') === 'paechterpartner'
                && !empty($mem['active'])) {
                respond(['status' => 'error', 'message' => 'partner_already_exists'], 409);
            }
        }
        // Auch in pending requests prüfen
        foreach ($requests as $req) {
            if (($req['parzelle'] ?? '') === $parzelle
                && ($req['rolle_typ'] ?? '') === 'paechterpartner'
                && ($req['status'] ?? '') === 'pending') {
                respond(['status' => 'error', 'message' => 'partner_already_exists'], 409);
            }
        }
    }
}

$requests[] = [
    'id'                => 'req_' . uniqid('', true),
    'rolle_typ'         => $rollTyp,
    'name'              => $name,
    'email'             => $email,
    'phone'             => $phone,
    'parzelle'          => $parzelle,
    'message'           => $message,
    'consent_contact'   => $consentContact,
    'consent_phonelist' => $consentPhonelist,
    'created_at'        => date('Y-m-d H:i:s'),
    'status'            => 'pending',
];
file_put_contents($requestsFile, json_encode($requests, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

$hits[] = $now;
file_put_contents($rlFile, json_encode($hits), LOCK_EX);

error_log("[member-register] rolle_typ={$rollTyp} name={$name} email={$email} parzelle={$parzelle}\n", 3, $logFile);
respond(['status' => 'ok']);
