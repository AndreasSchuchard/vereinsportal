<?php
require_once __DIR__ . '/inc/settings_loader.php';

$contentFile = __DIR__ . '/data/content.json';
$c = file_exists($contentFile) ? (json_decode((string)file_get_contents($contentFile), true) ?: []) : [];
$storedPrivacy = $c['datenschutz'] ?? [];
$ds = [
    'stand'            => 'August 2026',
    'verantwortlicher' => (string)($storedPrivacy['verantwortlicher'] ?? 'Max Mustermann'),
    'telefon'          => (string)($storedPrivacy['telefon'] ?? '+49 000 000 00 00'),
    'email'            => (string)($storedPrivacy['email'] ?? 'vorstand@example.org'),
    'adresse'          => (string)($storedPrivacy['adresse'] ?? 'Musterstraße 1, 12345 Musterstadt'),
];

// The processing descriptions are maintained in code so that an outdated CMS
// value cannot accidentally replace legally relevant information.
$privacySections = [
    ['titel' => '2. Hosting und Server-Protokolldaten',
     'inhalt' => "Diese Website wird bei der STRATO GmbH, Otto-Ostrowski-Straße 7, 10249 Berlin, gehostet. Beim Aufruf der Website verarbeitet der Hostinganbieter technisch erforderliche Zugriffsdaten. Dazu können insbesondere IP-Adresse, Datum und Uhrzeit, angeforderte Adresse beziehungsweise Datei, übertragene Datenmenge, Referrer, Browser, Betriebssystem und Zugriffsstatus gehören.\n\nDie Verarbeitung dient dem sicheren und störungsfreien Betrieb, der Fehleranalyse und der Abwehr von Missbrauch. Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO. Unser berechtigtes Interesse besteht in der sicheren Bereitstellung des Internetangebots. Mit STRATO besteht, soweit erforderlich, eine Vereinbarung zur Auftragsverarbeitung nach Art. 28 DSGVO. Protokolldaten werden gelöscht, sobald sie für diese Zwecke nicht mehr benötigt werden, vorbehaltlich einer erforderlichen Aufbewahrung zur Aufklärung konkreter Sicherheitsvorfälle oder gesetzlicher Pflichten."],

    ['titel' => '3. Technisch notwendige Sitzungen und Cookies',
     'inhalt' => "Die Website verwendet keine Analyse-, Marketing- oder Werbe-Cookies. Für Anmeldung, Mitgliederbereich, Backoffice, Formulare und sicherheitsrelevante Funktionen kann ein technisch notwendiges Sitzungs-Cookie mit der Bezeichnung PHPSESSID eingesetzt werden. Das Cookie enthält eine zufällige Sitzungskennung und wird mit Secure, HttpOnly und SameSite=Strict geschützt. Es wird grundsätzlich beim Schließen des Browsers beendet.\n\nAnonyme Besucher der öffentlichen Startseite erhalten keine Sitzung, solange keine sitzungsabhängige Funktion genutzt wird. Rechtsgrundlage ist § 25 Abs. 2 Nr. 2 TDDDG; die anschließende Verarbeitung erfolgt auf Grundlage von Art. 6 Abs. 1 lit. b beziehungsweise lit. f DSGVO."],

    ['titel' => '4. Kontaktformular und allgemeine Anfragen',
     'inhalt' => "Bei einer Kontaktaufnahme verarbeiten wir Name, E-Mail-Adresse, Betreff, Nachrichteninhalt sowie Datum und Bearbeitungsstatus. Die Angaben werden an die für das Thema zuständigen Funktionsträger des Vereins übermittelt und im geschützten Backoffice zur Bearbeitung gespeichert.\n\nRechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO, soweit es um vorvertragliche oder vertragliche Anliegen geht, andernfalls Art. 6 Abs. 1 lit. f DSGVO. Unser berechtigtes Interesse liegt in der Beantwortung und nachvollziehbaren Bearbeitung von Vereinsanfragen."],

    ['titel' => '5. Vereinshaus-Buchungsanfragen',
     'inhalt' => "Für Buchungsanfragen verarbeiten wir Name, E-Mail-Adresse, Telefonnummer, gewünschte Termine, Personenzahl, Anlass und den Bearbeitungsstatus. Bei bestätigten Buchungen können außerdem vereinbarte Leistungen, Zahlungs- und Kautionsangaben sowie zugehörige Korrespondenz verarbeitet werden. Die Daten sind nur für entsprechend berechtigte Funktionsträger zugänglich.\n\nRechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO zur Durchführung vorvertraglicher Maßnahmen und des Nutzungsverhältnisses. Soweit gesetzliche Nachweis- oder Aufbewahrungspflichten bestehen, erfolgt die Verarbeitung zusätzlich nach Art. 6 Abs. 1 lit. c DSGVO."],

    ['titel' => '6. Veranstaltungsanmeldungen',
     'inhalt' => "Bei einer Veranstaltungsanmeldung verarbeiten wir Name, E-Mail-Adresse, Telefonnummer, Zahl der teilnehmenden Personen und gegebenenfalls freiwillige veranstaltungsbezogene Angaben. Die Daten dienen der Planung, Kommunikation, Teilnehmerverwaltung und gegebenenfalls der Stornierung.\n\nRechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO, soweit mit der Anmeldung ein Teilnahmeverhältnis begründet wird, andernfalls Art. 6 Abs. 1 lit. f DSGVO aufgrund unseres Interesses an einer verlässlichen Veranstaltungsorganisation."],

    ['titel' => '7. Mitgliederanträge und Mitgliederbereich',
     'inhalt' => "Bei der Beantragung und Nutzung eines Mitgliederkontos verarbeiten wir insbesondere Name, E-Mail-Adresse, Telefonnummer, Parzelle, Mitgliedsrolle, Kontostatus, Einwilligungsangaben sowie technische Login- und Aktivitätsdaten. Abhängig von der Vereinsverwaltung können weitere für das Mitgliedschaftsverhältnis erforderliche Stammdaten, Jubiläums- oder Geburtstagsangaben hinterlegt werden. Passwörter werden nicht im Klartext, sondern ausschließlich als kryptografische Prüfsumme gespeichert.\n\nIm Mitgliederbereich können Beiträge, Nachrichten, Dateien, Anträge, Arbeitsstunden und Schadenmeldungen verarbeitet werden. Über die Antragsfunktion – einschließlich Bauanträgen – können Mitglieder Titel, Anliegen, ihre Parzelle sowie freiwillig beigefügte Dokumente und Fotos (etwa Baupläne oder Skizzen) übermitteln. Diese Angaben werden im geschützten Schriftführungs-Bereich bearbeitet, mit einem Bearbeitungsstatus versehen und dem antragstellenden Mitglied im Mitgliederbereich angezeigt; eine Entscheidung des Vorstands wird dem Mitglied nebst etwaiger Begründung und Entscheidungsdokumente per E-Mail und im Mitgliederbereich mitgeteilt. Zugriff erhalten nur angemeldete und nach Rollen berechtigte Personen. Rechtsgrundlagen sind Art. 6 Abs. 1 lit. b DSGVO für das Mitgliedschaftsverhältnis, Art. 6 Abs. 1 lit. c DSGVO für rechtliche Pflichten, Art. 6 Abs. 1 lit. f DSGVO für eine sichere und ordnungsgemäße Vereinsverwaltung sowie Art. 6 Abs. 1 lit. a DSGVO für ausdrücklich freiwillige Einwilligungen."],

    ['titel' => '8. E-Mail-Versand und Empfänger',
     'inhalt' => "Formularbestätigungen, Passwort-Reset-Nachrichten, Buchungs-, Veranstaltungs- und Vereinskommunikation werden per E-Mail versendet. Dabei verarbeiten die beteiligten Mailanbieter Absender- und Empfängeradresse, technische Zustelldaten sowie den Nachrichteninhalt. Empfänger können – abhängig von Zuständigkeit und hinterlegter Vereinsadresse – auch Postfächer bei Google sein. Dienste für Nutzer im Europäischen Wirtschaftsraum werden von Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland, angeboten. Dabei kann eine Verarbeitung durch verbundene Unternehmen oder Dienstleister in Drittländern nicht vollständig ausgeschlossen werden.\n\nRechtsgrundlage entspricht jeweils dem zugrunde liegenden Anliegen, insbesondere Art. 6 Abs. 1 lit. b, lit. c oder lit. f DSGVO."],

    ['titel' => '9. Lokale Schriften, Karte und externe Links',
     'inhalt' => "Die verwendeten Schriften werden lokal von unserem Webserver geladen. Beim Seitenaufruf wird daher keine Verbindung zu Google Fonts hergestellt. Die Anfahrtskarte wird als lokal gespeichertes Bild angezeigt. Erst wenn Sie die Karte bewusst anklicken, öffnet sich OpenStreetMap in einem neuen Fenster; dabei gelten die Datenschutzbestimmungen des externen Anbieters. Dasselbe gilt für andere externe Links, die erst nach einem bewussten Klick aufgerufen werden."],

    ['titel' => '10. Kategorien von Empfängern',
     'inhalt' => "Empfänger personenbezogener Daten können im erforderlichen Umfang sein: berechtigte Vorstandsmitglieder und Funktionsträger, der Hosting- und E-Mail-Anbieter, vertraglich gebundene technische Dienstleister, Banken und Zahlungsdienstleister bei zahlungsbezogenen Vorgängen sowie Behörden, Gerichte oder sonstige Stellen, wenn eine gesetzliche Verpflichtung besteht. Eine Übermittlung zu Werbezwecken findet nicht statt."],

    ['titel' => '11. Speicherdauer und Löschung',
     'inhalt' => "Wir speichern personenbezogene Daten nur so lange, wie dies für den jeweiligen Zweck erforderlich ist. Als Regelfristen gelten: Rate-Limit-Dateien höchstens 24 Stunden; abgelaufene Passwort-Reset-Daten höchstens bis zur technischen Bereinigung; erledigte allgemeine Kontaktanfragen grundsätzlich bis zu sechs Monate; abgelehnte oder abgelaufene Buchungsanfragen grundsätzlich bis zu sechs Monate; Veranstaltungsanmeldungen grundsätzlich bis zu drei Monate nach Veranstaltungsende; einfache Mitgliederanträge grundsätzlich bis zu drei Monate nach Abschluss.\n\nBauanträge nebst zugehörigen Plänen, Fotos und Entscheidungsdokumenten werden für die Dauer der baulichen und vereinsrechtlichen Relevanz aufbewahrt und anschließend gelöscht; die zugehörigen Dateien werden spätestens mit der Löschung des jeweiligen Antrags entfernt. Daten aus bestätigten Buchungen, Zahlungsunterlagen, Beschlüssen, Protokollen und Mitgliedschaftsverhältnissen können länger gespeichert werden, soweit dies für Vertragsdurchführung, Vereinsverwaltung, Rechtsverteidigung oder gesetzliche handels- und steuerrechtliche Aufbewahrungspflichten erforderlich ist. Nach Wegfall des Zwecks und Ablauf einschlägiger Fristen werden die Daten gelöscht oder anonymisiert."],

    ['titel' => '12. Betroffenenrechte und Beschwerderecht',
     'inhalt' => "Sie haben nach Maßgabe der gesetzlichen Voraussetzungen das Recht auf Auskunft (Art. 15 DSGVO), Berichtigung (Art. 16 DSGVO), Löschung (Art. 17 DSGVO), Einschränkung der Verarbeitung (Art. 18 DSGVO), Datenübertragbarkeit (Art. 20 DSGVO) und Widerspruch (Art. 21 DSGVO). Erteilte Einwilligungen können jederzeit mit Wirkung für die Zukunft widerrufen werden.\n\nSie können sich außerdem bei einer Datenschutzaufsichtsbehörde beschweren. Zuständig ist insbesondere der Hamburgische Beauftragte für Datenschutz und Informationsfreiheit, Ludwig-Erhard-Straße 22, 20459 Musterstadt, E-Mail: mailbox@datenschutz.hamburg.de."],

    ['titel' => '13. Datensicherheit und automatisierte Entscheidungen',
     'inhalt' => "Die Übertragung erfolgt verschlüsselt über HTTPS. Wir setzen angemessene technische und organisatorische Maßnahmen wie Zugriffskontrollen, rollenbasierte Berechtigungen, sichere Sitzungseinstellungen, Passwort-Hashing und Schutzmechanismen gegen automatisierte Anmeldeversuche ein. Eine ausschließlich automatisierte Entscheidungsfindung einschließlich Profiling im Sinne von Art. 22 DSGVO findet nicht statt."],

    ['titel' => '14. Aktualität',
     'inhalt' => 'Diese Datenschutzerklärung hat den Stand August 2026. Wir passen sie an, wenn Funktionen, Datenverarbeitungen oder rechtliche Anforderungen geändert werden.'],
];
function he(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Datenschutzerklärung der Muster-Kleingartenverein e.V.">
<link rel="canonical" href="<?= site_url() ?>/datenschutz.php">
<title>Datenschutz – Muster-Kleingartenverein</title>
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
.date-info{color:var(--text-gray);margin-bottom:2rem;font-style:italic}
h2{color:var(--primary-green);margin-bottom:1rem;margin-top:2.5rem;font-size:1.4rem}
h2:first-of-type{margin-top:0}
p{margin-bottom:1rem;line-height:1.8}
.info-box{background:var(--bg-light);padding:1.5rem;border-radius:10px;margin:1.5rem 0}
.info-box p{margin-bottom:0.4rem}
.highlight-box{background:rgba(139,195,74,0.1);padding:1.5rem;border-radius:10px;margin:2rem 0;border-left:4px solid var(--light-green)}
.ds-section{margin-top:2rem}
.ds-section p{margin-bottom:0.8rem}
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
        <img src="/logo.png" alt="Muster-Kleingartenverein e.V. Logo">
      </div>
    </div>
    <a href="/" class="back-link">← Zurück zur Startseite</a>
  </nav>
</header>

<main>
  <div class="container">
    <h1>Datenschutzerklärung</h1>
    <div class="content-box">
      <p class="date-info">Stand: <?= he($ds['stand']) ?></p>

      <p>Wir informieren Sie hier transparent darüber, welche personenbezogenen Daten beim Besuch und bei der Nutzung unserer Website verarbeitet werden. Wir verkaufen keine personenbezogenen Daten und setzen keine Analyse- oder Werbetracker ein.</p>

      <h2>1. Verantwortlicher</h2>
      <div class="info-box">
        <p><strong>Muster-Kleingartenverein e.V.</strong></p>
        <p><?= he($ds['adresse']) ?></p>
        <p>Telefon: <?= he($ds['telefon']) ?></p>
        <p>E-Mail: <a href="mailto:<?= he($ds['email']) ?>" style="color:var(--primary-green)"><?= he($ds['email']) ?></a></p>
      </div>
      <p>Vertreten durch den Vorstand: <?= he($ds['verantwortlicher']) ?></p>

      <?php foreach ($privacySections as $sec): ?>
      <div class="ds-section">
        <h2><?= he($sec['titel']) ?></h2>
        <?php foreach (explode("\n", $sec['inhalt']) as $para): $para = trim($para); if ($para !== ''): ?>
        <p><?= he($para) ?></p>
        <?php endif; endforeach; ?>
      </div>
      <?php endforeach; ?>

      <div class="highlight-box">
        <p style="margin:0"><strong>Bei Fragen zum Datenschutz:</strong><br>
        Wenn Sie Fragen zur Erhebung, Verarbeitung oder Nutzung Ihrer personenbezogenen Daten haben, kontaktieren Sie uns bitte unter: <?= he($ds['email']) ?></p>
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
