<?php
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=UTF-8');

$_isSuperAdmin  = !empty($_SESSION['kgv_admin']);
$_uploaderRoles = $_SESSION['kgv_member']['roles'] ?? [];
$_canUpload     = $_isSuperAdmin || !empty(array_intersect($_uploaderRoles, ['vorstand', 'web']));
if (!$_canUpload) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Keine Upload-Berechtigung']);
    exit;
}

$allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$maxBytes    = 5 * 1024 * 1024; // 5 MB
$uploadDir   = dirname(__DIR__) . '/images/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Upload-Fehler: ' . ($_FILES['file']['error'] ?? 'kein File')]);
    exit;
}

$file = $_FILES['file'];

if ($file['size'] > $maxBytes) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Datei zu groß (max. 5 MB)']);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime, $allowedMime, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültiger Dateityp: ' . $mime]);
    exit;
}

$ext      = match($mime) { 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', default => 'jpg' };
$purpose  = preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)($_POST['purpose'] ?? 'upload'))));
$filename = ($purpose ?: 'upload') . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest     = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Konnte Datei nicht speichern']);
    exit;
}

echo json_encode([
    'ok'       => true,
    'filename' => $filename,
    'url'      => '/images/' . $filename,
]);
