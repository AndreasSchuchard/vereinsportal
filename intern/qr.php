<?php
declare(strict_types=1);

session_start();

$_isSuperAdmin = !empty($_SESSION['kgv_admin']);
$_memberRoles = $_SESSION['kgv_member']['roles'] ?? [];
$_allowedRoles = ['vorstand', 'buchung', 'schriftfuehrer', 'web'];
if (!$_isSuperAdmin && empty(array_intersect($_memberRoles, $_allowedRoles))) {
    http_response_code(403);
    exit('Forbidden');
}

$payload = trim((string)($_GET['data'] ?? ''));
$size = max(180, min(900, (int)($_GET['size'] ?? 600)));
if ($payload === '' || strlen($payload) > 2048) {
    http_response_code(400);
    exit('Invalid QR data');
}

require_once dirname(__DIR__) . '/inc/local_qr.php';

try {
    $png = kgv_qr_png($payload, $size);
} catch (Throwable $e) {
    error_log('[qr] ' . $e->getMessage());
    http_response_code(500);
    exit('QR generation failed');
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo $png;

