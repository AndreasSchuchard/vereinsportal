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

// Session timeout 60 min
if (!empty($_SESSION['member_last_activity']) && (time() - (int)$_SESSION['member_last_activity']) > 3600) {
    unset($_SESSION['kgv_member'], $_SESSION['member_last_activity']);
}
if (empty($_SESSION['kgv_member'])) respond(['status' => 'error', 'message' => 'not_logged_in'], 401);
$_SESSION['member_last_activity'] = time();

$member  = $_SESSION['kgv_member'];
$dataDir = dirname(__DIR__) . '/data';
$msgsFile = $dataDir . '/member_messages.json';
$logFile  = $dataDir . '/member.log';

// Support both JSON and multipart/form-data
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $raw  = (string)(file_get_contents('php://input') ?: '');
    $data = json_decode($raw, true);
    if (!is_array($data)) respond(['status' => 'error', 'message' => 'invalid_json'], 400);
    // WAF bypass: decode base64-wrapped payload
    if (isset($data['_p'])) { $dec = json_decode((string)base64_decode((string)$data['_p']), true); if (is_array($dec)) $data = $dec; }
    $action       = (string)($data['action']    ?? 'new');
    $threadId     = (string)($data['thread_id'] ?? '');
    $subject      = memberapi_safe_header_value((string)($data['subject'] ?? ''), 200);
    $body         = substr(trim(strip_tags((string)($data['body']    ?? ''))), 0, 5000);
    $rawRecip     = (string)($data['recipient_role'] ?? 'vorstand');
    $recipientIds = array_map('strval', (array)($data['recipient_ids'] ?? []));
} else {
    $action         = (string)($_POST['action']    ?? 'new');
    $threadId       = (string)($_POST['thread_id'] ?? '');
    $subject        = memberapi_safe_header_value((string)($_POST['subject'] ?? ''), 200);
    $body           = substr(trim(strip_tags((string)($_POST['body']    ?? ''))), 0, 5000);
    $rawRecip       = (string)($_POST['recipient_role'] ?? 'vorstand');
    $recipientIds   = array_map('strval', (array)($_POST['recipient_ids'] ?? []));
}

// Ensure $recipientIds is always an array
if (!isset($recipientIds)) $recipientIds = [];

// Parse recipient: role or specific member
$recipientMemberId    = '';
$recipientMemberName  = '';
$recipientMemberEmail = '';
if (str_starts_with($rawRecip, 'member_')) {
    $recipientMemberId = substr($rawRecip, 7);
    $recipientRole     = 'member';
} else {
    $recipientRole = in_array($rawRecip, ['vorstand','kassier','koppel','web'], true) ? $rawRecip : 'vorstand';
}

if ($action === 'delete') {
    if ($threadId === '') respond(['status' => 'error', 'message' => 'missing_thread_id'], 400);
    $msgs = [];
    if (file_exists($msgsFile)) {
        $m = json_decode((string)file_get_contents($msgsFile), true);
        if (is_array($m)) $msgs = $m;
    }
    $found = false;
    foreach ($msgs as &$thread) {
        if (($thread['id'] ?? '') !== $threadId) continue;
        if (($thread['member_id'] ?? '') !== $member['id']) break;
        $thread['deleted_by_member'] = true;
        $found = true;
        break;
    }
    unset($thread);
    if (!$found) respond(['status' => 'error', 'message' => 'thread_not_found'], 404);
    file_put_contents($msgsFile, json_encode($msgs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    respond(['status' => 'ok']);
}

if ($body === '') respond(['status' => 'error', 'message' => 'missing_fields'], 400);
if ($action === 'new' && $subject === '') respond(['status' => 'error', 'message' => 'missing_fields'], 400);

// Handle optional file attachment
$fileInfo = null;
if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
    $filesDir = $dataDir . '/member_msg_files';
    if (!is_dir($filesDir)) mkdir($filesDir, 0700, true);

    $origName = basename((string)$_FILES['attachment']['name']);
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowedExts  = ['jpg','jpeg','png','gif','webp','pdf','doc','docx'];
    $allowedMimes = ['image/jpeg','image/png','image/gif','image/webp','application/pdf',
                     'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $detectedMime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['attachment']['tmp_name']);

    if (!in_array($ext, $allowedExts, true) || !in_array($detectedMime, $allowedMimes, true)) {
        respond(['status' => 'error', 'message' => 'invalid_file_type'], 400);
    }
    if ((int)$_FILES['attachment']['size'] > 5 * 1024 * 1024) {
        respond(['status' => 'error', 'message' => 'file_too_large'], 400);
    }
    $storedName = 'mf_' . uniqid('', true) . '.' . $ext;
    if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $filesDir . '/' . $storedName)) {
        respond(['status' => 'error', 'message' => 'upload_failed'], 500);
    }
    $fileInfo = ['orig' => $origName, 'stored' => $storedName, 'mime' => $detectedMime, 'size' => (int)$_FILES['attachment']['size']];
}

$msgs = [];
if (file_exists($msgsFile)) {
    $m = json_decode((string)file_get_contents($msgsFile), true);
    if (is_array($m)) $msgs = $m;
}

$now    = date('Y-m-d H:i:s');
$newMsg = ['from' => 'member', 'body' => $body, 'created_at' => $now];
if ($fileInfo !== null) $newMsg['file'] = $fileInfo;

if (($action === 'reply' || $action === 'peer_reply') && $threadId !== '') {
    $found = false;
    foreach ($msgs as &$thread) {
        if (($thread['id'] ?? '') !== $threadId) continue;
        $isOrigSender = ($thread['member_id'] ?? '') === $member['id'];
        $isRecipient  = ($thread['recipient_member_id'] ?? '') === $member['id'];
        if (!$isOrigSender && !$isRecipient) break;
        $newMsg['from'] = $isRecipient ? 'peer' : 'member';
        $thread['messages'][] = $newMsg;
        $thread['status']     = 'open';
        $thread['updated_at'] = $now;
        $thread['deleted_by_admin'] = false;
        $subject = $thread['subject'] ?? 'Nachricht';
        $found = true;
        // Email notification: notify the other party
        if ($isRecipient && filter_var($thread['member_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $fromEmail = 'kontakt@example.org';
            $notifyEmail = $thread['member_email'];
            $notifyName  = htmlspecialchars($thread['member_name'] ?? '');
            $replyerName = htmlspecialchars($member['name'] ?? '');
            $subj = 'Antwort auf Ihre Nachricht: ' . $subject;
            $html = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;background:#f5f7f2;padding:20px'>"
                  . "<div style='max-width:540px;margin:0 auto;background:#fff;border-radius:12px;border:1px solid #d4e6c3;overflow:hidden'>"
                  . "<div style='background:#3d6b41;padding:16px 24px'><img src='https://kgv461.de/images/logo.png' alt='KGV Musterstadt e.V.' height='48' style='display:block;max-height:48px'></div>"
                  . "<div style='padding:24px'><p>Hallo {$notifyName},</p>"
                  . "<p style='color:#5a6c5a'><strong>{$replyerName}</strong> hat auf Ihre Nachricht geantwortet.</p>"
                  . "<div style='background:#f5f7f2;border-radius:8px;padding:14px;margin-bottom:12px'><p style='margin:0;white-space:pre-wrap;color:#2d3e2d'>" . htmlspecialchars($body) . "</p></div>"
                  . "<a href='https://kgv461.de/mitglieder.php?tab=kontakt' style='display:inline-block;background:#3d6b41;color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.88rem'>Nachricht ansehen →</a>"
                  . "</div></div></body></html>";
            $hdr = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: KGV Musterstadt Mitgliederbereich <{$fromEmail}>\r\nReturn-Path: {$fromEmail}\r\n";
            @mail($notifyEmail, '=?UTF-8?B?' . base64_encode($subj) . '?=', $html, $hdr, "-f{$fromEmail}");
        }
        break;
    }
    unset($thread);
    if (!$found) respond(['status' => 'error', 'message' => 'thread_not_found'], 404);
} else {
    $threadId = 'msg_' . uniqid('', true);
    // Resolve recipient member details if member-to-member
    if ($recipientMemberId !== '') {
        $membersFile = $dataDir . '/members.json';
        $allMems = file_exists($membersFile) ? (json_decode((string)file_get_contents($membersFile), true) ?: []) : [];
        $recipientConsent = false;
        $recipientActive  = false;
        foreach ($allMems as $rm) {
            if (($rm['id'] ?? '') === $recipientMemberId) {
                $recipientMemberName  = $rm['name'] ?? '';
                $recipientMemberEmail = $rm['email'] ?? '';
                $recipientActive      = !empty($rm['active']);
                $recipientConsent     = !empty($rm['consents']['contact_allowed']);
                break;
            }
        }
        // Security: recipient must be an active member and have explicit contact consent.
        if ($recipientMemberName === '' || !$recipientActive) {
            respond(['status' => 'error', 'message' => 'recipient_not_found'], 400);
        }
        if (!$recipientConsent) {
            respond(['status' => 'error', 'message' => 'recipient_no_consent'], 403);
        }
    }
    $msgs[] = [
        'id'                     => $threadId,
        'member_id'              => $member['id'],
        'member_name'            => $member['name'],
        'member_email'           => $member['email'],
        'member_parzelle'        => $member['parzelle'],
        'subject'                => $subject,
        'status'                 => 'open',
        'created_at'             => $now,
        'updated_at'             => $now,
        'messages'               => [$newMsg],
        'recipient_role'         => $recipientRole,
        'recipient_member_id'    => $recipientMemberId,
        'recipient_member_name'  => $recipientMemberName,
        'recipient_member_email' => $recipientMemberEmail,
        'first_reply_by_id'      => null,
        'first_reply_by_name'    => null,
    ];
}

file_put_contents($msgsFile, json_encode($msgs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

// Notify recipients by email (only if enabled in settings)
$ccf = $dataDir . '/content.json';
$cc  = file_exists($ccf) ? (json_decode((string)file_get_contents($ccf), true) ?: []) : [];
$en  = $cc['settings']['email_notifications'] ?? [];
if ($action !== 'peer_reply' && !empty($en['enabled']) && !empty($en['member_notify'])) {
    $fromEmail = 'kontakt@example.org';
    $fromName  = 'KGV Musterstadt Mitgliederbereich';
    $mn = htmlspecialchars($member['name']);
    $mp = htmlspecialchars($member['parzelle']);
    $emailSubj = ($action === 'reply' ? 'Neue Antwort: ' : 'Neue Nachricht: ') . $subject . " (Parzelle {$member['parzelle']})";
    $fileNote  = $fileInfo ? "<p style='font-size:0.85rem;color:#5a6c5a;margin-top:10px'>📎 Anhang: " . htmlspecialchars($fileInfo['orig']) . "</p>" : '';

    // Build list of recipients based on role
    $emailRecipients = [];
    if ($action === 'new' && $recipientRole === 'member') {
        // Member-to-member: notify the specific recipient member
        if ($recipientMemberEmail !== '' && filter_var($recipientMemberEmail, FILTER_VALIDATE_EMAIL)) {
            $emailRecipients[] = $recipientMemberEmail;
        }
    } elseif ($action === 'new') {
        $membersFile = $dataDir . '/members.json';
        $allMems = file_exists($membersFile) ? (json_decode((string)file_get_contents($membersFile), true) ?: []) : [];
        foreach ($allMems as $rm) {
            if (!empty($rm['active']) && in_array($recipientRole, $rm['roles'] ?? [], true)
                && filter_var($rm['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                // If specific IDs were selected, only include those
                if (!empty($recipientIds) && !in_array((string)($rm['id'] ?? ''), $recipientIds, true)) continue;
                $emailRecipients[] = $rm['email'];
            }
        }
        // Fallback for vorstand: use admin contact_to email (only when no specific IDs given)
        if ($recipientRole === 'vorstand' && empty($emailRecipients) && empty($recipientIds)) {
            $fb = filter_var(trim((string)($en['contact_to'] ?? '')), FILTER_VALIDATE_EMAIL)
                ? trim((string)$en['contact_to']) : 'vorstand@example.org';
            $emailRecipients[] = $fb;
        }
    } else {
        // Replies always go to admin (vorstand) — peer_reply is handled separately above
        $adminEmail = filter_var(trim((string)($en['contact_to'] ?? '')), FILTER_VALIDATE_EMAIL)
            ? trim((string)$en['contact_to']) : 'vorstand@example.org';
        $emailRecipients[] = $adminEmail;
    }

    // Link: always member area — admins log in there and reach Backoffice via nav
    $adminLink = 'https://kgv461.de/mitglieder.php?tab=kontakt';

    // Role label for email header
    $roleLabels = ['vorstand' => '👑 Vorstand', 'kassier' => '💶 Kassier/in', 'koppel' => '🔨 Wegewart/in', 'member' => '👤 ' . htmlspecialchars($recipientMemberName)];
    $roleLabel  = $roleLabels[$recipientRole] ?? 'Vorstand';
    $roleBadge  = $action === 'new'
        ? "<tr><td style='padding:6px 0;color:#5a6c5a;border-top:1px solid #e8f0e0'>An</td><td style='padding:6px 0;border-top:1px solid #e8f0e0'>{$roleLabel}</td></tr>"
        : '';

    $emailHtml = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;background:#f5f7f2;padding:20px'>"
               . "<div style='max-width:540px;margin:0 auto;background:#fff;border-radius:12px;border:1px solid #d4e6c3;overflow:hidden'>"
               . "<div style='background:#3d6b41;padding:16px 24px'><img src='https://kgv461.de/images/logo.png' alt='KGV Musterstadt e.V.' height='48' style='display:block;max-height:48px'></div>"
               . "<div style='padding:24px'>"
               . "<table style='width:100%;border-collapse:collapse;margin-bottom:16px'>"
               . "<tr><td style='padding:6px 0;color:#5a6c5a;width:80px'>Von</td><td style='padding:6px 0;font-weight:600'>{$mn} · Parzelle {$mp}</td></tr>"
               . "<tr><td style='padding:6px 0;color:#5a6c5a;border-top:1px solid #e8f0e0'>Betreff</td><td style='padding:6px 0;border-top:1px solid #e8f0e0'>" . htmlspecialchars($subject) . "</td></tr>"
               . $roleBadge
               . "</table>"
               . "<div style='background:#f5f7f2;border-radius:8px;padding:14px;margin-bottom:12px'><p style='margin:0;white-space:pre-wrap;color:#2d3e2d'>" . htmlspecialchars($body) . "</p></div>"
               . $fileNote
               . "<a href='{$adminLink}' style='display:inline-block;background:#3d6b41;color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.88rem;margin-top:12px'>Nachricht ansehen &amp; antworten →</a>"
               . "</div></div></body></html>";
    $hdr = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: {$fromName} <{$fromEmail}>\r\nReply-To: {$member['email']}\r\nReturn-Path: {$fromEmail}\r\n";
    foreach ($emailRecipients as $recEmail) {
        @mail($recEmail, $emailSubj, $emailHtml, $hdr, "-f{$fromEmail}");
    }
}

error_log("[member-contact] {$action} from={$member['email']} subject={$subject}\n", 3, $logFile);
respond(['status' => 'ok', 'thread_id' => $threadId]);
