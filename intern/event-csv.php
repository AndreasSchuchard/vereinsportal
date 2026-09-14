<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors',     '1');
session_start();

$_isSuperAdmin = !empty($_SESSION['kgv_admin']);
$_roles = $_SESSION['kgv_member']['roles'] ?? [];
if (!($_isSuperAdmin || !empty(array_intersect($_roles, ['vorstand','web','schriftfuehrer'])))) {
    header('Location: /intern/'); exit;
}

require_once dirname(__DIR__) . '/inc/events.php';

$evId = trim((string)($_GET['id'] ?? ''));
$event = null;
foreach (kgv_events_all() as $e) {
    if (($e['id'] ?? '') === $evId) { $event = $e; break; }
}
if (!$event) { http_response_code(404); exit('Veranstaltung nicht gefunden'); }

$showCatering = !empty($event['show_catering']);

$filename = 'anmeldungen_' . ($event['slug'] ?? 'event') . '_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
// BOM für Excel-UTF-8-Kompatibilität
fwrite($out, "\xEF\xBB\xBF");

// Header-Zeile
$headers = ['Name','E-Mail','Telefon','Personen'];
if ($showCatering) $headers[] = 'Buffet-Beitrag';
$headers = array_merge($headers, ['Anmelde-Zeitpunkt','Storniert am','Quelle']);
fputcsv($out, $headers, ';', '"');

foreach (($event['registrations'] ?? []) as $r) {
    $row = [
        (string)($r['fullname']     ?? ''),
        (string)($r['email']        ?? ''),
        (string)($r['phone']        ?? ''),
        (int)   ($r['guests']       ?? 1),
    ];
    if ($showCatering) $row[] = (string)($r['catering'] ?? '');
    $row[] = (string)($r['submitted_at'] ?? '');
    $row[] = (string)($r['cancelled_at'] ?? '');
    $row[] = (string)($r['source'] ?? '');
    fputcsv($out, $row, ';', '"');
}
fclose($out);
exit;
