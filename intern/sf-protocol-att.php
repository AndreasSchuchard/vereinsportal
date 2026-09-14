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
    || in_array('web', $memberRoles, true);
if (!$canAccess) { http_response_code(403); exit('Forbidden'); }

require_once dirname(__DIR__) . '/inc/schriftfuehrung.php';

$pid = trim((string)($_GET['pid'] ?? ''));
$id  = trim((string)($_GET['id']  ?? ''));
if ($pid === '' || $id === '') { http_response_code(400); exit('Missing'); }

$protocols = sf_load_json(SF_PROTOCOLS);
$att = null;
foreach ($protocols as $p) {
    if (($p['id'] ?? '') === $pid) {
        foreach (($p['attachments'] ?? []) as $a) {
            if (($a['id'] ?? '') === $id) { $att = $a; break 2; }
        }
    }
}
if (!$att) { http_response_code(404); exit('Not found'); }

$fname = (string)($att['filename'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $fname)) { http_response_code(400); exit('Invalid filename'); }
$path = SF_UPLOAD_PROTOCOLS . '/' . $fname;
if (!file_exists($path)) { http_response_code(404); exit('Missing on disk'); }

header('Content-Type: ' . ($att['mime'] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
$safe = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', (string)($att['title'] ?? 'attachment'));
header('Content-Disposition: inline; filename="' . $safe . '"');
readfile($path);
