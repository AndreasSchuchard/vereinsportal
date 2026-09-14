<?php
declare(strict_types=1);

// Liefert Antrags-Anhänge (attachments + decision_files) für das Cockpit aus.
// Zugriff: SuperAdmin + Vorstand + Schriftführer + Web-Rolle.

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure',   '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

$isSuperAdmin = !empty($_SESSION['kgv_admin']);
$memberRoles  = $_SESSION['kgv_member']['roles'] ?? [];
$canAccess    = $isSuperAdmin
    || in_array('vorstand', $memberRoles, true)
    || in_array('schriftfuehrer', $memberRoles, true)
    || in_array('web', $memberRoles, true);
if (!$canAccess) { http_response_code(403); exit('Forbidden'); }

require_once dirname(__DIR__) . '/inc/schriftfuehrung.php';

$appId  = trim((string)($_GET['app'] ?? ''));
$fileId = trim((string)($_GET['id']  ?? ''));
$force  = !empty($_GET['dl']);
if ($appId === '' || $fileId === '') { http_response_code(400); exit('Missing params'); }

$apps = sf_load_json(SF_APPLICATIONS);
$file = null;
foreach ($apps as $a) {
    if (($a['id'] ?? '') !== $appId) continue;
    foreach (array_merge((array)($a['attachments'] ?? []), (array)($a['decision_files'] ?? [])) as $f) {
        if (($f['id'] ?? '') === $fileId) { $file = $f; break 2; }
    }
}
if (!$file) { http_response_code(404); exit('Not found'); }

$fname = (string)($file['filename'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $fname)) { http_response_code(400); exit('Invalid filename'); }
$path = SF_UPLOAD_ANTRAEGE . '/' . $fname;
if (!is_file($path)) { http_response_code(404); exit('Missing on disk'); }

$mime = (string)($file['mime'] ?? 'application/octet-stream');
$ext  = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
$safeTitle = preg_replace('/[^a-zA-Z0-9_.\- ]/', '_', (string)($file['title'] ?? 'anhang'));
$disp = ($force || $ext === 'heic') ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
header("Content-Disposition: {$disp}; filename=\"{$safeTitle}.{$ext}\"");
readfile($path);
exit;
