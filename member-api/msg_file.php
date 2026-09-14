<?php
declare(strict_types=1);
require_once __DIR__ . '/_security.php';
session_start();

$isMember = !empty($_SESSION['kgv_member']);
$isAdmin  = !empty($_SESSION['kgv_admin']);

if (!$isMember && !$isAdmin) { http_response_code(401); exit; }

$file = basename((string)($_GET['f'] ?? ''));
if ($file === '' || !preg_match('/^mf_[a-zA-Z0-9._-]+$/', $file)) { http_response_code(400); exit; }

$filesDir = dirname(__DIR__) . '/data/member_msg_files';
$path = $filesDir . '/' . $file;

if (!file_exists($path)) { http_response_code(404); exit; }

// Member: nur Anhaenge aus Threads, die man selbst gesendet hat ODER in denen man Empfaenger ist
// (Empfaenger = direkt per member_id ODER als Inhaber der adressierten Rolle, z.B. Vorstand).
if ($isMember && !$isAdmin) {
    $msgsFile = dirname(__DIR__) . '/data/member_messages.json';
    $msgs = file_exists($msgsFile) ? (json_decode((string)file_get_contents($msgsFile), true) ?: []) : [];
    $memberId = (string)($_SESSION['kgv_member']['id'] ?? '');
    $myRoles  = (array)($_SESSION['kgv_member']['roles'] ?? []);
    $allowed = false;
    foreach ($msgs as $thread) {
        $isSender      = $memberId !== '' && ($thread['member_id'] ?? '') === $memberId;
        $isMemberRecip = $memberId !== '' && ($thread['recipient_member_id'] ?? '') === $memberId;
        $isRoleRecip   = in_array((string)($thread['recipient_role'] ?? ''), $myRoles, true);
        if (!$isSender && !$isMemberRecip && !$isRoleRecip) continue;
        foreach ($thread['messages'] as $msg) {
            if (($msg['file']['stored'] ?? '') === $file) { $allowed = true; break 2; }
        }
    }
    if (!$allowed) { http_response_code(403); exit; }
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
          'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
          'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
$mime = $mimes[$ext] ?? 'application/octet-stream';

$isImage = str_starts_with($mime, 'image/');
$safeDisp = preg_replace('/[\r\n"\\\\]/', '_', $file);
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . ($isImage ? 'inline' : 'attachment') . '; filename="' . $safeDisp . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($path);
exit;
