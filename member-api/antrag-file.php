<?php
declare(strict_types=1);
require_once __DIR__ . '/_security.php';
session_start();

// Liefert einem eingeloggten Mitglied die Anhänge SEINER EIGENEN Anträge aus
// (attachments + decision_files). Cockpit-Nutzer verwenden intern/sf-antrag-file.php.

$isMember = !empty($_SESSION['kgv_member']);
if (!$isMember) { http_response_code(401); exit('Login erforderlich'); }

require_once dirname(__DIR__) . '/inc/schriftfuehrung.php';

$appId  = trim((string)($_GET['app'] ?? ''));
$fileId = trim((string)($_GET['id']  ?? ''));
$force  = !empty($_GET['dl']);
if ($appId === '' || $fileId === '') { http_response_code(400); exit('Fehlende Parameter'); }

$memberId = (string)($_SESSION['kgv_member']['id'] ?? '');
$apps = sf_load_json(SF_APPLICATIONS);
$file = null;
foreach ($apps as $a) {
    if (($a['id'] ?? '') !== $appId) continue;
    if ((string)($a['from_id'] ?? '') !== $memberId || $memberId === '') { http_response_code(403); exit('Kein Zugriff'); }
    foreach (array_merge((array)($a['attachments'] ?? []), (array)($a['decision_files'] ?? [])) as $f) {
        if (($f['id'] ?? '') === $fileId) { $file = $f; break 2; }
    }
}
if (!$file) { http_response_code(404); exit('Nicht gefunden'); }

$fname = (string)($file['filename'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $fname)) { http_response_code(400); exit('Ungültig'); }
$path = SF_UPLOAD_ANTRAEGE . '/' . $fname;
if (!is_file($path)) { http_response_code(404); exit('Datei fehlt'); }

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
