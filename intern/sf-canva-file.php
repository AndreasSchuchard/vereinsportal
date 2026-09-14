<?php
declare(strict_types=1);

// Auth: muss eingeloggt sein (Admin oder Mitglied mit Rolle)
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure',   '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

// Zugriff: SuperAdmin + Schriftführer + Web-Rolle
$isSuperAdmin = !empty($_SESSION['kgv_admin']);
$memberRoles  = $_SESSION['kgv_member']['roles'] ?? [];
$canAccess    = $isSuperAdmin
    || in_array('schriftfuehrer', $memberRoles, true)
    || in_array('web', $memberRoles, true);
if (!$canAccess) { http_response_code(403); exit('Forbidden'); }

require_once dirname(__DIR__) . '/inc/schriftfuehrung.php';

$id    = trim((string)($_GET['id'] ?? ''));
$force = !empty($_GET['dl']);
if ($id === '') { http_response_code(400); exit('Missing id'); }

$files = sf_load_json(SF_CANVA);
$file  = null;
foreach ($files as $f) if (($f['id'] ?? '') === $id) { $file = $f; break; }
if (!$file) { http_response_code(404); exit('Not found'); }

$fname = (string)($file['filename'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $fname)) { http_response_code(400); exit('Invalid filename'); }
$path = SF_UPLOAD_CANVA . '/' . $fname;
if (!file_exists($path)) { http_response_code(404); exit('Missing on disk'); }

$mime = (string)($file['mime'] ?? 'application/octet-stream');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
$safeTitle = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', (string)($file['title'] ?? 'canva'));
$ext = pathinfo($fname, PATHINFO_EXTENSION);
if ($force) {
    header('Content-Disposition: attachment; filename="' . $safeTitle . '.' . $ext . '"');
} else {
    header('Content-Disposition: inline; filename="' . $safeTitle . '.' . $ext . '"');
}
readfile($path);
