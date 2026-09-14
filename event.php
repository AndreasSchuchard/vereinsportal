<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors',     '1');
require_once __DIR__ . '/inc/events.php';
require_once __DIR__ . '/inc/email_template.php';

$_slug = trim((string)($_GET['slug'] ?? ''));
$event = $_slug !== '' ? kgv_event_by_slug($_slug) : null;

// Wenn nichts gefunden oder nicht aktiv UND nicht abgelaufen → 404
$showNotFound = !$event;
$showClosed   = $event && !empty($event['deadline']) && kgv_event_deadline_passed($event);
$showInactive = $event && empty($event['active']) && !$showClosed;
$canRegister  = $event && kgv_event_is_active($event);

$pageTitle = $event ? ($event['title'] ?? 'Veranstaltung') : 'Veranstaltung nicht gefunden';

if ($showNotFound) { http_response_code(404); }

// Pre-fill für eingeloggte Mitglieder
require_once __DIR__ . '/inc/session.php';
kgv_start_existing_session();
$_prefill = ['fullname'=>'','email'=>'','phone'=>''];
if (!empty($_SESSION['kgv_member'])) {
    $m = $_SESSION['kgv_member'];
    $_prefill = [
        'fullname' => (string)($m['name']  ?? ''),
        'email'    => (string)($m['email'] ?? ''),
        'phone'    => (string)($m['phone'] ?? ''),
    ];
}

// Statusmeldungen aus Submit-Redirect
$flashOk    = isset($_GET['ok']);
$flashError = (string)($_GET['error'] ?? '');
$flashId    = (string)($_GET['rid'] ?? '');
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<meta name="description" content="<?= htmlspecialchars($event['subtitle'] ?? 'Anmeldung zur Veranstaltung des KGV Musterstadt e.V.') ?>">
<title><?= htmlspecialchars($pageTitle) ?> | KGV Musterstadt e.V.</title>
<link rel="icon" type="image/png" href="/logo_kgv461.png">
<link rel="stylesheet" href="/kgv461.css?v=2">
<style>
.evt-wrap { max-width: 720px; margin: 0 auto; padding: 28px 20px 60px; }
.evt-head {
    background: var(--primary-green, #3d6b41);
    color: #fff;
    border-radius: 14px 14px 0 0;
    padding: 28px 28px 20px;
    text-align: center;
}
.evt-head h1 { margin: 0; font-size: 1.7rem; line-height: 1.25; font-weight: 700; }
.evt-head .evt-subtitle { margin: 8px 0 0; opacity: 0.9; font-size: 1rem; }
.evt-body { background: #fff; border: 1px solid #e0e0e0; border-top: 0; border-radius: 0 0 14px 14px; padding: 26px 28px 30px; box-shadow: 0 4px 18px rgba(0,0,0,0.06); }
.evt-desc { color: #4a5a4a; line-height: 1.7; margin-bottom: 22px; white-space: pre-wrap; }
.evt-meta { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
.evt-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f0f5f0; color: #2d3e2d; border-radius: 14px; font-size: 0.82rem; font-weight: 500; }
.evt-badge.deadline { background: #fff8e1; color: #f57f17; }
.evt-badge.internal { background: #e3f2fd; color: #1565c0; }
.evt-badge.public   { background: #e8f5e9; color: #2e7d32; }

.evt-form .field { margin-bottom: 16px; }
.evt-form label { display: block; font-size: 0.88rem; font-weight: 600; color: #2d3e2d; margin-bottom: 6px; }
.evt-form .hint { color: #8a9a8a; font-weight: 400; font-size: 0.82rem; }
.evt-form input[type=text],
.evt-form input[type=email],
.evt-form input[type=tel],
.evt-form input[type=number],
.evt-form textarea {
    width: 100%; padding: 11px 14px; font-size: 0.95rem;
    border: 1px solid #c8d3c4; border-radius: 8px; font-family: inherit;
    background: #fdfdfd; box-sizing: border-box;
}
.evt-form input:focus, .evt-form textarea:focus { outline: 0; border-color: var(--primary-green, #3d6b41); box-shadow: 0 0 0 3px rgba(61,107,65,0.12); }
.evt-form textarea { min-height: 84px; resize: vertical; }
.evt-form .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 480px) { .evt-form .row2 { grid-template-columns: 1fr; } }
.evt-consent { background: #f5f7f2; border-left: 4px solid var(--primary-green, #3d6b41); padding: 12px 14px; border-radius: 6px; margin: 14px 0 20px; font-size: 0.82rem; color: #4a5a4a; line-height: 1.5; }
.evt-consent label { display: flex; gap: 9px; align-items: flex-start; font-weight: 400; }
.evt-consent input[type=checkbox] { margin-top: 3px; flex-shrink: 0; }
.evt-submit { width: 100%; padding: 14px 20px; font-size: 1rem; font-weight: 700; color: #fff; background: var(--primary-green, #3d6b41); border: 0; border-radius: 8px; cursor: pointer; transition: background .15s; }
.evt-submit:hover { background: #2d5231; }
.evt-honeypot { position: absolute; left: -9999px; top: -9999px; height: 0; width: 0; opacity: 0; }
.evt-banner { padding: 16px 18px; border-radius: 10px; margin-bottom: 22px; font-size: 0.95rem; line-height: 1.5; }
.evt-banner.success { background: #e8f5e9; color: #1b5e20; border-left: 4px solid #2e7d32; }
.evt-banner.error   { background: #ffebee; color: #b71c1c; border-left: 4px solid #c62828; }
.evt-banner.info    { background: #fff8e1; color: #f57f17; border-left: 4px solid #f9a825; }
.evt-foot-link { text-align: center; margin-top: 22px; }
.evt-foot-link a { color: var(--primary-green, #3d6b41); text-decoration: none; font-weight: 600; }
.evt-flyer-img { margin: -26px -28px 22px; display: block; line-height: 0; background: #e8f0e0; }
.evt-flyer-img img { width: 100%; height: auto; display: block; max-height: 700px; object-fit: contain; background: #fff; }
.evt-flyer-pdf {
    display: flex; align-items: center; gap: 14px;
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    border-radius: 10px; padding: 16px 20px; margin: 0 0 22px;
    color: #1b5e20; text-decoration: none; font-weight: 600;
    transition: transform .15s, box-shadow .15s;
}
.evt-flyer-pdf:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(46,125,50,0.15); }
.evt-flyer-pdf .ico { font-size: 2.2rem; flex-shrink: 0; }
.evt-flyer-pdf .lbl { font-size: 1rem; line-height: 1.3; }
.evt-flyer-pdf .sub { font-weight: 400; font-size: 0.82rem; color: #2e7d32; opacity: 0.85; }
</style>
</head>
<body style="background: var(--bg-light, #f2f6f0); margin: 0; font-family: 'Poppins', Arial, sans-serif;">

<div class="evt-wrap">

<?php if ($showNotFound): ?>
  <div class="evt-head"><h1>Veranstaltung nicht gefunden</h1></div>
  <div class="evt-body">
    <p>Diese Anmeldeseite existiert nicht oder wurde entfernt.</p>
    <div class="evt-foot-link"><a href="/">← Zur Startseite KGV Musterstadt</a></div>
  </div>

<?php else: ?>
  <div class="evt-head">
    <h1><?= htmlspecialchars($event['title'] ?? '') ?></h1>
    <?php if (!empty($event['subtitle'])): ?>
    <p class="evt-subtitle"><?= htmlspecialchars($event['subtitle']) ?></p>
    <?php endif; ?>
  </div>
  <div class="evt-body">

    <?php if ($flashOk): ?>
    <div class="evt-banner success">
      <strong>Anmeldung erfolgreich eingegangen! 🎉</strong><br>
      Du bekommst gleich eine Bestätigungs-E-Mail. Eine verbindliche Zusage erfolgt noch durch den Festausschuss.
    </div>
    <?php elseif ($flashError !== ''): ?>
    <div class="evt-banner error">
      <strong>Anmeldung fehlgeschlagen.</strong><br>
      <?php
        echo htmlspecialchars(match ($flashError) {
            'missing'      => 'Bitte fülle alle Pflichtfelder aus.',
            'invalid_email'=> 'Die E-Mail-Adresse sieht nicht gültig aus.',
            'consent'      => 'Bitte stimme der Datenspeicherung zu.',
            'rate'         => 'Zu viele Anmeldeversuche von dieser Verbindung. Bitte später erneut versuchen.',
            'spam'         => 'Anmeldung konnte nicht verarbeitet werden.',
            'closed'       => 'Die Anmeldefrist für diese Veranstaltung ist abgelaufen.',
            'inactive'     => 'Diese Veranstaltung nimmt aktuell keine Anmeldungen entgegen.',
            default        => 'Bitte versuche es erneut.',
        });
      ?>
    </div>
    <?php endif; ?>

    <?php
    // Custom-Flyer prominent anzeigen (wenn aktiviert)
    $_hasCustomFlyer = !empty($event['use_custom_flyer']) && !empty($event['custom_flyer_file']);
    $_flyerExt = $_hasCustomFlyer ? strtolower(pathinfo($event['custom_flyer_file'], PATHINFO_EXTENSION)) : '';
    $_flyerUrl = $_hasCustomFlyer ? '/images/event_flyers/' . rawurlencode($event['custom_flyer_file']) : '';
    $_isImageFlyer = in_array($_flyerExt, ['jpg','jpeg','png'], true);
    ?>
    <?php if ($_hasCustomFlyer && $_isImageFlyer): ?>
    <a href="<?= htmlspecialchars($_flyerUrl) ?>" target="_blank" class="evt-flyer-img" title="Flyer in voller Größe öffnen">
      <img src="<?= htmlspecialchars($_flyerUrl) ?>" alt="Flyer zur Veranstaltung" loading="eager">
    </a>
    <?php elseif ($_hasCustomFlyer): ?>
    <a href="<?= htmlspecialchars($_flyerUrl) ?>" target="_blank" class="evt-flyer-pdf">
      <span class="ico">📄</span>
      <span>
        <span class="lbl">Flyer als PDF ansehen</span><br>
        <span class="sub">Öffnet sich in einem neuen Tab</span>
      </span>
    </a>
    <?php endif; ?>

    <div class="evt-meta">
      <?php if (!empty($event['event_date'])): ?>
        <?php $_ed = DateTimeImmutable::createFromFormat('Y-m-d', $event['event_date']); ?>
        <span class="evt-badge">📅 <?= $_ed ? $_ed->format('d.m.Y') : htmlspecialchars($event['event_date']) ?></span>
      <?php endif; ?>
      <?php if (!empty($event['deadline'])): ?>
        <?php $_dl = DateTimeImmutable::createFromFormat('Y-m-d', $event['deadline']); ?>
        <span class="evt-badge deadline">⏰ Anmeldeschluss: <?= $_dl ? $_dl->format('d.m.Y') : htmlspecialchars($event['deadline']) ?></span>
      <?php endif; ?>
      <?php if (($event['type'] ?? '') === 'internal'): ?>
        <span class="evt-badge internal">🌱 Interne Veranstaltung</span>
      <?php elseif (($event['type'] ?? '') === 'public'): ?>
        <span class="evt-badge public">🌿 Öffentliche Veranstaltung</span>
      <?php endif; ?>
    </div>

    <?php if (!empty($event['description'])): ?>
    <div class="evt-desc"><?= htmlspecialchars($event['description']) ?></div>
    <?php endif; ?>

    <?php if ($showClosed): ?>
      <div class="evt-banner info">
        <strong>Anmeldeschluss erreicht.</strong><br>
        Die Anmeldefrist war am <?= htmlspecialchars((DateTimeImmutable::createFromFormat('Y-m-d', $event['deadline']))?->format('d.m.Y') ?? $event['deadline']) ?>. Für Rückfragen wende dich gerne an den Festausschuss.
      </div>
    <?php elseif ($showInactive): ?>
      <div class="evt-banner info">Die Anmeldung ist aktuell deaktiviert.</div>
    <?php elseif ($canRegister): ?>
      <form method="POST" action="/event-register.php" class="evt-form" autocomplete="on">
        <input type="hidden" name="slug" value="<?= htmlspecialchars($event['slug'] ?? '') ?>">
        <!-- Honeypot: für Bots sichtbar, für Menschen unsichtbar -->
        <div class="evt-honeypot" aria-hidden="true">
          <label>Website (nicht ausfüllen)<input type="text" name="__website" tabindex="-1" autocomplete="off"></label>
        </div>

        <div class="field">
          <label for="evt-fullname">Vor- und Nachname *</label>
          <input id="evt-fullname" type="text" name="fullname" required maxlength="120" value="<?= htmlspecialchars($_prefill['fullname']) ?>" placeholder="z.B. Max Mustermann">
        </div>

        <div class="row2">
          <div class="field">
            <label for="evt-email">E-Mail-Adresse *</label>
            <input id="evt-email" type="email" name="email" required maxlength="120" value="<?= htmlspecialchars($_prefill['email']) ?>" placeholder="name@beispiel.de">
          </div>
          <div class="field">
            <label for="evt-phone">Telefonnummer *</label>
            <input id="evt-phone" type="tel" name="phone" required maxlength="40" value="<?= htmlspecialchars($_prefill['phone']) ?>" placeholder="0163 ...">
          </div>
        </div>

        <div class="row2">
          <div class="field">
            <label for="evt-guests">Anzahl Personen *</label>
            <input id="evt-guests" type="number" name="guests" required min="1" max="50" value="1">
          </div>
          <?php if (!empty($event['show_catering'])): ?>
          <div class="field">
            <label for="evt-catering">Buffet-Beitrag <span class="hint">(z.B. Kartoffelsalat)</span></label>
            <input id="evt-catering" type="text" name="catering" maxlength="120" placeholder="z.B. Nudelsalat für 8 Personen">
          </div>
          <?php endif; ?>
        </div>

        <div class="evt-consent">
          <label>
            <input type="checkbox" name="consent" value="1" required>
            <span>Ich willige in die Speicherung meiner Daten zur Organisation dieser Veranstaltung ein. Daten werden 30 Tage nach der Veranstaltung gelöscht. <a href="/datenschutz" target="_blank" style="color: inherit; text-decoration: underline;">Datenschutz</a></span>
          </label>
        </div>

        <button type="submit" class="evt-submit">Verbindlich anmelden →</button>
      </form>
    <?php endif; ?>

    <div class="evt-foot-link"><a href="/">← Zur Startseite KGV Musterstadt e.V.</a></div>
  </div>
<?php endif; ?>

</div>
</body>
</html>
