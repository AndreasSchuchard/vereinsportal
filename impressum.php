<?php
$contentFile = __DIR__ . '/data/content.json';
$c = file_exists($contentFile) ? (json_decode((string)file_get_contents($contentFile), true) ?: []) : [];
$imp = $c['impressum'] ?? [];
// Fallback defaults
$imp += [
    'verein'            => 'Muster-Kleingartenverein e.V.',
    'strasse'           => 'Musterstraße 1',
    'plz_ort'           => '12345 Musterstadt',
    'vertreter'         => 'Max Mustermann (1. Vorsitzender)',
    'telefon'           => '+49 000 000 00 00',
    'email'             => 'vorstand@example.org',
    'postanschrift'     => 'Musterstraße 2, 12345 Musterstadt',
    'registergericht'   => 'Amtsgericht Musterstadt',
    'registernummer'    => '8339',
    'verantwortlich'    => 'Maria Beispiel',
    'haftung_text'      => 'Trotz sorgfältiger inhaltlicher Kontrolle übernehmen wir keine Haftung für die Inhalte externer Links. Für den Inhalt der verlinkten Seiten sind ausschließlich deren Betreiber verantwortlich.',
    'urheberrecht_text' => 'Die durch die Seitenbetreiber erstellten Inhalte und Werke auf diesen Seiten unterliegen dem deutschen Urheberrecht. Die Vervielfältigung, Bearbeitung, Verbreitung und jede Art der Verwertung außerhalb der Grenzen des Urheberrechtes bedürfen der schriftlichen Zustimmung des jeweiligen Autors bzw. Erstellers.',
];
function he(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Impressum der Muster-Kleingartenverein e.V.">
<link rel="canonical" href="https://kgv461.de/impressum.php">
<title>Impressum – Muster-Kleingartenverein e.V.</title>
<link rel="stylesheet" href="/assets/fonts/fonts.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--primary-green:#4a7c4e;--light-green:#7cb342;--accent-green:#8bc34a;--bg-light:#f8faf8;--text-dark:#2d3e2d;--text-gray:#5a6c5a;--white:#ffffff;--shadow:0 10px 30px rgba(0,0,0,0.1)}
body{font-family:'Poppins',sans-serif;line-height:1.6;color:var(--text-dark);background:var(--bg-light);min-height:100vh;display:flex;flex-direction:column}
header{background:var(--white);padding:1rem 0;box-shadow:0 2px 20px rgba(0,0,0,0.08);position:sticky;top:0;z-index:1000}
nav{display:flex;justify-content:space-between;align-items:center;max-width:1200px;margin:0 auto;padding:0 2rem}
.logo{display:flex;align-items:center;gap:1rem}
.logo-img{width:70px;height:70px;transition:transform 0.3s ease}
.logo-img:hover{transform:scale(1.05)}
.logo-img img{width:100%;height:100%;object-fit:contain}
.back-link{display:inline-flex;align-items:center;gap:0.5rem;color:var(--primary-green);text-decoration:none;font-weight:500;transition:all 0.3s ease}
.back-link:hover{color:var(--light-green);transform:translateX(-5px)}
main{flex:1;padding:4rem 0}
.container{max-width:800px;margin:0 auto;padding:0 2rem}
h1{font-family:'Playfair Display',serif;font-size:3rem;color:var(--primary-green);text-align:center;margin-bottom:3rem}
.content-box{background:var(--white);padding:3rem;border-radius:20px;box-shadow:var(--shadow)}
h2{color:var(--primary-green);margin-bottom:1.5rem;font-size:1.5rem}
h3{color:var(--primary-green);margin-bottom:1rem;margin-top:2rem}
.info-section{margin-bottom:2rem}
.info-section p{margin-bottom:0.5rem}
.highlight-box{background:rgba(255,193,7,0.1);padding:1.5rem;border-radius:10px;border-left:4px solid #ffc107;margin-top:2rem}
footer{background:var(--primary-green);color:white;padding:2rem 0;text-align:center;margin-top:auto}
footer p{margin-bottom:0.5rem}
footer a{color:rgba(255,255,255,0.8);text-decoration:none;transition:color 0.3s}
footer a:hover{color:white}
@media(max-width:768px){h1{font-size:2rem}.content-box{padding:2rem 1.5rem}}
</style>
</head>
<body>
<header>
  <nav>
    <div class="logo">
      <div class="logo-img">
        <img src="/logo_kgv461.png" alt="Muster-Kleingartenverein e.V. Logo">
      </div>
    </div>
    <a href="/" class="back-link">← Zurück zur Startseite</a>
  </nav>
</header>

<main>
  <div class="container">
    <h1>Impressum</h1>
    <div class="content-box">
      <h2>Angaben gemäß § 5 DDG</h2>

      <div class="info-section">
        <p><strong><?= he($imp['verein']) ?></strong></p>
        <p><?= he($imp['strasse']) ?></p>
        <p><?= he($imp['plz_ort']) ?></p>
      </div>

      <div class="info-section">
        <h3>Vertretungsberechtigter Vorstand:</h3>
        <p><?= he($imp['vertreter']) ?></p>
      </div>

      <div class="info-section">
        <h3>Kontakt:</h3>
        <p>Mobil: <?= he($imp['telefon']) ?></p>
        <p>E-Mail: <a href="mailto:<?= he($imp['email']) ?>" style="color:var(--primary-green)"><?= he($imp['email']) ?></a></p>
      </div>

      <div class="info-section">
        <h3>Postanschrift:</h3>
        <p><?= he($imp['postanschrift']) ?></p>
      </div>

      <div class="info-section">
        <h3>Registereintrag:</h3>
        <p>Registergericht: <?= he($imp['registergericht']) ?></p>
        <p>Registernummer: <?= he($imp['registernummer']) ?></p>
      </div>

      <div class="info-section">
        <h3>Verantwortlich für den Inhalt nach § 55 Abs. 2 RStV:</h3>
        <p><?= he($imp['verantwortlich']) ?></p>
      </div>

      <div class="highlight-box">
        <h3 style="margin-top:0">Haftungsausschluss:</h3>
        <p style="margin:0"><?= he($imp['haftung_text']) ?></p>
      </div>

      <div class="info-section" style="margin-top:2rem">
        <h3>Urheberrecht:</h3>
        <p><?= he($imp['urheberrecht_text']) ?></p>
      </div>
    </div>
  </div>
</main>

<footer>
  <p>&copy; <?= date('Y') ?> Muster-Kleingartenverein e.V.</p>
  <p>
    <a href="/">Startseite</a> |
    <a href="/impressum.php">Impressum</a> |
    <a href="/datenschutz.php">Datenschutz</a>
  </p>
</footer>
</body>
</html>
