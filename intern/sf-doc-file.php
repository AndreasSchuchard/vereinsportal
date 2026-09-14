<?php
declare(strict_types=1);

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

$docs = sf_load_json(SF_DOCUMENTS);
$file = null;
foreach ($docs as $d) if (($d['id'] ?? '') === $id) { $file = $d; break; }
if (!$file) { http_response_code(404); exit('Not found'); }

$fname = (string)($file['filename'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $fname)) { http_response_code(400); exit('Invalid filename'); }
$path = SF_UPLOAD_DOCS . '/' . $fname;
if (!file_exists($path)) { http_response_code(404); exit('Missing on disk'); }

$mime = (string)($file['mime'] ?? 'application/octet-stream');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
$safeTitle = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', (string)($file['title'] ?? 'document'));
$ext = pathinfo($fname, PATHINFO_EXTENSION);
$disp = $force ? 'attachment' : 'inline';
header("Content-Disposition: {$disp}; filename=\"{$safeTitle}.{$ext}\"");
readfile($path);
