<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$bookingsFile = __DIR__ . '/data/bookings.json';
$contentFile  = __DIR__ . '/data/content.json';

if (!file_exists($bookingsFile)) { echo '{"confirmed":[],"pending":[],"blocked":[]}'; exit; }

$raw = file_get_contents($bookingsFile);
$bookings = json_decode((string)$raw, true);
if (!is_array($bookings)) { echo '{"confirmed":[],"pending":[],"blocked":[]}'; exit; }

$confirmed = [];
$pending   = [];

foreach ($bookings as $b) {
    $status = $b['status'] ?? 'pending';
    if ($status === 'rejected' || $status === 'expired') continue;

    $bDates = $b['dates'] ?? (isset($b['date']) ? [$b['date']] : []);
    foreach ($bDates as $d) {
        if (!is_string($d) || $d === '') continue;
        if ($status === 'confirmed') {
            $confirmed[] = $d;
        } else {
            $pending[] = $d;
        }
    }
}

// Wenn ein Tag sowohl pending als auch confirmed hat → confirmed gewinnt
$pending = array_values(array_diff(array_unique($pending), array_unique($confirmed)));

// Blocked dates aus content.json live liefern (inkl. auto-generierter Puffertage)
$blocked = [];
if (file_exists($contentFile)) {
    $cc = json_decode((string)file_get_contents($contentFile), true) ?: [];
    foreach ($cc['blocked_dates'] ?? [] as $bd) {
        if (!empty($bd['date'])) $blocked[] = $bd['date'];
    }
    $tz = new DateTimeZone('Europe/Berlin');
    foreach ($cc['blocked_ranges'] ?? [] as $br) {
        $dtF = DateTimeImmutable::createFromFormat('Y-m-d', $br['from'] ?? '', $tz);
        $dtT = DateTimeImmutable::createFromFormat('Y-m-d', $br['to']   ?? '', $tz);
        if (!$dtF || !$dtT) continue;
        $cur = $dtF;
        while ($cur <= $dtT) {
            $blocked[] = $cur->format('Y-m-d');
            $cur = $cur->modify('+1 day');
        }
    }
}
$blocked = array_values(array_unique($blocked));

echo json_encode([
    'confirmed' => array_values(array_unique($confirmed)),
    'pending'   => $pending,
    'blocked'   => $blocked,
]);
