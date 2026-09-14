<?php
declare(strict_types=1);
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure',   '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

$isSuperAdmin = !empty($_SESSION['kgv_admin']);
$memberRoles  = $_SESSION['kgv_member']['roles'] ?? [];
$canAccess    = $isSuperAdmin
    || in_array('schriftfuehrer', $memberRoles, true)
    || in_array('vorstand',       $memberRoles, true)
    || in_array('web',            $memberRoles, true);
if (!$canAccess) { http_response_code(403); exit('Forbidden'); }

require_once dirname(__DIR__) . '/inc/schriftfuehrung.php';

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '') { http_response_code(400); exit('Missing'); }

$items = sf_load_json(SF_SCHAEDEN);
$item  = null;
foreach ($items as $s) if (($s['id'] ?? '') === $id) { $item = $s; break; }
if (!$item || empty($item['photo_filename'])) { http_response_code(404); exit('Not found'); }

$fname = (string)$item['photo_filename'];
if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $fname)) { http_response_code(400); exit('Invalid'); }
$path = SF_UPLOAD_SCHAEDEN . '/' . $fname;
if (!file_exists($path)) { http_response_code(404); exit('Missing on disk'); }

$ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
$mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="schaden_' . $id . '.' . $ext . '"');
readfile($path);
