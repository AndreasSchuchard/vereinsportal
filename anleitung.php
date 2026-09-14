<?php
declare(strict_types=1);
session_start();

// ── Passwort-Konfiguration ───────────────────────────────────────────────────
// Prefer environment variable `ANLEITUNG_PASSWORD`; fall back to data/settings.json
require_once __DIR__ . '/inc/settings_loader.php';
$_sf = load_settings();
$_anlPass = (string)($_sf['anleitung_password'] ?? '');

// ── CSRF ─────────────────────────────────────────────────────────────────────
if (empty($_SESSION['anl_csrf'])) {
    $_SESSION['anl_csrf'] = bin2hex(random_bytes(16));
}

// ── Logout ───────────────────────────────────────────────────────────────────
if (isset($_POST['anleitung_logout']) && hash_equals($_SESSION['anl_csrf'], (string)($_POST['anl_csrf'] ?? ''))) {
    unset($_SESSION['kgv_anleitung']);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Login verarbeiten ────────────────────────────────────────────────────────
$_anlError = '';
if (isset($_POST['anleitung_pw'])) {
    if (!hash_equals($_SESSION['anl_csrf'], (string)($_POST['anl_csrf'] ?? ''))) {
        $_anlError = 'Ungültige Anfrage.';
    } else {
        $_pwInput = (string)$_POST['anleitung_pw'];
        if ($_anlPass === '') {
            $_valid = false; // kein Passwort in settings.json gesetzt → Login deaktiviert
        } else {
            $_valid = str_starts_with($_anlPass, '$2y$')
                ? password_verify($_pwInput, $_anlPass)
                : hash_equals($_anlPass, $_pwInput);
        }
        if ($_valid) {
            session_regenerate_id(true);
            $_SESSION['kgv_anleitung'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        $_anlError = 'Falsches Passwort.';
    }
}

// ── Zugang prüfen ────────────────────────────────────────────────────────────
$_anlAuth = !empty($_SESSION['kgv_admin']) || !empty($_SESSION['kgv_anleitung']);

if (!$_anlAuth) {
    http_response_code(403);
    ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Handbuch – Zugang</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;
     background:linear-gradient(135deg,#3d6b41 0%,#2d5234 100%);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
.card{background:rgba(255,255,255,0.12);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
      border:1px solid rgba(255,255,255,0.25);border-radius:16px;padding:48px 40px;width:360px;text-align:center;color:#fff}
.logo{font-size:2.5rem;margin-bottom:12px}
h1{font-size:1.3rem;font-weight:700;margin-bottom:6px}
p{font-size:0.88rem;opacity:.75;margin-bottom:28px}
input[type=password]{width:100%;padding:12px 16px;border-radius:8px;border:1px solid rgba(255,255,255,0.3);
  background:rgba(255,255,255,0.15);color:#fff;font-size:1rem;outline:none;margin-bottom:14px}
input[type=password]::placeholder{color:rgba(255,255,255,0.55)}
input[type=password]:focus{border-color:rgba(255,255,255,0.7);background:rgba(255,255,255,0.22)}
button{width:100%;padding:12px;background:#fff;color:#3d6b41;border:none;border-radius:8px;
  font-size:1rem;font-weight:700;cursor:pointer;transition:opacity .15s}
button:hover{opacity:.9}
.err{background:rgba(239,68,68,0.25);border:1px solid rgba(239,68,68,0.5);border-radius:8px;
     padding:10px 14px;margin-bottom:14px;font-size:0.88rem;color:#ffd5d5}
</style>
</head>
<body>
<div class="card">
  <div class="logo">📗</div>
  <h1>Admin-Handbuch</h1>
  <p>KGV Musterstadt e.V. · Nur für den Vorstand</p>
  <?php if ($_anlError): ?>
  <div class="err"><?= htmlspecialchars($_anlError) ?></div>
  <?php endif; ?>
  <form method="POST">
    <input type="hidden" name="anl_csrf" value="<?= htmlspecialchars($_SESSION['anl_csrf']) ?>">
    <input type="password" name="anleitung_pw" placeholder="Passwort eingeben" autofocus>
    <button type="submit">Handbuch öffnen →</button>
  </form>
</div>
</body>
</html>
<?php
    exit;
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin-Handbuch – KGV Musterstadt e.V.</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--green:#3d6b41;--green-light:#7cb342;--green-bg:#f0f4ee;--text:#2d3e2d;--gray:#5a6c5a;--border:#d4e6c3;--yellow-bg:#fffde7;--yellow-border:#f9a825;--red-bg:#ffebee;--red-border:#ef9a9a;--blue-bg:#e3f2fd;--blue-border:#90caf9}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--green-bg);color:var(--text);line-height:1.6}

.layout{display:flex;min-height:100vh}
.sidebar{width:280px;background:var(--green);position:sticky;top:0;height:100vh;overflow-y:auto;flex-shrink:0}
.sidebar-header{padding:20px 20px 14px;border-bottom:1px solid rgba(255,255,255,0.15)}
.sidebar-logo{font-size:1.1rem;font-weight:700;color:#fff;display:flex;align-items:center;gap:8px}
.sidebar-logo img{height:28px;filter:brightness(0) invert(1)}
.sidebar-sub{font-size:0.72rem;color:rgba(255,255,255,0.55);margin-top:4px}
.sidebar nav{padding:10px 0}
.nav-section{padding:10px 20px 3px;font-size:0.66rem;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,0.4);font-weight:600}
.nav-link{display:block;padding:7px 20px;color:rgba(255,255,255,0.78);text-decoration:none;font-size:0.85rem;transition:all .15s;border-left:3px solid transparent}
.nav-link:hover{background:rgba(255,255,255,0.1);color:#fff;border-left-color:var(--green-light)}
.nav-link.active{background:rgba(255,255,255,0.15);color:#fff;border-left-color:#fff}
.nav-link .num{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;background:rgba(255,255,255,0.18);border-radius:50%;font-size:0.7rem;margin-right:7px;font-weight:700;flex-shrink:0}

.main{flex:1;padding:40px;max-width:860px}

.section{margin-bottom:60px;scroll-margin-top:24px}
.section-header{display:flex;align-items:center;gap:14px;margin-bottom:22px;padding-bottom:14px;border-bottom:2px solid var(--border)}
.section-icon{width:44px;height:44px;background:var(--green);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0}
.section-title{font-size:1.35rem;font-weight:700;color:var(--green)}
.section-desc{color:var(--gray);font-size:0.88rem;margin-top:2px}

.steps{display:flex;flex-direction:column;gap:14px;margin:18px 0}
.step{display:flex;gap:14px;align-items:flex-start}
.step-num{width:30px;height:30px;background:var(--green);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.88rem;flex-shrink:0;margin-top:2px}
.step-content{flex:1}
.step-title{font-weight:600;margin-bottom:3px}
.step-text{color:var(--gray);font-size:0.88rem}

.tip-box,.warn-box,.info-box{border-radius:10px;padding:13px 16px;margin:14px 0;font-size:0.88rem;display:flex;gap:12px;align-items:flex-start}
.tip-box{background:var(--yellow-bg);border:1px solid var(--yellow-border)}
.warn-box{background:var(--red-bg);border:1px solid var(--red-border)}
.info-box{background:var(--blue-bg);border:1px solid var(--blue-border)}
.box-icon{font-size:1.1rem;flex-shrink:0;margin-top:1px}
.box-text strong{display:block;margin-bottom:3px}

.mock-screen{background:#fff;border:1px solid var(--border);border-radius:12px;padding:18px;margin:14px 0;font-size:0.85rem}
.mock-screen-title{font-size:0.7rem;text-transform:uppercase;letter-spacing:.1em;color:var(--gray);margin-bottom:10px;font-weight:600}
.mock-nav{background:#3d6b41;border-radius:8px;padding:7px 12px;display:flex;align-items:center;gap:4px;margin-bottom:12px;font-size:0.8rem;flex-wrap:wrap}
.mock-nav-logo{color:#fff;font-weight:700;margin-right:8px;font-size:0.82rem}
.mock-nav-sep{width:1px;height:16px;background:rgba(255,255,255,0.25);margin:0 6px}
.mock-nav-item{color:rgba(255,255,255,0.7);padding:4px 10px;border-radius:5px;font-weight:500}
.mock-nav-item.active{background:rgba(255,255,255,0.2);color:#fff;font-weight:700}
.mock-nav-right{margin-left:auto;display:flex;gap:4px}
.mock-tabs{display:flex;gap:3px;background:#f5f7f2;border-radius:8px;padding:4px;margin-bottom:10px;flex-wrap:wrap}
.mock-tab{padding:5px 11px;border-radius:6px;font-size:0.78rem;color:var(--gray);cursor:default}
.mock-tab.active{background:var(--green);color:#fff;font-weight:600}
.mock-tab .badge{display:inline-block;background:#e53935;color:#fff;border-radius:10px;padding:1px 5px;font-size:0.62rem;margin-left:3px;font-weight:700}
.mock-btn{display:inline-block;padding:5px 12px;border-radius:6px;font-size:0.8rem;font-weight:600;cursor:default;margin:2px}
.mock-btn.green{background:var(--green);color:#fff}
.mock-btn.red{background:#c62828;color:#fff}
.mock-btn.gray{background:#e0e0e0;color:#555}
.mock-btn.yellow{background:#f9a825;color:#fff}
.mock-btn.outline{background:#fff;color:var(--green);border:1px solid var(--green)}
.mock-btn.blue{background:#1565c0;color:#fff}
.mock-card{background:#f9fbf7;border:1px solid var(--border);border-radius:8px;padding:13px;margin-bottom:8px}
.mock-card-title{font-weight:600;margin-bottom:5px;font-size:0.88rem}
.mock-label{display:inline-block;padding:2px 8px;border-radius:4px;font-size:0.72rem;font-weight:600;margin-right:5px}
.mock-label.new{background:#e8f5e9;color:#2e7d32}
.mock-label.pending{background:#fff8e1;color:#e65100}
.mock-label.confirmed{background:#e3f2fd;color:#1565c0}
.mock-label.rejected{background:#ffebee;color:#c62828}
.mock-label.paid{background:#e8f5e9;color:#1b5e20}
.mock-field{background:#f5f7f2;border:1px solid var(--border);border-radius:6px;padding:7px 11px;font-size:0.82rem;color:var(--gray);margin:3px 0}
.mock-stat{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px 16px;text-align:center;flex:1;min-width:100px}
.mock-stat-n{font-size:1.6rem;font-weight:700;color:var(--green)}
.mock-stat-l{font-size:0.72rem;color:var(--gray);margin-top:2px}
.mock-stats{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap}

.cols{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:14px 0}
.overview-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px;margin:18px 0}
.overview-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px;text-align:center}
.overview-card .oc-icon{font-size:1.8rem;margin-bottom:6px}
.overview-card .oc-title{font-weight:600;font-size:0.88rem;color:var(--green);margin-bottom:3px}
.overview-card .oc-text{font-size:0.78rem;color:var(--gray)}

.url{display:inline-block;background:#fff;border:1px solid var(--border);border-radius:6px;padding:3px 10px;font-family:monospace;font-size:0.85rem;color:var(--green);margin:3px 0}
h3{font-size:0.98rem;font-weight:700;color:var(--text);margin:18px 0 8px}
p{margin-bottom:10px;color:var(--gray)}
ul{margin:6px 0 10px 20px;color:var(--gray);font-size:0.88rem}
ul li{margin-bottom:3px}

.progress-bar{background:#f0f4ee;border-radius:4px;height:8px;margin-top:4px}
.progress-fill{background:#4caf50;border-radius:4px;height:8px}
.progress-fill.warn{background:#ff9800}

@media print{.sidebar{display:none}.main{max-width:100%;padding:20px}.section{page-break-inside:avoid}}
@media(max-width:768px){.layout{flex-direction:column}.sidebar{width:100%;height:auto;position:relative}.main{padding:20px}.cols{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="layout">

<aside class="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">
      <img src="/images/logo.png" alt="KGV Musterstadt">
      KGV Musterstadt e.V.
    </div>
    <div class="sidebar-sub">Admin-Handbuch · Nur für den Vorstand</div>
    <form method="POST" style="margin-top:10px">
      <input type="hidden" name="anl_csrf" value="<?= htmlspecialchars($_SESSION['anl_csrf']) ?>">
      <button name="anleitung_logout" type="submit"
        style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.25);color:rgba(255,255,255,0.75);
               padding:5px 12px;border-radius:6px;font-size:0.76rem;cursor:pointer;width:100%;text-align:left">
        🔓 Abmelden
      </button>
    </form>
  </div>
  <nav>
    <div class="nav-section">Einstieg</div>
    <a class="nav-link" href="#ueberblick"><span class="num">0</span>Übersicht</a>
    <a class="nav-link" href="#login"><span class="num">1</span>Einloggen &amp; Navigation</a>

    <div class="nav-section">📊 Backoffice – Tagesgeschäft</div>
    <a class="nav-link" href="#dashboard"><span class="num">2</span>Dashboard</a>
    <a class="nav-link" href="#buchungen"><span class="num">3</span>Buchungen verwalten</a>
    <a class="nav-link" href="#buchung-manuell"><span class="num">4</span>Buchung manuell anlegen</a>
    <a class="nav-link" href="#kontakt"><span class="num">5</span>Kontaktanfragen</a>
    <a class="nav-link" href="#kalender"><span class="num">6</span>Kalender</a>
    <a class="nav-link" href="#kassenbuch"><span class="num">7</span>Kassenbuch-Export</a>

    <div class="nav-section">👥 Backoffice – Mitglieder</div>
    <a class="nav-link" href="#mitglieder"><span class="num">8</span>Mitglieder &amp; Typen</a>
    <a class="nav-link" href="#beitraege"><span class="num">9</span>Mitgliedsbeiträge</a>
    <a class="nav-link" href="#gemeinschaft"><span class="num">10</span>Gemeinschaftsarbeit</a>
    <a class="nav-link" href="#geburtstage"><span class="num">11</span>Geburtstage</a>
    <a class="nav-link" href="#nachrichten"><span class="num">12</span>Nachrichten</a>
    <a class="nav-link" href="#massennachricht"><span class="num">13</span>Massen-Nachricht</a>
    <a class="nav-link" href="#aktivitaet"><span class="num">14</span>Aktivitätsprotokoll</a>

    <div class="nav-section">✏️ Website bearbeiten</div>
    <a class="nav-link" href="#cms-start"><span class="num">15</span>CMS öffnen</a>
    <a class="nav-link" href="#hero"><span class="num">16</span>Startseite / Hero</a>
    <a class="nav-link" href="#banner"><span class="num">17</span>Banner / Laufschrift</a>
    <a class="nav-link" href="#galerie"><span class="num">18</span>Galerie &amp; Bilder</a>
    <a class="nav-link" href="#vereinshaus"><span class="num">19</span>Vereinshaus</a>
    <a class="nav-link" href="#vorstand"><span class="num">20</span>Vorstand</a>
    <a class="nav-link" href="#termine"><span class="num">21</span>Termine</a>
    <a class="nav-link" href="#links"><span class="num">22</span>Links</a>
    <a class="nav-link" href="#preise"><span class="num">23</span>Preise</a>
    <a class="nav-link" href="#sperrtage"><span class="num">24</span>Sperrtage</a>
    <a class="nav-link" href="#email-vorlagen"><span class="num">25</span>E-Mail-Vorlagen</a>
    <a class="nav-link" href="#sektionen"><span class="num">26</span>Sektionen</a>

    <div class="nav-section">⚙️ Einstellungen</div>
    <a class="nav-link" href="#einstellungen"><span class="num">27</span>Allg. Einstellungen</a>
    <a class="nav-link" href="#wartung"><span class="num">28</span>Wartungsmodus</a>
    <a class="nav-link" href="#passwort"><span class="num">29</span>Passwort ändern</a>

    <div class="nav-section">Hilfe</div>
    <a class="nav-link" href="#tipps"><span class="num">💡</span>Häufige Fragen</a>
  </nav>
</aside>

<main class="main">

<!-- 0. ÜBERSICHT -->
<div class="section" id="ueberblick">
  <div class="section-header">
    <div class="section-icon">🗺️</div>
    <div>
      <div class="section-title">Übersicht: Was kann ich alles verwalten?</div>
      <div class="section-desc">Alle Funktionen auf einen Blick</div>
    </div>
  </div>

  <p>Als Vorstand habt ihr Zugriff auf drei Bereiche: das <strong>Backoffice</strong> (Buchungen, Mitglieder, Tagesgeschäft), den <strong>Mitgliederbereich</strong> (euer eigenes Profil und interne Kommunikation) und den <strong>Website-Editor</strong> (alle Inhalte der öffentlichen Website pflegen). Welche Bereiche ihr seht, hängt von eurer Rolle ab.</p>

  <div class="overview-grid">
    <div class="overview-card"><div class="oc-icon">📊</div><div class="oc-title">Dashboard</div><div class="oc-text">Schnellübersicht: offene Buchungen, Einnahmen, Geburtstage, letzte Aktionen</div></div>
    <div class="overview-card"><div class="oc-icon">📋</div><div class="oc-title">Buchungen</div><div class="oc-text">Anfragen bestätigen oder ablehnen — E-Mails gehen automatisch raus</div></div>
    <div class="overview-card"><div class="oc-icon">📬</div><div class="oc-title">Kontaktanfragen</div><div class="oc-text">Nachrichten aus dem Kontaktformular direkt im Panel beantworten</div></div>
    <div class="overview-card"><div class="oc-icon">📅</div><div class="oc-title">Kalender</div><div class="oc-text">Monatsübersicht aller Buchungen auf einen Blick</div></div>
    <div class="overview-card"><div class="oc-icon">💶</div><div class="oc-title">Kassenbuch-Export</div><div class="oc-text">Jahresübersicht aller bezahlten Buchungen als Excel-CSV</div></div>
    <div class="overview-card"><div class="oc-icon">🌱</div><div class="oc-title">Mitglieder</div><div class="oc-text">Zugänge anlegen, Rollen vergeben, Mitgliedstypen verwalten</div></div>
    <div class="overview-card"><div class="oc-icon">💰</div><div class="oc-title">Mitgliedsbeiträge</div><div class="oc-text">Jahresbeiträge pro Mitglied erfassen und Zahlungsstand verfolgen</div></div>
    <div class="overview-card"><div class="oc-icon">🔨</div><div class="oc-title">Gemeinschaftsarbeit</div><div class="oc-text">Geleistete Stunden eintragen und Fortschritt pro Mitglied verfolgen</div></div>
    <div class="overview-card"><div class="oc-icon">💬</div><div class="oc-title">Nachrichten</div><div class="oc-text">Internes Postfach — Mitglieder schreiben, Vorstand antwortet direkt</div></div>
    <div class="overview-card"><div class="oc-icon">📢</div><div class="oc-title">Massen-Nachricht</div><div class="oc-text">Eine Nachricht an alle aktiven Mitglieder gleichzeitig senden</div></div>
    <div class="overview-card"><div class="oc-icon">🎂</div><div class="oc-title">Geburtstage</div><div class="oc-text">Geburtstage der Mitglieder im Dashboard im Blick behalten</div></div>
    <div class="overview-card"><div class="oc-icon">✏️</div><div class="oc-title">Website-Editor</div><div class="oc-text">Alle Inhalte der öffentlichen Website bearbeiten — kein Programmieren nötig</div></div>
    <div class="overview-card"><div class="oc-icon">📣</div><div class="oc-title">Termine</div><div class="oc-text">Veranstaltungen und Termine auf der Startseite ankündigen</div></div>
    <div class="overview-card"><div class="oc-icon">🚫</div><div class="oc-title">Sperrtage</div><div class="oc-text">Tage blockieren, an denen das Vereinshaus nicht buchbar ist</div></div>
  </div>

  <div class="info-box">
    <div class="box-icon">🔗</div>
    <div class="box-text">
      <strong>Die wichtigsten Adressen:</strong>
      <span class="url">kgv461.de/mitglieder.php</span> → Euer persönlicher Login (Mitgliederbereich + Admin)<br>
      <span class="url">kgv461.de/intern/</span> → Direktzugang Backoffice (Notfall-Passwort)<br>
      <span class="url">kgv461.de/intern/content.php</span> → Website-Editor
    </div>
  </div>
</div>

<!-- 1. LOGIN & NAVIGATION -->
<div class="section" id="login">
  <div class="section-header">
    <div class="section-icon">🔐</div>
    <div>
      <div class="section-title">1. Einloggen &amp; Navigation</div>
      <div class="section-desc">Wie ihr euch anmeldet und zwischen den Bereichen wechselt</div>
    </div>
  </div>

  <h3>Login-Wege</h3>
  <div class="cols">
    <div class="mock-card">
      <div class="mock-card-title">👤 Als Vorstandsmitglied (empfohlen)</div>
      <p style="margin:0;font-size:0.85rem;color:var(--gray)">Über <span class="url">kgv461.de/mitglieder.php</span> mit eurem persönlichen Passwort einloggen. Ihr seht dann „Mein Bereich", „Backoffice" und „Website" je nach eurer Rolle.</p>
    </div>
    <div class="mock-card">
      <div class="mock-card-title">🔑 SuperAdmin (Notfall)</div>
      <p style="margin:0;font-size:0.85rem;color:var(--gray)">Über <span class="url">kgv461.de/intern/</span> mit dem Notfall-Passwort. Hat Zugriff auf alles, aber kein persönliches Profil.</p>
    </div>
  </div>

  <h3>Die neue Navigation</h3>
  <p>Nach dem Einloggen erscheint oben auf jeder Seite eine grüne Navigationsleiste. Sie zeigt euch immer genau die Bereiche, auf die ihr Zugriff habt:</p>

  <div class="mock-screen">
    <div class="mock-screen-title">Navigationsleiste (Beispiel: Vorstand eingeloggt)</div>
    <div class="mock-nav">
      <span class="mock-nav-logo">🌿</span>
      <div class="mock-nav-sep"></div>
      <span class="mock-nav-item">👤 Mein Bereich</span>
      <span class="mock-nav-item active">📊 Backoffice</span>
      <span class="mock-nav-item">✏️ Website</span>
      <div class="mock-nav-right">
        <span class="mock-nav-item">Nicole</span>
        <div class="mock-nav-sep"></div>
        <span class="mock-nav-item">🌐</span>
        <span class="mock-nav-item">Abmelden</span>
      </div>
    </div>
    <div style="font-size:0.8rem;color:var(--gray)">Der aktuell aktive Bereich ist weiß hervorgehoben.</div>
  </div>

  <ul>
    <li><strong>👤 Mein Bereich</strong> — euer persönliches Profil, Pinnwand, Dokumente, Nachrichten</li>
    <li><strong>📊 Backoffice</strong> — Buchungen, Mitglieder, Tagesgeschäft</li>
    <li><strong>✏️ Website</strong> — Inhalte der öffentlichen Website bearbeiten (nur Vorstand/Web)</li>
    <li><strong>🌐</strong> — öffentliche Website in neuem Tab ansehen</li>
    <li><strong>Abmelden</strong> — Session beenden</li>
  </ul>

  <div class="tip-box">
    <div class="box-icon">💡</div>
    <div class="box-text">
      <strong>Lesezeichen anlegen</strong>
      Speichert <code>kgv461.de/mitglieder.php</code> als Lesezeichen — von dort kommt ihr per Klick überall hin.
    </div>
  </div>

  <div class="warn-box">
    <div class="box-icon">⚠️</div>
    <div class="box-text">
      <strong>Session läuft ab</strong>
      Nach 60 Minuten ohne Aktivität werdet ihr automatisch ausgeloggt. Einfach neu einloggen — alle Daten bleiben gespeichert.
    </div>
  </div>
</div>

<!-- 2. DASHBOARD -->
<div class="section" id="dashboard">
  <div class="section-header">
    <div class="section-icon">📊</div>
    <div>
      <div class="section-title">2. Dashboard</div>
      <div class="section-desc">Tagesübersicht auf einen Blick</div>
    </div>
  </div>

  <p>Das Dashboard ist die Startseite des Backoffice. Es zeigt die wichtigsten Informationen auf einen Blick — ohne dass ihr in verschiedenen Tabs suchen müsst.</p>

  <div class="mock-screen">
    <div class="mock-screen-title">Backoffice → Tab „📊 Dashboard"</div>
    <div class="mock-nav">
      <span class="mock-nav-logo">🌿</span>
      <div class="mock-nav-sep"></div>
      <span class="mock-nav-item active">📊 Backoffice</span>
      <span class="mock-nav-item">✏️ Website</span>
      <div class="mock-nav-right"><span class="mock-nav-item">🌐</span><span class="mock-nav-item">Abmelden</span></div>
    </div>
    <div class="mock-tabs">
      <div class="mock-tab active">📊 Dashboard</div>
      <div class="mock-tab">📋 Buchungen</div>
      <div class="mock-tab">📬 Kontaktanfragen</div>
      <div class="mock-tab">📅 Kalender</div>
      <div class="mock-tab">👥 Mitglieder</div>
    </div>
    <div class="mock-stats">
      <div class="mock-stat"><div class="mock-stat-n" style="color:#e65100">2</div><div class="mock-stat-l">Offene Buchungen</div></div>
      <div class="mock-stat"><div class="mock-stat-n" style="color:#1565c0">1</div><div class="mock-stat-l">Neue Kontaktanfragen</div></div>
      <div class="mock-stat"><div class="mock-stat-n" style="color:#6a1b9a">3</div><div class="mock-stat-l">Anfragen &amp; Nachrichten</div></div>
      <div class="mock-stat"><div class="mock-stat-n">23</div><div class="mock-stat-l">Aktive Mitglieder</div></div>
      <div class="mock-stat"><div class="mock-stat-n" style="color:#e65100">1.820 €</div><div class="mock-stat-l">Einnahmen Mrz 2026</div></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:0.8rem">
      <div style="background:#f9fbf7;border:1px solid var(--border);border-radius:8px;padding:12px">
        <div style="font-weight:700;margin-bottom:8px;color:var(--green)">📅 Nächste 14 Tage</div>
        <div style="border-bottom:1px solid var(--border);padding-bottom:6px;margin-bottom:6px"><strong>25.03.</strong> — Maria Muster · Geburtstag</div>
        <div><strong>01.04.</strong> — Klaus Berger · Vereinsfest</div>
      </div>
      <div style="background:#f9fbf7;border:1px solid var(--border);border-radius:8px;padding:12px">
        <div style="font-weight:700;margin-bottom:8px;color:var(--green)">🎂 Geburtstage (30 Tage)</div>
        <div style="border-bottom:1px solid var(--border);padding-bottom:6px;margin-bottom:6px">🎂 <strong>28.03.</strong> Anna Schmidt</div>
        <div>🎂 <strong>05.04.</strong> Peter Wagner</div>
      </div>
    </div>
  </div>

  <h3>Was zeigt das Dashboard?</h3>
  <div class="cols">
    <div class="mock-card"><div class="mock-card-title">📊 Schnell-Statistiken</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Fünf Kennzahlen: Offene Buchungen, Neue Kontaktanfragen, Offene Nachrichten, Aktive Mitglieder, Monats-Einnahmen.</p></div>
    <div class="mock-card"><div class="mock-card-title">📅 Nächste 14 Tage</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Alle bestätigten Buchungen der nächsten zwei Wochen — mit Datum, Name und Anlass.</p></div>
    <div class="mock-card"><div class="mock-card-title">📈 Einnahmen pro Monat</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Balkendiagramm der letzten 6 Monate — zeigt nur tatsächlich bezahlte Buchungen.</p></div>
    <div class="mock-card"><div class="mock-card-title">🎂 Geburtstage</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Mitglieder mit Geburtstag in den nächsten 30 Tagen — sofern der Geburtstag im Profil hinterlegt ist.</p></div>
    <div class="mock-card"><div class="mock-card-title">📋 Letzte Aktionen</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Die 15 neuesten Aktionen im System: wer hat wann was gemacht (z.B. „Buchung bestätigt", „Mitglied genehmigt").</p></div>
  </div>
</div>

<!-- 3. BUCHUNGEN -->
<div class="section" id="buchungen">
  <div class="section-header">
    <div class="section-icon">📋</div>
    <div>
      <div class="section-title">3. Buchungsanfragen verwalten</div>
      <div class="section-desc">Anfragen bestätigen, ablehnen, Zahlung markieren</div>
    </div>
  </div>

  <p>Wenn jemand über die Website eine Buchungsanfrage stellt, erhaltet ihr eine E-Mail-Benachrichtigung. Im Backoffice → Tab „📋 Buchungen" seht ihr alle Anfragen.</p>

  <div class="mock-screen">
    <div class="mock-screen-title">Tab „📋 Buchungen" — neue Anfrage</div>
    <div class="mock-nav"><span class="mock-nav-logo">🌿</span><div class="mock-nav-sep"></div><span class="mock-nav-item active">📊 Backoffice</span><div class="mock-nav-right"><span class="mock-nav-item">Abmelden</span></div></div>
    <div class="mock-tabs">
      <div class="mock-tab">📊 Dashboard</div>
      <div class="mock-tab active">📋 Buchungen <span class="badge">1</span></div>
      <div class="mock-tab">📬 Kontaktanfragen</div>
      <div class="mock-tab">📅 Kalender</div>
      <div class="mock-tab">👥 Mitglieder</div>
    </div>
    <div class="mock-card">
      <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:8px">
        <div>
          <span class="mock-label pending">⏳ Ausstehend</span>
          <div class="mock-card-title">Max Mustermann — 25.04.2026</div>
          <div style="font-size:0.8rem;color:var(--gray)">Geburtstag · 30 Personen · max@example.de</div>
        </div>
        <div>
          <div class="mock-btn green">✓ Bestätigen</div>
          <div class="mock-btn red">✗ Ablehnen</div>
        </div>
      </div>
    </div>
  </div>

  <h3>Buchung bestätigen</h3>
  <div class="steps">
    <div class="step"><div class="step-num">1</div><div class="step-content"><div class="step-title">Anfrage prüfen</div><div class="step-text">Datum, Name, Anlass und Personenzahl ansehen. Prüfen ob der Termin frei ist (Kalender-Tab hilft).</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-content"><div class="step-title">„✓ Bestätigen" klicken</div><div class="step-text">Optional eine persönliche Nachricht eingeben (z.B. Hinweis zum Schlüssel). Dann „Bestätigen &amp; E-Mail senden".</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-content"><div class="step-title">E-Mail geht automatisch raus</div><div class="step-text">Der Buchende bekommt sofort eine Bestätigungs-E-Mail mit Zahlungsinformationen (IBAN, Betrag, Verwendungszweck).</div></div></div>
  </div>

  <h3>Zahlung markieren &amp; Notiz hinterlegen</h3>
  <div class="mock-screen">
    <div class="mock-screen-title">Bestätigte Buchung — verfügbare Aktionen</div>
    <div class="mock-card">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px">
        <div><span class="mock-label confirmed">✓ Bestätigt</span><div class="mock-card-title">Maria Muster — 15.05.2026</div></div>
        <div>
          <div class="mock-btn yellow">✏️ Notiz</div>
          <div class="mock-btn green">💶 Zahlung</div>
          <div class="mock-btn gray">↩ Kaution</div>
          <div class="mock-btn red">🗑 Stornieren</div>
        </div>
      </div>
    </div>
  </div>

  <div class="cols">
    <div class="mock-card"><div class="mock-card-title">✏️ Notiz</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Interne Notiz hinterlegen: z.B. „Schlüssel abgeholt", „Kaution bar gezahlt". Nur im Admin sichtbar.</p></div>
    <div class="mock-card"><div class="mock-card-title">💶 Zahlung</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Sobald die Überweisung auf dem Vereinskonto eingegangen ist, hier als bezahlt markieren.</p></div>
    <div class="mock-card"><div class="mock-card-title">↩ Kaution</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Nach der Veranstaltung: Kaution als zurückgegeben markieren.</p></div>
    <div class="mock-card"><div class="mock-card-title">🗑 Stornieren</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Bestätigte Buchung stornieren. Nach Sicherheitsabfrage endgültig.</p></div>
  </div>

  <div class="info-box">
    <div class="box-icon">ℹ️</div>
    <div class="box-text">
      <strong>Status-Übersicht:</strong><br>
      <span class="mock-label pending">⏳ Ausstehend</span> Neue Anfrage, noch nicht bearbeitet<br>
      <span class="mock-label confirmed">✓ Bestätigt</span> Bestätigt, Zahlung noch ausstehend<br>
      <span class="mock-label paid">💶 Bezahlt</span> Zahlung eingegangen<br>
      <span class="mock-label rejected">✗ Abgelehnt</span> Absage wurde verschickt
    </div>
  </div>
</div>

<!-- 4. BUCHUNG MANUELL -->
<div class="section" id="buchung-manuell">
  <div class="section-header">
    <div class="section-icon">📅</div>
    <div>
      <div class="section-title">4. Buchung manuell anlegen</div>
      <div class="section-desc">Telefonische oder persönliche Buchungen direkt eintragen</div>
    </div>
  </div>

  <p>Nicht alle Buchungen kommen über das Online-Formular. Wenn jemand persönlich oder telefonisch bucht, könnt ihr die Buchung direkt im Admin-Panel anlegen.</p>

  <div class="steps">
    <div class="step"><div class="step-num">1</div><div class="step-content"><div class="step-title">„📅 Buchung manuell eintragen" klicken</div><div class="step-text">Den Button findet ihr oben im Tab „📋 Buchungen". Es öffnet sich ein Formular mit Kalender.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-content"><div class="step-title">Datum(en) im Kalender auswählen</div><div class="step-text">Klickt auf die gewünschten Tage — sie werden grün markiert. Bestehende Buchungen (blau/orange) und Sperrtage (grau) sind zur Orientierung eingeblendet, schränken euch aber nicht ein.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-content"><div class="step-title">Name, Anlass, Personenzahl und E-Mail eingeben</div><div class="step-text">Die E-Mail ist optional — nur nötig wenn ihr später Nachrichten senden wollt.</div></div></div>
    <div class="step"><div class="step-num">4</div><div class="step-content"><div class="step-title">„Buchung anlegen" klicken</div><div class="step-text">Die Buchung erscheint sofort als „✓ Bestätigt" in der Buchungsliste. Keine E-Mail wird automatisch verschickt.</div></div></div>
  </div>

  <div class="tip-box">
    <div class="box-icon">💡</div>
    <div class="box-text">
      <strong>Zahlung und Notiz nachtragen</strong>
      Nach dem Anlegen könnt ihr wie gewohnt „💶 Zahlung" und „✏️ Notiz" nutzen, um Zahlungseingang und Absprachen zu dokumentieren.
    </div>
  </div>
</div>

<!-- 5. KONTAKTANFRAGEN -->
<div class="section" id="kontakt">
  <div class="section-header">
    <div class="section-icon">📬</div>
    <div>
      <div class="section-title">5. Kontaktanfragen</div>
      <div class="section-desc">Nachrichten aus dem Kontaktformular der Website</div>
    </div>
  </div>

  <p>Wenn jemand das Kontaktformular auf der Website ausfüllt, landet die Nachricht im Backoffice → Tab „📬 Kontaktanfragen". Ihr könnt direkt im Panel antworten — ohne E-Mail-Programm öffnen zu müssen.</p>

  <div class="steps">
    <div class="step"><div class="step-num">1</div><div class="step-content"><div class="step-title">Roten Badge beachten</div><div class="step-text">Die Zahl neben „Kontaktanfragen" zeigt ungelesene Nachrichten an.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-content"><div class="step-title">„▼ Nachricht lesen" klicken</div><div class="step-text">Die vollständige Nachricht klappt auf und wird als gelesen markiert.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-content"><div class="step-title">„✉️ Direkt antworten" klicken</div><div class="step-text">Antwortformular direkt im Panel. Tippt eure Antwort und klickt „Absenden" — die E-Mail geht automatisch raus.</div></div></div>
    <div class="step"><div class="step-num">4</div><div class="step-content"><div class="step-title">Erneut antworten</div><div class="step-text">Falls eine Rückfrage kommt: „↩ Erneut antworten" — der Gesprächsverlauf bleibt gespeichert.</div></div></div>
  </div>
</div>

<!-- 6. KALENDER -->
<div class="section" id="kalender">
  <div class="section-header">
    <div class="section-icon">📅</div>
    <div>
      <div class="section-title">6. Kalenderansicht</div>
      <div class="section-desc">Monatsübersicht aller Buchungen</div>
    </div>
  </div>

  <p>Der Kalender im Tab „📅 Kalender" zeigt auf einen Blick welche Tage belegt oder frei sind. Grün = bestätigt, Orange = ausstehend, Grau = Sperrtag. Ein Klick auf einen belegten Tag springt direkt zur Buchungskarte.</p>

  <div class="tip-box">
    <div class="box-icon">💡</div>
    <div class="box-text">Nutzt den Kalender als erste Prüfung bevor ihr eine manuelle Buchung anlegt — so seht ihr sofort ob der Wunschtermin noch frei ist.</div>
  </div>
</div>

<!-- 7. KASSENBUCH -->
<div class="section" id="kassenbuch">
  <div class="section-header">
    <div class="section-icon">💶</div>
    <div>
      <div class="section-title">7. Kassenbuch-Export</div>
      <div class="section-desc">Alle bezahlten Buchungen als Excel-Datei exportieren</div>
    </div>
  </div>

  <p>Der Kassenbuch-Export erstellt eine CSV-Datei mit allen Buchungen, die in einem Jahr als bezahlt markiert wurden — ideal für Jahresberichte, Steuerzwecke oder die Kassenprüfung.</p>

  <div class="steps">
    <div class="step"><div class="step-num">1</div><div class="step-content"><div class="step-title">Backoffice → Tab „📋 Buchungen"</div><div class="step-text">Ganz oben rechts findet ihr den Button <strong>„💶 Kassenbuch (CSV)"</strong>.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-content"><div class="step-title">Download startet sofort</div><div class="step-text">Die Datei heißt z.B. <code>kassenbuch_2026.csv</code> und lässt sich direkt in Excel oder LibreOffice öffnen.</div></div></div>
  </div>

  <h3>Was enthält die Datei?</h3>
  <ul>
    <li>Bezahlt am (Datum der Zahlungsmarkierung)</li>
    <li>Veranstaltungsdatum</li>
    <li>Name des Buchenden &amp; Anlass</li>
    <li>Personenzahl, Miete (€), Extras (€), Gesamtbetrag</li>
    <li>Kaution-Status (ausstehend / zurückgegeben)</li>
    <li>Interne Notiz</li>
    <li>Letzte Zeile: <strong>Summen</strong> aller Beträge</li>
  </ul>

  <div class="tip-box">
    <div class="box-icon">💡</div>
    <div class="box-text"><strong>Anderes Jahr exportieren:</strong> Die URL <span class="url">/intern/kassenbuch_export.php?year=2025</span> exportiert das Jahr 2025.</div>
  </div>
</div>

<!-- 8. MITGLIEDER -->
<div class="section" id="mitglieder">
  <div class="section-header">
    <div class="section-icon">🌱</div>
    <div>
      <div class="section-title">8. Mitglieder &amp; Typen</div>
      <div class="section-desc">Zugänge anlegen, verwalten und Rollen vergeben</div>
    </div>
  </div>

  <p>Der Tab „👥 Mitglieder" im Backoffice zeigt alle registrierten Mitglieder. Mitglieder registrieren sich selbst auf <span class="url">kgv461.de/mitglieder.php</span> — ihr genehmigt die Anfrage mit einem Klick.</p>

  <h3>Registrierungsanfragen genehmigen</h3>
  <div class="steps">
    <div class="step"><div class="step-num">1</div><div class="step-content"><div class="step-title">Anfrage erscheint oben im Mitglieder-Tab</div><div class="step-text">Ihr seht Name, Parzelle, E-Mail, Telefon und den gewählten Mitgliedstyp.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-content"><div class="step-title">„✅ Genehmigen" klicken</div><div class="step-text">Das Mitglied bekommt eine E-Mail mit einem zufällig generierten Startpasswort. Beim ersten Login wird es aufgefordert, ein eigenes Passwort zu setzen.</div></div></div>
  </div>

  <h3>Aktionen in der Mitglieder-Tabelle</h3>
  <div class="cols">
    <div class="mock-card"><div class="mock-card-title">🔒 / 🔓 Sperren / Entsperren</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Mitglied vorübergehend sperren — kann sich nicht mehr einloggen. Jederzeit entsperrbar.</p></div>
    <div class="mock-card"><div class="mock-card-title">🔑 Passwort zurücksetzen</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Generiert ein neues Zufallspasswort und schickt es per E-Mail ans Mitglied.</p></div>
    <div class="mock-card"><div class="mock-card-title">🗑️ Löschen</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Entfernt das Mitglied endgültig. Nach Sicherheitsabfrage. Nicht rückgängig machbar.</p></div>
    <div class="mock-card"><div class="mock-card-title">🎭 Rollen bearbeiten</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Öffnet ein Inline-Formular: Signatur-Titel, Admin-Rollen per Checkbox, Geburtstag eintragen.</p></div>
  </div>

  <h3>Mitgliedstypen</h3>
  <p>Beim Registrieren wählt jedes Mitglied seinen Typ. Dieser wird in der Tabelle als Badge angezeigt:</p>
  <div class="cols">
    <div class="mock-card"><div class="mock-card-title">🌱 Pächter/in</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Standard-Typ. Hauptpächter der Parzelle.</p></div>
    <div class="mock-card"><div class="mock-card-title">🤝 Pächterpartner</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Partner oder Lebenspartner des Pächters, lebt auf der Parzelle mit.</p></div>
    <div class="mock-card"><div class="mock-card-title">💛 Fördermitglied</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Unterstützt den Verein ohne eigene Parzelle.</p></div>
  </div>

  <h3>Admin-Rollen erklärt</h3>
  <div class="cols">
    <div class="mock-card"><div class="mock-card-title">🌱 Mitglied</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Basisrecht: Dokumente, Nachrichten, Profil. Kein Admin-Zugang.</p></div>
    <div class="mock-card"><div class="mock-card-title">👑 Vorstand</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Volle Admin-Rechte: alles im Backoffice + Website-Editor.</p></div>
    <div class="mock-card"><div class="mock-card-title">📋 Buchung</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Nur Buchungen und Kontaktanfragen verwalten.</p></div>
    <div class="mock-card"><div class="mock-card-title">📝 Schriftführer</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Buchungen, Kontaktanfragen, Mitglieder und Website-Editor.</p></div>
    <div class="mock-card"><div class="mock-card-title">🌐 Web</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Nur der Website-Editor — kein Backoffice.</p></div>
  </div>

  <div class="info-box">
    <div class="box-icon">ℹ️</div>
    <div class="box-text"><strong>CSV-Export &amp; Telefonliste:</strong> Oben im Mitglieder-Tab findet ihr „📥 CSV exportieren" (alle Daten als Tabelle) und „🖨️ Telefonliste" (druckerfreundliche Liste aller Mitglieder mit Einwilligung).</div>
  </div>
</div>

<!-- 9. MITGLIEDSBEITRÄGE -->
<div class="section" id="beitraege">
  <div class="section-header">
    <div class="section-icon">💰</div>
    <div>
      <div class="section-title">9. Mitgliedsbeiträge</div>
      <div class="section-desc">Jahresbeiträge erfassen und Zahlungsstand verfolgen</div>
    </div>
  </div>

  <p>Im Tab „👥 Mitglieder" → Abschnitt „💰 Mitgliedsbeiträge" (nach unten scrollen) könnt ihr für jedes Mitglied den Jahresbeitrag erfassen und den Zahlungsstand pflegen.</p>

  <div class="mock-screen">
    <div class="mock-screen-title">Mitgliedsbeiträge — Jahresübersicht 2026</div>
    <div style="font-size:0.8rem;overflow-x:auto">
      <table style="width:100%;border-collapse:collapse">
        <thead><tr style="background:var(--green);color:#fff"><th style="padding:7px 10px;text-align:left">Parzelle</th><th style="padding:7px 10px;text-align:left">Name</th><th style="padding:7px 10px;text-align:right">Betrag</th><th style="padding:7px 10px;text-align:center">Status</th><th style="padding:7px 10px;text-align:center">Aktion</th></tr></thead>
        <tbody>
          <tr style="border-bottom:1px solid var(--border)"><td style="padding:6px 10px">7</td><td style="padding:6px 10px">Maria Muster</td><td style="padding:6px 10px;text-align:right">120,00 €</td><td style="padding:6px 10px;text-align:center"><span style="background:#e8f5e9;color:#2e7d32;padding:2px 8px;border-radius:10px;font-size:0.72rem;font-weight:600">✓ bezahlt</span></td><td style="padding:6px 10px;text-align:center"><span class="mock-btn outline" style="font-size:0.72rem;padding:3px 8px">✏️</span></td></tr>
          <tr><td style="padding:6px 10px">12</td><td style="padding:6px 10px">Klaus Berger</td><td style="padding:6px 10px;text-align:right">120,00 €</td><td style="padding:6px 10px;text-align:center"><span style="background:#fff8e1;color:#e65100;padding:2px 8px;border-radius:10px;font-size:0.72rem;font-weight:600">⏳ ausstehend</span></td><td style="padding:6px 10px;text-align:center"><span class="mock-btn outline" style="font-size:0.72rem;padding:3px 8px">✏️</span></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="steps">
    <div class="step"><div class="step-num">1</div><div class="step-content"><div class="step-title">„✏️" neben dem Mitglied klicken</div><div class="step-text">Ein Eingabeformular klappt direkt unter der Zeile auf.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-content"><div class="step-title">Betrag eingeben und Zahlungsstand wählen</div><div class="step-text">Optional eine Notiz ergänzen (z.B. „Ratenzahlung vereinbart").</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-content"><div class="step-title">„Speichern" klicken</div><div class="step-text">Die Tabelle aktualisiert sich sofort.</div></div></div>
  </div>

  <p>Mit den Pfeilen ◀ / ▶ oben rechts im Abschnitt könnt ihr das Jahr wechseln.</p>

  <div class="warn-box">
    <div class="box-icon">⚠️</div>
    <div class="box-text"><strong>Kein automatischer Jahresübertrag:</strong> Die Einträge eines Jahres werden nicht ins nächste Jahr kopiert — jedes Jahr müsst ihr die Beträge neu anlegen.</div>
  </div>
</div>

<!-- 10. GEMEINSCHAFTSARBEIT -->
<div class="section" id="gemeinschaft">
  <div class="section-header">
    <div class="section-icon">🔨</div>
    <div>
      <div class="section-title">10. Gemeinschaftsarbeit</div>
      <div class="section-desc">Geleistete Stunden erfassen und Soll-Stunden im Blick behalten</div>
    </div>
  </div>

  <p>Im Tab „👥 Mitglieder" → Abschnitt „🔨 Gemeinschaftsarbeit" verfolgt ihr, wie viele Stunden jedes Mitglied im laufenden Jahr bereits geleistet hat. Die Pflicht-Soll-Stunden sind frei konfigurierbar.</p>

  <div class="mock-screen">
    <div class="mock-screen-title">Gemeinschaftsarbeit 2026 — Übersicht</div>
    <div style="display:flex;gap:10px;margin-bottom:10px;font-size:0.8rem;flex-wrap:wrap">
      <span style="color:var(--green);font-weight:600">Soll: 4h pro Mitglied</span>
      <span style="color:#2e7d32;font-weight:600">✅ 18 erfüllt</span>
      <span style="color:#e65100;font-weight:600">⏳ 5 offen</span>
    </div>
    <div style="font-size:0.8rem">
      <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
        <span style="width:30px;color:var(--gray)">7</span>
        <span style="flex:1">Maria Muster</span>
        <span style="font-weight:700;color:#2e7d32;width:40px;text-align:right">4,0h</span>
        <div style="width:80px"><div class="progress-bar"><div class="progress-fill" style="width:100%"></div></div><div style="font-size:0.65rem;color:var(--gray);text-align:center;margin-top:2px">100%</div></div>
        <div class="mock-btn outline" style="font-size:0.72rem;padding:3px 8px">+</div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;padding:8px 0">
        <span style="width:30px;color:var(--gray)">12</span>
        <span style="flex:1">Klaus Berger</span>
        <span style="font-weight:700;color:#e65100;width:40px;text-align:right">1,5h</span>
        <div style="width:80px"><div class="progress-bar"><div class="progress-fill warn" style="width:37%"></div></div><div style="font-size:0.65rem;color:var(--gray);text-align:center;margin-top:2px">37%</div></div>
        <div class="mock-btn outline" style="font-size:0.72rem;padding:3px 8px">+</div>
      </div>
    </div>
  </div>

  <h3>Stunden eintragen</h3>
  <div class="steps">
    <div class="step"><div class="step-num">1</div><div class="step-content"><div class="step-title">„+" neben dem Mitglied klicken</div><div class="step-text">Ein Eingabeformular klappt auf.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-content"><div class="step-title">Stunden, Datum und optional eine Notiz eingeben</div><div class="step-text">z.B. „2" Stunden, „15.04.2026", Notiz: „Heckenschnitt Einfahrt"</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-content"><div class="step-title">„Speichern" klicken</div><div class="step-text">Der Fortschrittsbalken aktualisiert sich sofort. Einzelne Einträge können über das 🗑-Symbol gelöscht werden.</div></div></div>
  </div>

  <h3>Soll-Stunden anpassen</h3>
  <p>Die Pflicht-Stundenzahl (Standard: 4h) kann jederzeit geändert werden:</p>
  <div class="steps">
    <div class="step"><div class="step-num">1</div><div class="step-content"><div class="step-title">Navigation: „✏️ Website" → Tab „⚙️ Einstellungen"</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-content"><div class="step-title">Abschnitt „🔨 Gemeinschaftsarbeit" suchen</div><div class="step-text">Feld „Soll-Stunden pro Mitglied" ändern und unten „Speichern" klicken.</div></div></div>
  </div>

  <div class="tip-box">
    <div class="box-icon">💡</div>
    <div class="box-text">Oben im Abschnitt seht ihr immer eine Zusammenfassung: wie viele Mitglieder das Soll bereits erfüllt haben und wie viele noch offen sind.</div>
  </div>
</div>

<!-- 11. GEBURTSTAGE -->
<div class="section" id="geburtstage">
  <div class="section-header">
    <div class="section-icon">🎂</div>
    <div>
      <div class="section-title">11. Geburtstage</div>
      <div class="section-desc">Geburtstage der Mitglieder im Blick behalten</div>
    </div>
  </div>

  <p>Mitglieder können im Mitgliederbereich → Tab „Mein Profil" ihren Geburtstag eintragen (Format TT.MM — der Jahrgang wird nicht gespeichert). Im Dashboard erscheinen alle Geburtstage der nächsten 30 Tage automatisch.</p>

  <p>Als Admin könnt ihr den Geburtstag auch über das 🎭-Rollen-Formular eines Mitglieds eintragen oder ändern — z.B. wenn das Mitglied selbst keinen Zugang hat.</p>

  <div class="info-box">
    <div class="box-icon">ℹ️</div>
    <div class="box-text">Wenn kein Geburtstag hinterlegt ist, erscheint das Mitglied nicht in der Geburtstagsliste. Die Eingabe ist freiwillig.</div>
  </div>
</div>

<!-- 12. NACHRICHTEN -->
<div class="section" id="nachrichten">
  <div class="section-header">
    <div class="section-icon">💬</div>
    <div>
      <div class="section-title">12. Nachrichten</div>
      <div class="section-desc">Internes Postfach zwischen Mitgliedern und Vorstand</div>
    </div>
  </div>

  <p>Mitglieder können über ihren Login eine Nachricht an den Vorstand schicken. Ihr findet alle Nachrichten im Tab „👥 Mitglieder" → Abschnitt „💬 Nachrichten" (nach unten scrollen).</p>

  <h3>Auf eine Nachricht antworten</h3>
  <div class="steps">
    <div class="step"><div class="step-num">1</div><div class="step-content"><div class="step-title">Tab „👥 Mitglieder" → nach unten scrollen</div><div class="step-text">Der Abschnitt „💬 Nachrichten" zeigt alle offenen Konversationen. Der Badge oben am Tab zeigt die Anzahl ungelesener Nachrichten.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-content"><div class="step-title">Nachricht aufklappen und „💬 Antworten" klicken</div><div class="step-text">Der vollständige Gesprächsverlauf wird angezeigt.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-content"><div class="step-title">Antwort eintippen und absenden</div><div class="step-text">Die Antwort erscheint sofort im Mitgliederbereich des Empfängers und eine E-Mail-Benachrichtigung wird verschickt.</div></div></div>
  </div>

  <h3>Neue Nachricht an ein Mitglied starten</h3>
  <div class="steps">
    <div class="step"><div class="step-num">1</div><div class="step-content"><div class="step-title">„✉️ Neue Nachricht an Mitglied" klicken</div><div class="step-text">Button oben im Nachrichten-Abschnitt.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-content"><div class="step-title">Mitglied aus Liste wählen, Betreff und Text eingeben</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-content"><div class="step-title">Absenden</div><div class="step-text">Die Nachricht erscheint im Postfach des Mitglieds. Wenn das Mitglied der Kontaktaufnahme zugestimmt hat, erhält es zusätzlich eine E-Mail.</div></div></div>
  </div>
</div>

<!-- 13. MASSEN-NACHRICHT -->
<div class="section" id="massennachricht">
  <div class="section-header">
    <div class="section-icon">📢</div>
    <div>
      <div class="section-title">13. Massen-Nachricht</div>
      <div class="section-desc">Eine Nachricht an alle Mitglieder gleichzeitig senden</div>
    </div>
  </div>

  <p>Mit der Massen-Nachricht könnt ihr alle aktiven Mitglieder auf einmal erreichen — z.B. für Einladungen zur Hauptversammlung, wichtige Ankündigungen oder Erinnerungen.</p>

  <div class="steps">
    <div class="step"><div class="step-num">1</div><div class="step-content"><div class="step-title">Tab „👥 Mitglieder" → „📢 Massen-Nachricht" klicken</div><div class="step-text">Den Button findet ihr oben rechts im Mitglieder-Tab.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-content"><div class="step-title">Betreff und Text eingeben</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-content"><div class="step-title">„Per E-Mail senden?" Checkbox</div><div class="step-text">Wenn aktiviert: jedes Mitglied bekommt zusätzlich eine E-Mail. Wenn deaktiviert: die Nachricht erscheint nur im Mitgliederbereich.</div></div></div>
    <div class="step"><div class="step-num">4</div><div class="step-content"><div class="step-title">„Senden" klicken</div><div class="step-text">Die Nachricht wird an alle aktiven Mitglieder zugestellt.</div></div></div>
  </div>

  <div class="warn-box">
    <div class="box-icon">⚠️</div>
    <div class="box-text"><strong>E-Mails sind nicht rückgängig zu machen!</strong> Lest den Text vor dem Absenden nochmal durch. Bei Bedarf erst ohne E-Mail testen (Checkbox deaktiviert lassen) — die Nachricht erscheint dann nur im Mitgliederbereich.</div>
  </div>
</div>

<!-- 14. AKTIVITÄTSPROTOKOLL -->
<div class="section" id="aktivitaet">
  <div class="section-header">
    <div class="section-icon">📋</div>
    <div>
      <div class="section-title">14. Aktivitätsprotokoll</div>
      <div class="section-desc">Was zuletzt im System passiert ist</div>
    </div>
  </div>

  <p>Im Dashboard → Abschnitt „📋 Letzte Aktionen" seht ihr die 15 neuesten Aktionen im System: wer hat wann was gemacht.</p>

  <div class="mock-screen">
    <div class="mock-screen-title">Letzte Aktionen (Beispiel)</div>
    <div style="font-size:0.8rem">
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0f4ee"><div><strong>Buchung bestätigt</strong><br><span style="color:var(--gray)">Maria Muster · Geburtstag 25.04.2026</span><br><span style="font-size:0.7rem;color:#9aaa9a">Erika Musterfrau</span></div><span style="color:#9aaa9a;white-space:nowrap;font-size:0.7rem">2026-03-20 14:32</span></div>
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0f4ee"><div><strong>Arbeitsstunden eingetragen</strong><br><span style="color:var(--gray)">Klaus Berger · 2,0h am 18.03.2026</span><br><span style="font-size:0.7rem;color:#9aaa9a">Erika Musterfrau</span></div><span style="color:#9aaa9a;white-space:nowrap;font-size:0.7rem">2026-03-18 10:15</span></div>
      <div style="display:flex;justify-content:space-between;padding:6px 0"><div><strong>Mitglied genehmigt</strong><br><span style="color:var(--gray)">Anna Schmidt</span><br><span style="font-size:0.7rem;color:#9aaa9a">SuperAdmin</span></div><span style="color:#9aaa9a;white-space:nowrap;font-size:0.7rem">2026-03-15 09:02</span></div>
    </div>
  </div>

  <div class="info-box">
    <div class="box-icon">ℹ️</div>
    <div class="box-text">Das Protokoll hilft bei Fragen wie: <em>„Wann wurde diese Buchung bestätigt?"</em> oder <em>„Wer hat das Passwort zurückgesetzt?"</em> — es werden die letzten 200 Aktionen gespeichert.</div>
  </div>
</div>

<!-- 15. CMS -->
<div class="section" id="cms-start">
  <div class="section-header">
    <div class="section-icon">✏️</div>
    <div>
      <div class="section-title">15. Website-Editor öffnen</div>
      <div class="section-desc">Wie ihr in den Editor gelangt</div>
    </div>
  </div>

  <p>Der Website-Editor erlaubt es, alle Inhalte der öffentlichen KGV-Website zu bearbeiten — ohne Programmieren. Ihr braucht die Rolle <strong>Vorstand</strong>, <strong>Schriftführer</strong> oder <strong>Web</strong>.</p>

  <div class="steps">
    <div class="step"><div class="step-num">1</div><div class="step-content"><div class="step-title">In der Navigation oben auf „✏️ Website" klicken</div><div class="step-text">Das funktioniert von jeder Seite aus — Backoffice, Mitgliederbereich, egal wo.</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-content"><div class="step-title">Ihr seht den Website-Editor</div><div class="step-text">Oben eine Leiste mit Tabs: Hero, Banner, Galerie, Vereinshaus, Vorstand, Termine, Links, Preise, Sperrtage, E-Mail, Bilder, Sektionen, Einstellungen, Wartung, Mitglieder.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-content"><div class="step-title">Tab auswählen, Inhalt bearbeiten, unten „Speichern" klicken</div><div class="step-text">Änderungen sind sofort auf der öffentlichen Website sichtbar.</div></div></div>
  </div>

  <div class="warn-box">
    <div class="box-icon">⚠️</div>
    <div class="box-text"><strong>Immer „Speichern" klicken!</strong> Wenn ihr zwischen Tabs wechselt ohne zu speichern, gehen nicht gespeicherte Änderungen verloren.</div>
  </div>
</div>

<!-- 16. HERO -->
<div class="section" id="hero">
  <div class="section-header">
    <div class="section-icon">🏠</div>
    <div>
      <div class="section-title">16. Startseite / Hero</div>
      <div class="section-desc">Der große Bereich ganz oben auf der Startseite</div>
    </div>
  </div>
  <p>Tab „🏠 Hero" — hier bearbeitet ihr: Überschrift, Untertitel, Beschreibungstext (HTML erlaubt für Fettschrift/Zeilenumbrüche), und das Hintergrundbild (JPG/PNG/WebP, max. 5 MB).</p>
  <div class="tip-box"><div class="box-icon">💡</div><div class="box-text">Für einen Zeilenumbruch im Text: <code>&lt;br&gt;</code> eingeben. Für Fettschrift: <code>&lt;strong&gt;Text&lt;/strong&gt;</code>.</div></div>
</div>

<!-- 17. BANNER -->
<div class="section" id="banner">
  <div class="section-header">
    <div class="section-icon">📢</div>
    <div>
      <div class="section-title">17. Banner / Laufschrift</div>
      <div class="section-desc">Der gelbe Hinweisstreifen ganz oben auf der Website</div>
    </div>
  </div>
  <p>Tab „📢 Banner" — aktiviert den Streifen per Checkbox, tippt den Text ein und wählt die Farbe. Ideal für kurzfristige Hinweise wie „Hauptversammlung am 15. April um 18 Uhr". Einfach Checkbox deaktivieren wenn der Hinweis nicht mehr nötig ist.</p>
</div>

<!-- 18. GALERIE -->
<div class="section" id="galerie">
  <div class="section-header">
    <div class="section-icon">🖼️</div>
    <div>
      <div class="section-title">18. Galerie &amp; Bilder</div>
      <div class="section-desc">Fotos auf der Website verwalten</div>
    </div>
  </div>
  <p><strong>Tab „🖼️ Galerie":</strong> Hier pflegt ihr die Bildergalerie auf der Website. Fotos hochladen (JPG/PNG/WebP), Reihenfolge per Drag &amp; Drop ändern, Bilder entfernen.</p>
  <p><strong>Tab „🖼 Bilder":</strong> Der Bilder-Manager zeigt alle hochgeladenen Bilder im System. Hier könnt ihr Bilder löschen, die nirgendwo mehr verwendet werden, um Speicherplatz zu sparen.</p>
  <div class="tip-box"><div class="box-icon">💡</div><div class="box-text">Bilder werden automatisch komprimiert. Sehr große Originaldateien einfach hochladen — die Website zeigt sie immer in optimierter Größe.</div></div>
</div>

<!-- 19. VEREINSHAUS -->
<div class="section" id="vereinshaus">
  <div class="section-header">
    <div class="section-icon">🏡</div>
    <div>
      <div class="section-title">19. Vereinshaus</div>
      <div class="section-desc">Beschreibung und Fotos des Vereinshauses</div>
    </div>
  </div>
  <p>Tab „🏡 Vereinshaus" — Beschreibungstext, Hauptbild und Bildergalerie des Vereinshauses bearbeiten. Diese Inhalte erscheinen auf der Buchungsseite und helfen Interessenten, das Haus besser kennenzulernen.</p>
</div>

<!-- 20. VORSTAND -->
<div class="section" id="vorstand">
  <div class="section-header">
    <div class="section-icon">👥</div>
    <div>
      <div class="section-title">20. Vorstand</div>
      <div class="section-desc">Vorstandsmitglieder auf der Website aktuell halten</div>
    </div>
  </div>
  <p>Tab „👥 Vorstand" — Jedes Vorstandsmitglied hat eine Karte mit Foto, Name, Titel und Kontakt. Karten hinzufügen, bearbeiten oder entfernen. Das Gruppenfoto des Vorstands wird separat hochgeladen.</p>
  <div class="tip-box"><div class="box-icon">💡</div><div class="box-text">Wenn ein Vorstandsmitglied wechselt: Karte entfernen und neue Karte anlegen — oder einfach Name und Foto der bestehenden Karte austauschen.</div></div>
</div>

<!-- 21. TERMINE -->
<div class="section" id="termine">
  <div class="section-header">
    <div class="section-icon">📅</div>
    <div>
      <div class="section-title">21. Termine</div>
      <div class="section-desc">Veranstaltungen auf der Startseite ankündigen</div>
    </div>
  </div>
  <p>Tab „📅 Termine" — Veranstaltungen mit Datum, Uhrzeit, Titel und Beschreibung anlegen. Sie erscheinen chronologisch auf der Website. Vergangene Termine einfach löschen.</p>
</div>

<!-- 22. LINKS -->
<div class="section" id="links">
  <div class="section-header">
    <div class="section-icon">🔗</div>
    <div>
      <div class="section-title">22. Links</div>
      <div class="section-desc">Nützliche externe Links für Mitglieder und Besucher</div>
    </div>
  </div>
  <p>Tab „🔗 Links" — Links zu externen Websites pflegen (z.B. Stadtplanungsamt, Gartenratgeber). Jeder Link hat Titel, URL und optional eine kurze Beschreibung.</p>
</div>

<!-- 23. PREISE -->
<div class="section" id="preise">
  <div class="section-header">
    <div class="section-icon">💶</div>
    <div>
      <div class="section-title">23. Preise</div>
      <div class="section-desc">Mietpreise und Extras für das Vereinshaus</div>
    </div>
  </div>
  <p>Tab „💶 Preise" — Grundmiete, Kaution und buchbare Extras (z.B. Bestuhlung, Technik) mit Preisen pflegen. Diese Angaben erscheinen auf der Buchungsseite und werden in Bestätigungs-E-Mails eingesetzt.</p>
  <div class="warn-box"><div class="box-icon">⚠️</div><div class="box-text"><strong>Preisänderungen gelten sofort</strong> für neue Buchungsanfragen — bereits bestätigte Buchungen werden nicht rückwirkend geändert.</div></div>
</div>

<!-- 24. SPERRTAGE -->
<div class="section" id="sperrtage">
  <div class="section-header">
    <div class="section-icon">🚫</div>
    <div>
      <div class="section-title">24. Sperrtage</div>
      <div class="section-desc">Tage blockieren, an denen das Vereinshaus nicht buchbar ist</div>
    </div>
  </div>
  <p>Tab „🚫 Sperrtage" — Einzelne Tage oder Zeiträume blockieren (z.B. Vereinsfeste, Renovierungen). Gesperrte Tage sind im Buchungskalender grau markiert und können nicht gebucht werden.</p>
  <div class="steps">
    <div class="step"><div class="step-num">1</div><div class="step-content"><div class="step-title">„Einzelnen Tag sperren" oder „Zeitraum sperren" wählen</div></div></div>
    <div class="step"><div class="step-num">2</div><div class="step-content"><div class="step-title">Datum(e) und optional einen Grund eingeben</div><div class="step-text">Der Grund ist nur intern sichtbar.</div></div></div>
    <div class="step"><div class="step-num">3</div><div class="step-content"><div class="step-title">„Speichern" — sofort aktiv</div></div></div>
  </div>
</div>

<!-- 25. E-MAIL-VORLAGEN -->
<div class="section" id="email-vorlagen">
  <div class="section-header">
    <div class="section-icon">✉️</div>
    <div>
      <div class="section-title">25. E-Mail-Vorlagen</div>
      <div class="section-desc">Automatische E-Mails anpassen</div>
    </div>
  </div>
  <p>Tab „✉️ E-Mail" — drei automatische E-Mails können angepasst werden:</p>
  <div class="cols">
    <div class="mock-card"><div class="mock-card-title">✓ Bestätigung</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Geht raus wenn ihr eine Buchung bestätigt. Enthält IBAN und Betrag.</p></div>
    <div class="mock-card"><div class="mock-card-title">✗ Absage</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Geht raus wenn ihr eine Buchung ablehnt.</p></div>
    <div class="mock-card"><div class="mock-card-title">📩 Eingangsbestätigung</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Geht automatisch raus sobald eine neue Anfrage eingeht.</p></div>
  </div>
  <p>Platzhalter die ihr im Text verwenden könnt: <code>{name}</code> <code>{datum}</code> <code>{betrag}</code> <code>{iban}</code> <code>{kontakt_name}</code> <code>{telefon}</code></p>
  <div class="tip-box"><div class="box-icon">💡</div><div class="box-text"><strong>Test-Mail senden:</strong> Unten im E-Mail-Tab könnt ihr eine Test-E-Mail an eure eigene Adresse senden, bevor ihr die Vorlage speichert.</div></div>
</div>

<!-- 26. SEKTIONEN -->
<div class="section" id="sektionen">
  <div class="section-header">
    <div class="section-icon">👁</div>
    <div>
      <div class="section-title">26. Sektionen</div>
      <div class="section-desc">Bereiche der Website ein- und ausblenden</div>
    </div>
  </div>
  <p>Tab „👁 Sektionen" — Ihr könnt einzelne Bereiche der Website (z.B. Galerie, Links, Termine) mit einem Klick unsichtbar schalten, ohne den Inhalt zu löschen. Praktisch wenn ein Bereich gerade nicht aktuell ist.</p>
</div>

<!-- 27. EINSTELLUNGEN -->
<div class="section" id="einstellungen">
  <div class="section-header">
    <div class="section-icon">⚙️</div>
    <div>
      <div class="section-title">27. Allgemeine Einstellungen</div>
      <div class="section-desc">Bankdaten, Kontakt, Gemeinschaftsarbeit, E-Mail-Benachrichtigungen</div>
    </div>
  </div>

  <p>Tab „⚙️ Einstellungen" im Website-Editor. Alles was ihr hier eintragt wird automatisch in E-Mails und auf der Website verwendet.</p>

  <div class="cols">
    <div class="mock-card"><div class="mock-card-title">🏦 Bankdaten</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">IBAN, Kontoinhaber und Bank — erscheinen in Bestätigungs-E-Mails.</p></div>
    <div class="mock-card"><div class="mock-card-title">📞 Kontaktdaten</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Name, Telefon und E-Mail des Ansprechpartners — erscheinen in E-Mail-Signaturen.</p></div>
    <div class="mock-card"><div class="mock-card-title">🔨 Gemeinschaftsarbeit</div><p style="margin:0;font-size:0.85rem;color:var(--gray)"><strong>Soll-Stunden pro Mitglied</strong> — hier stellt ihr ein, wie viele Stunden jedes Mitglied pro Jahr leisten muss (z.B. 4).</p></div>
    <div class="mock-card"><div class="mock-card-title">📬 E-Mail-Benachrichtigungen</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">An welche Adresse neue Buchungsanfragen und Kontaktanfragen per E-Mail weitergeleitet werden.</p></div>
  </div>
</div>

<!-- 28. WARTUNG -->
<div class="section" id="wartung">
  <div class="section-header">
    <div class="section-icon">🔧</div>
    <div>
      <div class="section-title">28. Wartungsmodus</div>
      <div class="section-desc">Website für Besucher temporär sperren</div>
    </div>
  </div>
  <p>Tab „🟢 Wartung" (wird rot wenn aktiv) — Wenn ihr größere Änderungen vornehmt, könnt ihr die Website für externe Besucher vorübergehend sperren. Besucher sehen dann eine freundliche Hinweisseite. Das Admin-Panel bleibt weiter zugänglich.</p>
  <div class="warn-box"><div class="box-icon">⚠️</div><div class="box-text"><strong>Daran denken:</strong> Wartungsmodus nach getaner Arbeit wieder deaktivieren — sonst sehen alle Besucher weiterhin die Sperrseite.</div></div>
</div>

<!-- 29. PASSWORT -->
<div class="section" id="passwort">
  <div class="section-header">
    <div class="section-icon">🔑</div>
    <div>
      <div class="section-title">29. Passwort ändern</div>
      <div class="section-desc">Admin-Passwort und Handbuch-Passwort aktualisieren</div>
    </div>
  </div>
  <p>Im Website-Editor → Tab „⚙️ Einstellungen" ganz unten findet ihr Felder zum Ändern von:</p>
  <ul>
    <li><strong>Admin-Passwort</strong> — das Notfall-Passwort für /intern/</li>
    <li><strong>Handbuch-Passwort</strong> — das Passwort für diese Anleitung</li>
  </ul>
  <p>Persönliche Passwörter (für den Mitgliederbereich) können Mitglieder selbst im Mitgliederbereich → Mein Profil → Passwort ändern. Als Admin könnt ihr über den 🔑-Button ein neues Zufallspasswort generieren und per E-Mail schicken.</p>
</div>

<!-- TIPPS -->
<div class="section" id="tipps">
  <div class="section-header">
    <div class="section-icon">💡</div>
    <div>
      <div class="section-title">Häufige Fragen</div>
      <div class="section-desc">Schnelle Antworten auf typische Fragen</div>
    </div>
  </div>

  <h3>Navigation &amp; Zugang</h3>
  <div class="mock-card"><div class="mock-card-title">Wo logge ich mich am besten ein?</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Über <span class="url">kgv461.de/mitglieder.php</span> mit eurem persönlichen Passwort. Von dort kommt ihr per Klick in alle Bereiche.</p></div>
  <div class="mock-card"><div class="mock-card-title">Ich sehe „Backoffice" nicht in der Navigation — warum?</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Eure Rolle hat keinen Admin-Zugang. Wendet euch an den SuperAdmin oder ein Vorstandsmitglied um die Rolle anzupassen (🎭-Button).</p></div>
  <div class="mock-card"><div class="mock-card-title">Ich werde immer ausgeloggt — was tun?</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Die Session läuft nach 60 Minuten ohne Aktivität ab. Einfach neu einloggen — alle Daten bleiben erhalten.</p></div>

  <h3>Buchungen</h3>
  <div class="mock-card"><div class="mock-card-title">Kann ich eine Buchung nachträglich bearbeiten?</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Ja — über „✏️ Notiz" könnt ihr Informationen ergänzen. Datum und Personenzahl können nicht nachträglich geändert werden; bei Bedarf stornieren und neu anlegen.</p></div>
  <div class="mock-card"><div class="mock-card-title">Wie exportiere ich die Einnahmen fürs Kassenbuch?</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Backoffice → Tab „📋 Buchungen" → Button „💶 Kassenbuch (CSV)". Die Excel-Datei enthält alle bezahlten Buchungen des laufenden Jahres mit Summenzeile.</p></div>
  <div class="mock-card"><div class="mock-card-title">Jemand hat telefonisch gebucht — wie trage ich das ein?</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Backoffice → Buchungen → „📅 Buchung manuell eintragen". Im Kalender Datum auswählen, Daten eingeben, anlegen. Erscheint sofort als bestätigt.</p></div>

  <h3>Mitglieder</h3>
  <div class="mock-card"><div class="mock-card-title">Wie sende ich eine Nachricht an alle Mitglieder?</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Backoffice → Tab „👥 Mitglieder" → Button „📢 Massen-Nachricht". Text eingeben, optional E-Mail aktivieren, absenden.</p></div>
  <div class="mock-card"><div class="mock-card-title">Wo stelle ich die Pflicht-Stunden für Gemeinschaftsarbeit ein?</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Website-Editor (✏️ Website) → Tab „⚙️ Einstellungen" → Abschnitt „🔨 Gemeinschaftsarbeit" → Soll-Stunden ändern und speichern.</p></div>
  <div class="mock-card"><div class="mock-card-title">Ein Mitglied hat sein Passwort vergessen — was tun?</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Backoffice → Mitglieder-Tabelle → 🔑-Button neben dem Mitglied. Ein neues Zufallspasswort wird generiert und per E-Mail zugeschickt.</p></div>

  <h3>Website</h3>
  <div class="mock-card"><div class="mock-card-title">Ich habe etwas gespeichert — warum sehe ich es auf der Website nicht?</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Seite im Browser neu laden (Strg+F5 / Cmd+Shift+R). Manchmal speichert der Browser alte Versionen zwischen.</p></div>
  <div class="mock-card"><div class="mock-card-title">Kann ich Änderungen rückgängig machen?</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Im Website-Editor gibt es keine automatische Versionierung. Über den Tab „🔴 Wartung" → „Backup erstellen" könnt ihr vor größeren Änderungen eine Sicherung anlegen.</p></div>
  <div class="mock-card"><div class="mock-card-title">Was passiert wenn ich den Wartungsmodus aktiviere?</div><p style="margin:0;font-size:0.85rem;color:var(--gray)">Externe Besucher sehen eine Wartungsseite. Das Admin-Panel und der Mitgliederbereich bleiben für euch zugänglich. Daran denken: Wartungsmodus nach der Arbeit wieder deaktivieren!</p></div>
</div>

</main>
</div>

<script>
// Aktiven Nav-Link beim Scrollen hervorheben
const sections = document.querySelectorAll('.section');
const links     = document.querySelectorAll('.nav-link');
const observer  = new IntersectionObserver(entries => {
  entries.forEach(e => {
    if (e.isIntersecting) {
      links.forEach(l => l.classList.remove('active'));
      const active = document.querySelector('.nav-link[href="#' + e.target.id + '"]');
      if (active) active.classList.add('active');
    }
  });
}, { rootMargin: '-20% 0px -70% 0px' });
sections.forEach(s => observer.observe(s));
</script>
</body>
</html>
