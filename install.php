<?php
/**
 * install.php — Setup-Installer für das Vereinsportal.
 *
 * Führt durch: Voraussetzungs-Check → Domain/Vereinsdaten → .env & data/ anlegen
 * → Admin-Passwort → Sicherheits-Checkliste. Sperrt sich selbst per install.lock.
 *
 * WICHTIG: Nach Abschluss diese Datei vom Server LÖSCHEN (siehe Checkliste).
 * Der Installer ist bewusst eigenständig (kein settings_loader nötig).
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$LOCK_FILE = __DIR__ . '/install.lock';
$ROOT      = __DIR__;

/* ────────────── Lock / Abbruch ────────────── */
if (file_exists($LOCK_FILE)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo install_page(
        '<h2>Setup bereits abgeschlossen</h2>'
        . '<p>Die Installation ist bereits erfolgt (install.lock vorhanden).</p>'
        . '<p>Zum erneuten Ausführen: <code>install.lock</code> löschen '
        . '<strong>und</strong> <code>install.php</code> vom Server entfernen '
        . 'bzw. durch eine frische Kopie ersetzen.</p>'
        . '<p><a class="btn" href="./">Zur Startseite</a></p>'
    );
    exit;
}

/* ────────────── Session + CSRF ────────────── */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['install_csrf'] = $_SESSION['install_csrf'] ?? bin2hex(random_bytes(16));
$csrf = $_SESSION['install_csrf'];

/** Prüft CSRF-Token bei POST. */
function csrf_ok(?string $token): bool {
    return is_string($token) && $token !== '' && hash_equals($_SESSION['install_csrf'] ?? '', $token);
}

/** Rendert Fehler-/Infozeilen */
function fa(?string $m, string $cls = 'err'): string {
    return $m === '' || $m === null ? '' : '<div class="msg ' . $cls . '">' . htmlspecialchars($m) . '</div>';
}

/** Schreibt Datei; liefert Leerstring bei Erfolg, sonst Fehlermeldung. */
function put(string $path, string $content, bool $overwrite = false): string {
    global $ROOT;
    $abs = rtrim($ROOT, '/') . '/' . ltrim($path, '/');
    if (file_exists($abs) && !$overwrite) {
        return "existiert bereits (übersprungen): {$path}";
    }
    if (@file_put_contents($abs, $content) === false) {
        return "Schreibfehler bei: {$path} — Rechte prüfen (0755/chown).";
    }
    @chmod($abs, 0600); // .env, settings.json etc. schützen
    return '';
}

/* ────────────── Status-/Schrittdaten ────────────── */
$step = (int)($_POST['step'] ?? ($_GET['step'] ?? 1));
$msgs = [];
$ok   = true;

/* ── 1) Voraussetzungen prüfen ── */
$checks = [];
$checks[] = [version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP-Version ≥ 8.1 (aktuell: ' . PHP_VERSION . ')'];
foreach (['mbstring', 'gd', 'dom', 'json'] as $ext) {
    $checks[] = [extension_loaded($ext), "Erweiterung <code>{$ext}</code>"];
}
$imapOk = extension_loaded('imap');
$checks[] = [true, 'IMAP ' . ($imapOk ? 'vorhanden (E-Mail-Empfang aktiv)' : '<span class="muted">fehlt — optional, nur für E-Mail-Empfang nötig</span>')];

$writable = [];
foreach ([$ROOT, $ROOT . '/data', $ROOT . '/data.example'] as $d) {
    $w = is_dir($d) ? is_writable($d) : @mkdir($d, 0755, true);
    if ($d === $ROOT . '/data' && !is_dir($d)) {
        $w = @mkdir($d, 0755, true);
    }
    $writable[] = [$w, 'Schreibrechte: ' . str_replace($ROOT, '.', $d)];
}
$allOk = true;
foreach ($checks as [$c]) { $allOk = $allOk && $c; }
foreach ($writable as [$c]) { $allOk = $allOk && $c; }

/* ── POST-Verarbeitung ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_ok($_POST['csrf'] ?? null)) {
    $msgs[] = fa('Sicherheits-Token ungültig — bitte neu laden.', 'err');
    $ok = false;
}

if ($ok && $_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $domain     = trim((string)($_POST['domain'] ?? ''));
    $site_url   = trim((string)($_POST['site_url'] ?? ''));
    $verein     = trim((string)($_POST['verein'] ?? ''));
    $contact    = trim((string)($_POST['contact'] ?? ''));

    if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
        $msgs[] = fa('Bitte eine gültige Domain ohne https:// angeben (z.B. mein-verein.de).', 'err');
        $ok = false;
    }
    if ($site_url === '' || !filter_var($site_url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#', $site_url)) {
        $msgs[] = fa('Bitte eine gültige Basis-URL angeben (z.B. https://mein-verein.de).', 'err');
        $ok = false;
    }
    if ($contact !== '' && !filter_var($contact, FILTER_VALIDATE_EMAIL)) {
        $msgs[] = fa('Bitte eine gültige Kontakt-E-Mail angeben (oder leer lassen).', 'err');
        $ok = false;
    }

    if ($ok) {
        $msgs[] = fa('Domain-Daten übernommen — jetzt werden Dateien geschrieben.', 'ok');

        /* Domain in statischen Dateien ersetzen */
        foreach (['.htaccess', 'sitemap.xml', 'robots.txt', '.well-known/security.txt'] as $f) {
            $p = $ROOT . '/' . $f;
            if (!is_file($p)) { continue; }
            $c = (string)file_get_contents($p);
            $c = str_replace('YOUR-DOMAIN.TLD', $domain, $c);
            $e = put($f, $c, true);
            if ($e !== '') { $msgs[] = fa($e, 'err'); }
        }

        /* .env aus Vorlage (nicht überschreiben) */
        if (!is_file($ROOT . '/.env') && is_file($ROOT . '/.env.example')) {
            $env = (string)file_get_contents($ROOT . '/.env.example');
            $env = preg_replace('/^SITE_URL=.*$/m', 'SITE_URL=' . $site_url, $env);
            $e = put('.env', $env);
            if ($e !== '') { $msgs[] = fa($e, 'err'); } else { $msgs[] = fa('.env angelegt (IMAP-Zugang dort eintragen).', 'ok'); }
        } else {
            $msgs[] = fa('.env existiert bereits — unverändert.', 'info');
        }

        /* data/ anlegen + settings.json */
        if (!is_dir($ROOT . '/data')) { @mkdir($ROOT . '/data', 0755, true); }
        if (!is_file($ROOT . '/data/settings.json') && is_file($ROOT . '/data.example/settings.example.json')) {
            $s = json_decode((string)file_get_contents($ROOT . '/data.example/settings.example.json'), true) ?: [];
            $s['site_url'] = $site_url;
            if ($contact !== '') { $s['email'] = $contact; }
            $e = put('data/settings.json', (string)json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if ($e !== '') { $msgs[] = fa($e, 'err'); } else { $msgs[] = fa('data/settings.json angelegt.', 'ok'); }
        } else {
            $msgs[] = fa('data/settings.json existiert bereits — unverändert.', 'info');
        }

        /* content.json aus Vorlage mit Vereinsnamen */
        if (!is_file($ROOT . '/data/content.json') && is_file($ROOT . '/data.example/content.example.json')) {
            $c = (string)file_get_contents($ROOT . '/data.example/content.example.json');
            if ($verein !== '') {
                $c = str_replace('Euer Kleingartenverein e.V.', $verein, $c);
                $c = str_replace('"verein": "Euer Kleingartenverein e.V."', '"verein": "' . str_replace('"', '\"', $verein) . '"', $c);
            }
            $e = put('data/content.json', $c);
            if ($e !== '') { $msgs[] = fa($e, 'err'); } else { $msgs[] = fa('data/content.json mit Vereinsdaten angelegt.', 'ok'); }
        } else {
            $msgs[] = fa('data/content.json existiert bereits — unverändert.', 'info');
        }
    }
}

if ($ok && $_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    $pw = (string)($_POST['pw'] ?? '');
    $pw2 = (string)($_POST['pw2'] ?? '');
    if (strlen($pw) < 10) {
        $msgs[] = fa('Passwort muss mindestens 10 Zeichen haben.', 'err'); $ok = false;
    } elseif ($pw !== $pw2) {
        $msgs[] = fa('Passwörter stimmen nicht überein.', 'err'); $ok = false;
    } elseif (!is_file($ROOT . '/data/settings.json')) {
        $msgs[] = fa('data/settings.json fehlt — bitte Schritt 2 zuerst ausführen.', 'err'); $ok = false;
    } else {
        $s = json_decode((string)file_get_contents($ROOT . '/data/settings.json'), true) ?: [];
        $s['password_hash'] = password_hash($pw, PASSWORD_BCRYPT);
        $e = put('data/settings.json', (string)json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), true);
        if ($e !== '') { $msgs[] = fa($e, 'err'); $ok = false; }
        else {
            $msgs[] = fa('Admin-Passwort gesetzt (bcrypt).', 'ok');
            @file_put_contents($LOCK_FILE, "installer abgeschlossen: " . date('c') . "\n");
            @chmod($LOCK_FILE, 0600);
            $step = 4;
        }
    }
}

/* ────────────── UI ────────────── */
function install_page(string $body): string {
    $title = 'Vereinsportal — Installation';
    return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>
  :root{--green:#2d5231;--green2:#3d6b41;--lime:#d4e6c3;--bg:#f4f7f0;--card:#fff;--txt:#2d3e2d;}
  *{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--txt);line-height:1.55}
  header{background:linear-gradient(135deg,var(--green),var(--green2);color:#fff;padding:28px 20px;text-align:center}
  header h1{margin:0 0 4px;font-size:1.6rem}
  header p{margin:0;opacity:.9;font-size:.95rem}
  main{max-width:760px;margin:26px auto;padding:0 16px}
  .card{background:var(--card);border-radius:14px;padding:26px;box-shadow:0 4px 18px rgba(0,0,0,.08);margin-bottom:20px}
  h2{margin-top:0;color:var(--green)}
  .steps{display:flex;gap:8px;margin-bottom:22px;flex-wrap:wrap}
  .steps span{flex:1;min-width:90px;text-align:center;padding:8px;border-radius:10px;background:#e8efe3;font-size:.8rem;color:#5a6c5a}
  .steps span.on{background:var(--green2);color:#fff;font-weight:600}
  table{width:100%;border-collapse:collapse;margin:10px 0}
  td,th{padding:8px 10px;border-bottom:1px solid #e3ecdd;text-align:left;font-size:.92rem}
  .yes{color:#1e7a2e;font-weight:600}.no{color:#c0392b;font-weight:600}.muted{color:#8a9a8a}
  .msg{margin:10px 0;padding:12px 14px;border-radius:10px;font-size:.92rem}
  .msg.ok{background:#e6f4e6;color:#1e7a2e}
  .msg.err{background:#fdecea;color:#c0392b}
  .msg.info{background:#eef2ff;color:#2b4a8a}
  label{display:block;margin:14px 0 4px;font-weight:600;font-size:.92rem}
  input[type=text],input[type=email],input[type=password],input[type=url]{width:100%;padding:11px 13px;border:1.5px solid var(--lime);border-radius:9px;font-size:1rem;outline:none}
  input:focus{border-color:var(--green2)}
  .btn{display:inline-block;margin-top:18px;padding:12px 26px;background:var(--green2);color:#fff;border:0;border-radius:50px;font-size:1rem;font-weight:600;cursor:pointer;text-decoration:none}
  .btn:hover{background:var(--green)}
  .btn.ghost{background:transparent;color:var(--green2);border:1.5px solid var(--green2)}
  code{background:#eef2ea;padding:2px 6px;border-radius:6px;font-size:.88em}
  ul.check{padding-left:22px}
  ul.check li{margin:6px 0}
  .foot{text-align:center;color:#8a9a8a;font-size:.8rem;margin:30px 0}
</style>
</head>
<body>
<header><h1>🌿 Vereinsportal</h1><p>Setup & Installation</p></header>
<main>{$body}<p class="foot">Open-Source-Vereinsportal — install.php (nach Abschluss löschen)</p></main>
</body>
</html>
HTML;
}

/* ── Schritte rendern ── */
$body = '';
if ($step === 1) {
    $body .= '<div class="steps"><span class="on">1 · Prüfung</span><span>2 · Daten</span><span>3 · Passwort</span><span>4 · Fertig</span></div>';
    $body .= '<div class="card"><h2>Voraussetzungen prüfen</h2><table>';
    foreach ($checks as [$c, $label]) {
        $body .= '<tr><td>' . $label . '</td><td class="' . ($c ? 'yes' : 'no') . '">' . ($c ? '✓' : '✗') . '</td></tr>';
    }
    foreach ($writable as [$c, $label]) {
        $body .= '<tr><td>' . $label . '</td><td class="' . ($c ? 'yes' : 'no') . '">' . ($c ? '✓' : '✗') . '</td></tr>';
    }
    $body .= '</table>';
    if ($allOk) {
        $body .= '<form method="post" action="?step=2"><input type="hidden" name="step" value="2">'
               . '<input type="hidden" name="csrf" value="' . htmlspecialchars($csrf) . '">'
               . '<button class="btn" type="submit">Weiter → Domain &amp; Vereinsdaten</button></form>';
    } else {
        $body .= '<p class="msg err">Nicht alle Voraussetzungen erfüllt — bitte zuerst beheben.</p>';
    }
    $body .= '</div>';
} elseif ($step === 2) {
    $body .= '<div class="steps"><span>1 · Prüfung</span><span class="on">2 · Daten</span><span>3 · Passwort</span><span>4 · Fertig</span></div>';
    $body .= '<div class="card"><h2>Domain &amp; Vereinsdaten</h2>';
    $body .= implode('', $msgs);
    $body .= '<form method="post" action="?step=3"><input type="hidden" name="step" value="3">'
           . '<input type="hidden" name="csrf" value="' . htmlspecialchars($csrf) . '">'
           . '<label>Domaine (ohne https:// — ersetzt YOUR-DOMAIN.TLD in .htaccess, sitemap etc.)</label>'
           . '<input type="text" name="domain" value="' . htmlspecialchars($_POST['domain'] ?? '') . '" placeholder="mein-verein.de" required>'
           . '<label>Basis-URL des Portals (SITE_URL — für Links in E-Mails &amp; QR-Codes)</label>'
           . '<input type="url" name="site_url" value="' . htmlspecialchars($_POST['site_url'] ?? '') . '" placeholder="https://mein-verein.de" required>'
           . '<label>Vereinsname (wird in die Beispiel-Inhalte übernommen)</label>'
           . '<input type="text" name="verein" value="' . htmlspecialchars($_POST['verein'] ?? '') . '" placeholder="Kleingartenverein Beispiel e.V.">'
           . '<label>Kontakt-E-Mail (für das Impressum / E-Mail-Empfänger)</label>'
           . '<input type="email" name="contact" value="' . htmlspecialchars($_POST['contact'] ?? '') . '" placeholder="vorstand@mein-verein.de">'
           . '<button class="btn" type="submit">Dateien anlegen →</button></form>';
    $body .= '</div>';
} elseif ($step === 3) {
    $body .= '<div class="steps"><span>1 · Prüfung</span><span>2 · Daten</span><span class="on">3 · Passwort</span><span>4 · Fertig</span></div>';
    $body .= '<div class="card"><h2>Admin-Passwort festlegen</h2>';
    $body .= implode('', $msgs);
    $body .= '<form method="post" action="?step=4"><input type="hidden" name="step" value="3">'
           . '<input type="hidden" name="csrf" value="' . htmlspecialchars($csrf) . '">'
           . '<label>Admin-Passwort (mind. 10 Zeichen)</label>'
           . '<input type="password" name="pw" minlength="10" required autocomplete="new-password">'
           . '<label>Passwort wiederholen</label>'
           . '<input type="password" name="pw2" minlength="10" required autocomplete="new-password">'
           . '<button class="btn" type="submit">Setup abschließen →</button></form>';
    $body .= '</div>';
} elseif ($step === 4) {
    $body .= '<div class="steps"><span>1 · Prüfung</span><span>2 · Daten</span><span>3 · Passwort</span><span class="on">4 · Fertig</span></div>';
    $body .= '<div class="card"><h2>Installation abgeschlossen ✅</h2>';
    $body .= implode('', $msgs);
    $body .= '<p>Das Portal ist eingerichtet. Bitte die Sicherheits-Checkliste abarbeiten:</p>'
           . '<ul class="check">'
           . '<li><strong>install.php löschen</strong> (oder zumindest den Zugriff per .htaccess blocken) — <code>rm install.php</code></li>'
           . '<li><code>.env</code> öffnen und IMAP-Zugang eintragen (falls E-Mail-Empfang gewünscht)</li>'
           . '<li>In <code>data/content.json</code> Vorstand, Impressum, Datenschutz, Termine &amp; Preise pflegen (oder im Admin-Bereich <code>/intern</code>)</li>'
           . '<li>Prüfen, dass <code>data/</code> nicht öffentlich erreichbar ist (.htaccess blockt es bereits)</li>'
           . '<li>Cron-Jobs einrichten (siehe INSTALL.md, Abschnitt 6)</li>'
           . '<li>Backup von <code>data/</code> und <code>.env</code> einplanen</li>'
           . '</ul>'
           . '<a class="btn" href="./intern/">Zum Admin-Bereich</a> '
           . '<a class="btn ghost" href="./">Zur Startseite</a>';
    $body .= '</div>';
} else {
    $body .= '<div class="card"><h2>Unbekannter Schritt</h2><p>Bitte neu starten: <a href="install.php">install.php</a></p></div>';
}

echo install_page($body);