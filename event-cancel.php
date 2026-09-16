<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors',     '1');
require_once __DIR__ . '/inc/events.php';

$regId = trim((string)($_GET['id'] ?? ''));
$token = trim((string)($_GET['t']  ?? ''));

$msg = '';
$msgType = 'error';
$eventTitle = '';
$regName = '';

if ($regId === '' || $token === '') {
    $msg = 'Ungültiger Stornierungs-Link.';
} else {
    $confirm = isset($_GET['confirm']) || ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $all = kgv_events_all();
    $found = false;
    foreach ($all as $eIdx => &$ev) {
        foreach (($ev['registrations'] ?? []) as $rIdx => &$r) {
            if (($r['id'] ?? '') !== $regId) continue;
            $found = true;
            $eventTitle = (string)($ev['title'] ?? '');
            $regName    = (string)($r['fullname'] ?? '');
            $stored = (string)($r['cancel_token'] ?? '');
            if ($stored === '' || !hash_equals($stored, $token)) {
                $msg = 'Stornierungs-Token ungültig oder bereits verwendet.';
                break 2;
            }
            if (!empty($r['cancelled_at'])) {
                $msg = 'Deine Anmeldung ist bereits storniert.';
                $msgType = 'info';
                break 2;
            }
            if (!$confirm) {
                // Bestätigungs-Seite zeigen
                break 2;
            }
            $r['cancelled_at']  = date('Y-m-d H:i:s');
            $r['cancel_token']  = ''; // verbrauchen
            if (kgv_events_save($all)) {
                $msg = 'Deine Anmeldung für "' . $eventTitle . '" wurde erfolgreich storniert.';
                $msgType = 'success';
                @error_log("[event] cancel: reg={$regId} ev={$ev['id']} name={$regName}\n", 3, __DIR__ . '/data/admin.log');
            } else {
                $msg = 'Stornierung konnte nicht gespeichert werden. Bitte den Festausschuss kontaktieren.';
            }
            break 2;
        }
    }
    unset($ev, $r);
    if (!$found) $msg = 'Anmeldung nicht gefunden — eventuell bereits gelöscht.';
}

$confirmStep = ($msg === '' && $regId !== '' && $token !== '' && $regName !== '');
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Anmeldung stornieren | unser Verein</title>
<link rel="icon" type="image/png" href="/logo.png">
<link rel="stylesheet" href="/portal.css?v=2">
<style>
.cnc-wrap { max-width: 540px; margin: 40px auto; padding: 28px 30px; background: #fff; border-radius: 14px; box-shadow: 0 4px 18px rgba(0,0,0,0.08); }
.cnc-head { background: #c62828; color: #fff; padding: 18px 22px; margin: -28px -30px 22px; border-radius: 14px 14px 0 0; text-align: center; }
.cnc-head h1 { margin: 0; font-size: 1.3rem; }
.cnc-msg { padding: 14px 18px; border-radius: 8px; line-height: 1.55; margin-bottom: 18px; }
.cnc-msg.success { background: #e8f5e9; color: #1b5e20; border-left: 4px solid #2e7d32; }
.cnc-msg.error   { background: #ffebee; color: #b71c1c; border-left: 4px solid #c62828; }
.cnc-msg.info    { background: #e3f2fd; color: #1565c0; border-left: 4px solid #1565c0; }
.cnc-btn { display: inline-block; padding: 11px 22px; border-radius: 8px; font-weight: 700; text-decoration: none; font-size: 0.95rem; }
.cnc-btn.danger { background: #c62828; color: #fff; border: 0; cursor: pointer; }
.cnc-btn.danger:hover { background: #a71d1d; }
.cnc-btn.ghost { background: #f0f5f0; color: #3d6b41; margin-left: 8px; }
.cnc-info { color: #4a5a4a; line-height: 1.6; margin-bottom: 18px; }
.cnc-info strong { color: #2d3e2d; }
</style>
</head>
<body style="background: #f2f6f0; margin: 0; font-family: 'Poppins', Arial, sans-serif;">

<div class="cnc-wrap">
  <div class="cnc-head"><h1>Anmeldung stornieren</h1></div>

  <?php if ($msg !== ''): ?>
    <div class="cnc-msg <?= htmlspecialchars($msgType) ?>"><?= htmlspecialchars($msg) ?></div>
    <p style="text-align:center"><a href="/" class="cnc-btn ghost">← Zur Startseite</a></p>
  <?php elseif ($confirmStep): ?>
    <p class="cnc-info">
      Bist du sicher, dass du deine Anmeldung für<br>
      <strong><?= htmlspecialchars($eventTitle) ?></strong><br>
      (<?= htmlspecialchars($regName) ?>) stornieren möchtest?
    </p>
    <p class="cnc-info">Diese Aktion kann nicht rückgängig gemacht werden. Falls du dich später wieder anmelden möchtest, müsstest du das Formular neu ausfüllen.</p>
    <form method="POST" action="/event-cancel.php">
      <input type="hidden" name="id" value="<?= htmlspecialchars($regId) ?>">
      <input type="hidden" name="t"  value="<?= htmlspecialchars($token) ?>">
      <input type="hidden" name="confirm" value="1">
      <p style="text-align:center">
        <button type="submit" class="cnc-btn danger">Ja, Anmeldung stornieren</button>
        <a href="/" class="cnc-btn ghost">Nein, abbrechen</a>
      </p>
    </form>
  <?php endif; ?>
</div>

</body>
</html>
<?php
// POST → einmal nach Confirmed neu rendern (Refresh-Safe). Falls POST, leite zur GET-Confirmed-URL:
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $msg !== '') {
    // schon gerendert, ok.
}
?>
