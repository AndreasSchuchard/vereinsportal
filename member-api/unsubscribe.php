<?php
declare(strict_types=1);
require_once __DIR__ . '/_security.php';

/**
 * E-Mail-Benachrichtigung abmelden
 * GET: ?id=MEMBER_ID&token=NOTIFY_TOKEN&type=posts
 */

$dataDir     = dirname(__DIR__) . '/data';
$membersFile = $dataDir . '/members.json';

$memberId = trim((string)($_GET['id']    ?? ''));
$token    = trim((string)($_GET['token'] ?? ''));
$type     = trim((string)($_GET['type']  ?? 'posts'));

$error   = '';
$success = '';

if ($memberId === '' || $token === '') {
    $error = 'Ungültiger Abmeldelink.';
} elseif (!file_exists($membersFile)) {
    $error = 'Daten nicht gefunden.';
} else {
    $members = json_decode((string)file_get_contents($membersFile), true) ?: [];
    $found   = false;
    foreach ($members as &$m) {
        if (($m['id'] ?? '') !== $memberId) continue;
        $expected = (string)($m['notify_token'] ?? '');
        if ($expected === '' || !hash_equals($expected, $token)) {
            $error = 'Ungültiger oder abgelaufener Token.';
            break;
        }
        $found = true;
        if ($type === 'posts') {
            $m['email_posts'] = false;
            $success = 'Sie erhalten keine Pinnwand-Benachrichtigungen mehr.';
        } else {
            $error = 'Unbekannter Abmeldetyp.';
            break;
        }
        break;
    }
    unset($m);
    if (!$found && $error === '') $error = 'Mitglied nicht gefunden.';
    if ($success !== '') {
        file_put_contents($membersFile, json_encode($members, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Abmeldung — KGV Musterstadt e.V.</title>
<style>
  body { margin:0; font-family: Arial, sans-serif; background:#f2f6f0; display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px; box-sizing:border-box; }
  .card { background:#fff; border-radius:16px; border:1px solid #d4e6c3; max-width:480px; width:100%; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.06); }
  .header { background:#3d6b41; padding:20px 28px; display:flex; align-items:center; gap:14px; }
  .header img { height:40px; display:block; }
  .body { padding:32px 28px; text-align:center; }
  .icon { font-size:2.8rem; margin-bottom:12px; }
  h2 { margin:0 0 10px; color:#2d3e2d; font-size:1.15rem; }
  p { color:#5a6c5a; font-size:0.9rem; line-height:1.6; margin:0 0 20px; }
  .btn { display:inline-block; background:#3d6b41; color:#fff; padding:10px 24px; border-radius:8px; text-decoration:none; font-weight:600; font-size:0.9rem; }
  .error { color:#c62828; }
</style>
</head>
<body>
<div class="card">
  <div class="header">
    <img src="https://kgv461.de/images/logo.png" alt="KGV Musterstadt e.V.">
  </div>
  <div class="body">
    <?php if ($success !== ''): ?>
      <div class="icon">✅</div>
      <h2>Abmeldung erfolgreich</h2>
      <p><?= htmlspecialchars($success) ?><br>Sie können sich jederzeit im Mitglieder-Bereich wieder anmelden.</p>
      <a href="https://kgv461.de/mitglieder.php" class="btn">Zum Mitglieder-Bereich</a>
    <?php else: ?>
      <div class="icon">⚠️</div>
      <h2 class="error">Fehler</h2>
      <p class="error"><?= htmlspecialchars($error) ?></p>
      <a href="https://kgv461.de/mitglieder.php" class="btn">Zur Startseite</a>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
