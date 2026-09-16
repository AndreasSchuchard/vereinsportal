<?php
declare(strict_types=1);
require_once __DIR__ . '/settings_loader.php';


/**
 * Schriftführerin-Cockpit für unser Verein
 *
 * Module:
 *   - sf_inbox()         — Dashboard mit Aktion-Items
 *   - sf_birthdays()     — Geburtstags- und Ehrungs-Radar
 *   - sf_letters()       — Standardbrief-Generator + Vorlagen
 *   - sf_canva()         — Canva-Bibliothek (Sandras Designs)
 *   - sf_protocols()     — Sitzungsprotokoll-Werkstatt
 *
 * Daten unter data/schriftfuehrung/:
 *   protocols.json          — Sitzungs-Protokolle
 *   letter_templates.json   — Briefvorlagen
 *   letters.json            — Versandte Briefe (Log)
 *   canva.json              — Canva-Bibliothek (Metadaten)
 *   uploads/canva/*         — Canva-Dateien
 */

define('SF_DIR',           dirname(__DIR__) . '/data/schriftfuehrung');
define('SF_PROTOCOLS',     SF_DIR . '/protocols.json');
define('SF_LETTER_TPL',    SF_DIR . '/letter_templates.json');
define('SF_LETTERS_LOG',   SF_DIR . '/letters.json');
define('SF_CANVA',         SF_DIR . '/canva.json');
define('SF_UPLOAD_CANVA',  SF_DIR . '/uploads/canva');
define('SF_NEWSLETTERS',   SF_DIR . '/newsletters.json');
define('SF_DOCUMENTS',     SF_DIR . '/documents.json');
define('SF_UPLOAD_DOCS',   SF_DIR . '/uploads/docs');
define('SF_NOTES',         SF_DIR . '/notes.json');

require_once __DIR__ . '/email_template.php';

/* ───────────────────────────────────────────────────────────────────────── */
/*  Storage Helpers                                                          */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_ensure_dirs(): void {
    $dirs = [SF_DIR, SF_DIR . '/uploads', SF_UPLOAD_CANVA, SF_UPLOAD_DOCS];
    if (defined('SF_UPLOAD_PROTOCOLS')) $dirs[] = SF_UPLOAD_PROTOCOLS;
    if (defined('SF_UPLOAD_SCHAEDEN'))  $dirs[] = SF_UPLOAD_SCHAEDEN;
    if (defined('SF_UPLOAD_ANTRAEGE'))  $dirs[] = SF_UPLOAD_ANTRAEGE;
    foreach ($dirs as $d) {
        if (!is_dir($d)) @mkdir($d, 0755, true);
    }
}

function sf_load_json(string $path): array {
    if (!file_exists($path)) return [];
    $d = json_decode((string)@file_get_contents($path), true);
    return is_array($d) ? $d : [];
}

function sf_save_json(string $path, array $data): bool {
    sf_ensure_dirs();
    return (bool)@file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function sf_uniq_id(string $prefix = 'sf'): string {
    return $prefix . '_' . time() . '_' . bin2hex(random_bytes(3));
}

/**
 * Nimmt einen Datei-Upload (<input type="file" multiple>) entgegen, prüft MIME/Größe,
 * legt die Dateien in SF_UPLOAD_ANTRAEGE ab und gibt Metadaten-Einträge zurück.
 * Erlaubt: pdf, jpg, png, heic · max. 25 MB/Datei · $maxFiles Dateien.
 *
 * @return list<array{id:string,title:string,filename:string,mime:string,size:int,uploaded_at:string}>
 */
function sf_store_antrag_uploads(string $field, int $maxFiles = 6): array {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'] ?? null)) return [];
    if (!defined('SF_UPLOAD_ANTRAEGE')) return [];
    sf_ensure_dirs();

    $extByMime = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/heic'      => 'heic',
        'image/heif'      => 'heic',
    ];
    $out   = [];
    $names = $_FILES[$field]['name'];
    $count = 0;
    foreach ($names as $i => $origName) {
        if ($count >= $maxFiles) break;
        if (($_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $tmp  = (string)($_FILES[$field]['tmp_name'][$i] ?? '');
        $size = (int)($_FILES[$field]['size'][$i] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) continue;
        if ($size <= 0 || $size > 25 * 1024 * 1024) continue;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = (string)finfo_file($finfo, $tmp);
        finfo_close($finfo);
        if (!isset($extByMime[$mime])) continue;

        $ext   = $extByMime[$mime];
        $fid   = sf_uniq_id('af');
        $fname = $fid . '.' . $ext;
        if (!@move_uploaded_file($tmp, SF_UPLOAD_ANTRAEGE . '/' . $fname)) continue;

        $title = trim(strip_tags((string)$origName));
        if ($title === '') $title = 'Anhang.' . $ext;
        $out[] = [
            'id'          => $fid,
            'title'       => mb_substr($title, 0, 160),
            'filename'    => $fname,
            'mime'        => $mime === 'image/heif' ? 'image/heic' : $mime,
            'size'        => $size,
            'uploaded_at' => date('Y-m-d H:i:s'),
        ];
        $count++;
    }
    return $out;
}

/** Löscht die physischen Dateien einer Anhang-Metadatenliste. */
function sf_delete_antrag_files(array $files): void {
    if (!defined('SF_UPLOAD_ANTRAEGE')) return;
    foreach ($files as $f) {
        $fn = (string)($f['filename'] ?? '');
        if ($fn !== '' && preg_match('/^[a-zA-Z0-9_.\-]+$/', $fn)) {
            @unlink(SF_UPLOAD_ANTRAEGE . '/' . $fn);
        }
    }
}

function sf_member_list(): array {
    $f = dirname(__DIR__) . '/data/members.json';
    return sf_load_json($f);
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Default Templates                                                        */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_default_letter_templates(): array {
    return [
        [
            'id'       => 'tpl_welcome',
            'title'    => 'Begrüßungsschreiben Neumitglied',
            'icon'     => '👋',
            'subject'  => 'Herzlich willkommen im unser Verein, {name}!',
            'body'     => "Liebe/r {name},\n\nherzlich willkommen in unserer Muster-Kleingartenverein e.V.!\n\nWir freuen uns sehr, dass du dich für unseren Verein entschieden hast und ab sofort Parzelle {parzelle} dein Zuhause auf Zeit ist.\n\nIn den nächsten Tagen erreichen dich noch folgende Unterlagen:\n- Vereinssatzung\n- Gartenordnung\n- Liste der Vereinstermine\n\nBei Fragen steht dir der Vorstand jederzeit zur Verfügung — wir sind nur einen Anruf oder eine Mail entfernt.\n\nWir wünschen dir eine schöne und entspannte Zeit in deinem neuen Garten!\n\nHerzliche Grüße",
        ],
        [
            'id'       => 'tpl_birthday',
            'title'    => 'Geburtstagsgruß',
            'icon'     => '🎂',
            'subject'  => 'Alles Gute zum Geburtstag, {name}!',
            'body'     => "Liebe/r {name},\n\nzu deinem Geburtstag senden wir dir die herzlichsten Glückwünsche!\n\nWir wünschen dir ein wunderbares Lebensjahr voller Sonnenschein, Gesundheit, Lebensfreude und natürlich vieler entspannter Stunden in deinem Garten.\n\nBleib so wie du bist und genieße deinen Ehrentag mit deinen Liebsten.\n\nHerzliche Glückwünsche im Namen des gesamten Vorstands",
        ],
        [
            'id'       => 'tpl_condolence',
            'title'    => 'Kondolenzschreiben',
            'icon'     => '🕯',
            'subject'  => 'Unsere Anteilnahme',
            'body'     => "Liebe Familie {name},\n\nmit großer Bestürzung haben wir vom Verlust eines lieben Menschen erfahren.\n\nIn dieser schweren Zeit sind unsere Gedanken bei euch. Wir wünschen euch viel Kraft und Trost und möchten euch versichern, dass wir in Gedanken an eurer Seite sind.\n\nFalls wir euch in irgendeiner Form unterstützen können, zögert bitte nicht, uns anzusprechen.\n\nIn stiller Anteilnahme\nDer Vorstand der Muster-Kleingartenverein e.V.",
        ],
        [
            'id'       => 'tpl_dues_reminder',
            'title'    => 'Zahlungserinnerung Mitgliedsbeitrag',
            'icon'     => '💶',
            'subject'  => 'Erinnerung: Mitgliedsbeitrag {year}',
            'body'     => "Liebe/r {name},\n\nwir möchten dich freundlich daran erinnern, dass dein Mitgliedsbeitrag für das Jahr {year} noch nicht auf unserem Vereinskonto eingegangen ist.\n\nBitte überweise den fälligen Betrag innerhalb der nächsten 14 Tage auf unser Vereinskonto. Solltest du den Beitrag bereits überwiesen haben, betrachte diese Erinnerung bitte als gegenstandslos.\n\nBei Rückfragen oder im Falle einer wirtschaftlich schwierigen Situation melde dich gerne — wir finden gemeinsam eine Lösung.\n\nMit freundlichen Grüßen",
        ],
        [
            'id'       => 'tpl_anniversary',
            'title'    => 'Ehrung Vereinsjubiläum',
            'icon'     => '🏅',
            'subject'  => 'Herzliche Glückwünsche zum {jahre}-jährigen Vereinsjubiläum',
            'body'     => "Liebe/r {name},\n\nin diesem Jahr feierst du dein {jahre}-jähriges Vereinsjubiläum bei uns in der Muster-Kleingartenverein e.V.\n\nDas ist ein wunderschöner Anlass, dir für deine langjährige Treue und dein Engagement zu danken. Mitglieder wie du sind das Rückgrat unseres Vereins, und wir sind dankbar, dich seit {jahre} Jahren in unseren Reihen zu haben.\n\nIn Anerkennung deiner Verdienste laden wir dich herzlich zur nächsten Mitgliederversammlung ein, in deren Rahmen wir die Ehrung offiziell vornehmen möchten.\n\nMit herzlichen Glückwünschen und freundlichen Grüßen",
        ],
        [
            'id'       => 'tpl_invitation_meeting',
            'title'    => 'Einladung zur Mitgliederversammlung',
            'icon'     => '📅',
            'subject'  => 'Einladung zur Mitgliederversammlung am {datum}',
            'body'     => "Liebe Vereinsmitglieder,\n\nhiermit lade ich euch satzungsgemäß zur diesjährigen Mitgliederversammlung ein.\n\nTermin: {datum}, {uhrzeit} Uhr\nOrt: Vereinshaus der Muster-Kleingartenverein e.V.\n\nTagesordnung:\n1. Begrüßung und Feststellung der Beschlussfähigkeit\n2. Genehmigung des Protokolls der letzten Sitzung\n3. Berichte des Vorstands\n4. Kassenbericht und Bericht der Kassenprüfer\n5. Entlastung des Vorstands\n6. Wahlen / Anträge\n7. Verschiedenes\n\nAnträge zur Tagesordnung bitten wir bis spätestens 7 Tage vor der Versammlung schriftlich beim Vorstand einzureichen.\n\nWir freuen uns auf rege Teilnahme!\n\nMit freundlichen Grüßen",
        ],
    ];
}

function sf_default_protocol_tops(): array {
    return [
        ['top' => 'TOP 1', 'title' => 'Begrüßung und Feststellung der Beschlussfähigkeit'],
        ['top' => 'TOP 2', 'title' => 'Genehmigung des Protokolls der letzten Sitzung'],
        ['top' => 'TOP 3', 'title' => 'Berichte (Vorsitz, Kassier, Vermietung, …)'],
        ['top' => 'TOP 4', 'title' => 'Aktuelle Themen und Beschlüsse'],
        ['top' => 'TOP 5', 'title' => 'Verschiedenes'],
    ];
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Geburtstags- und Ehrungs-Logik                                            */
/* ───────────────────────────────────────────────────────────────────────── */

/**
 * Liefert kommende Geburtstage in den nächsten N Tagen.
 * Erwartet member-Felder: 'geburtstag' im Format MM-TT oder YYYY-MM-TT.
 */
function sf_upcoming_birthdays(int $days = 30): array {
    $today    = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
    $cutoff   = $today->modify("+{$days} days");
    $thisYear = (int)$today->format('Y');

    $out = [];
    foreach (sf_member_list() as $m) {
        if (empty($m['active'])) continue;
        $b = trim((string)($m['geburtstag'] ?? ''));
        if ($b === '') continue;

        // Akzeptiere MM-TT oder YYYY-MM-TT
        $monthDay = null;
        $birthYear = null;
        if (preg_match('/^(\d{2})-(\d{2})$/', $b, $mm)) {
            $monthDay = $mm[1] . '-' . $mm[2];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $b, $mm)) {
            $monthDay = $mm[2] . '-' . $mm[3];
            $birthYear = (int)$mm[1];
        } else {
            continue;
        }

        // Geburtstag dieses Jahr
        try {
            $bdayThis = new DateTimeImmutable($thisYear . '-' . $monthDay, new DateTimeZone('Europe/Berlin'));
        } catch (\Throwable $e) { continue; }

        // Wenn schon vorbei → nächstes Jahr probieren
        if ($bdayThis < $today) {
            try {
                $bdayThis = new DateTimeImmutable(($thisYear + 1) . '-' . $monthDay, new DateTimeZone('Europe/Berlin'));
            } catch (\Throwable $e) { continue; }
        }

        if ($bdayThis > $cutoff) continue;

        $age = $birthYear ? ((int)$bdayThis->format('Y') - $birthYear) : null;
        $daysUntil = (int)$today->diff($bdayThis)->format('%a');

        $out[] = [
            'member_id'  => $m['id'] ?? '',
            'name'       => $m['name'] ?? '',
            'email'      => $m['email'] ?? '',
            'date'       => $bdayThis->format('Y-m-d'),
            'days_until' => $daysUntil,
            'age'        => $age,
        ];
    }

    usort($out, fn($a, $b) => $a['days_until'] <=> $b['days_until']);
    return $out;
}

/**
 * Liefert anstehende Jubiläen (10/25/40/50 Jahre) in den nächsten 90 Tagen.
 * Erwartet member-Feld 'member_since' = YYYY-MM-TT.
 */
function sf_upcoming_anniversaries(int $days = 90): array {
    $today    = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
    $cutoff   = $today->modify("+{$days} days");
    $milestones = [10, 25, 40, 50, 60];

    $out = [];
    foreach (sf_member_list() as $m) {
        if (empty($m['active'])) continue;
        $ms = trim((string)($m['member_since'] ?? ''));
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ms, $mm)) continue;

        $year     = (int)$mm[1];
        $monthDay = $mm[2] . '-' . $mm[3];

        foreach ($milestones as $milestone) {
            try {
                $annivDate = new DateTimeImmutable(($year + $milestone) . '-' . $monthDay, new DateTimeZone('Europe/Berlin'));
            } catch (\Throwable $e) { continue; }
            if ($annivDate < $today || $annivDate > $cutoff) continue;
            $out[] = [
                'member_id'  => $m['id'] ?? '',
                'name'       => $m['name'] ?? '',
                'email'      => $m['email'] ?? '',
                'date'       => $annivDate->format('Y-m-d'),
                'days_until' => (int)$today->diff($annivDate)->format('%a'),
                'years'      => $milestone,
            ];
        }
    }
    usort($out, fn($a, $b) => $a['days_until'] <=> $b['days_until']);
    return $out;
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Letter-Template Logic                                                    */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_get_letter_templates(): array {
    $stored = sf_load_json(SF_LETTER_TPL);
    if (empty($stored)) return sf_default_letter_templates();
    return $stored;
}

function sf_apply_letter_variables(string $text, array $vars): string {
    foreach ($vars as $k => $v) {
        $text = str_replace('{' . $k . '}', (string)$v, $text);
    }
    return $text;
}

function sf_letter_pdf(array $tpl, array $member, array $vars, array $settings = []): string {
    require_once __DIR__ . '/fpdf.php';

    $e = fn(string $s): string => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;

    $body    = sf_apply_letter_variables((string)($tpl['body'] ?? ''),    $vars);
    $subject = sf_apply_letter_variables((string)($tpl['subject'] ?? ''), $vars);
    $name    = (string)($member['name'] ?? '');
    $address = trim((string)($member['address'] ?? ''));
    $parzelle= trim((string)($member['parzelle'] ?? ''));
    try { $today = (new DateTimeImmutable('today'))->format('d.m.Y'); }
    catch (\Throwable $err) { $today = date('d.m.Y'); }

    $vorstand = (string)($settings['kontakt_name']  ?? 'Max Mustermann');
    $rolle    = (string)($settings['kontakt_rolle'] ?? '1. Vorsitzender');
    $iban     = (string)($settings['iban']          ?? '');
    $bank     = (string)($settings['bank']          ?? '');

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(20, 12, 20);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();
    $W = 170;

    // ── Briefkopf (italic Adresse + Vorsitzender + Logo) ───────────────
    sf_briefkopf_top($pdf, $settings, 12);
    $pdf->Ln(14);

    // ── Empfängeranschrift (links, klein, normal) ──────────────────────
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($W, 5, $e($name), 0, 1);
    if ($address !== '') $pdf->Cell($W, 5, $e($address), 0, 1);
    if ($parzelle !== '') {
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->Cell($W, 4, $e('Parzelle: ' . $parzelle), 0, 1);
    }
    $pdf->Ln(4);

    // ── Datum (rechtsbündig) ────────────────────────────────────────────
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($W, 5, $e('Musterstadt, ' . $today), 0, 1, 'R');
    $pdf->Ln(8);

    // ── Betreff: groß, fett, unterstrichen ─────────────────────────────
    $pdf->SetFont('Helvetica', 'BU', 12);
    $pdf->MultiCell($W, 6, $e($subject), 0, 'L');
    $pdf->Ln(4);

    // ── Body (Fließtext) ────────────────────────────────────────────────
    sf_body_paragraph($pdf, $body);
    $pdf->Ln(8);

    // ── Signatur ────────────────────────────────────────────────────────
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($W, 5, $e('Mit freundlichen Grüßen'), 0, 1);
    $pdf->Ln(8);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell($W, 5, $e($vorstand), 0, 1);
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell($W, 4, $e($rolle . ' · unser Verein'), 0, 1);

    // ── Footer mit IBAN ─────────────────────────────────────────────────
    $pdf->SetY(-14);
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetTextColor(140, 140, 140);
    $pdf->Cell($W, 4,
        $e('Muster-Kleingartenverein e.V.'
        . ($bank ? '    ' . $bank : '')
        . ($iban ? '    IBAN: ' . $iban : '')),
        0, 1, 'C');

    return $pdf->Output('S');
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Inbox-Counter                                                            */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_inbox_counts(): array {
    $cnt = ['birthdays' => 0, 'anniversaries' => 0, 'contacts' => 0, 'bookings_pending' => 0, 'reminders_due' => 0];

    $cnt['birthdays']     = count(sf_upcoming_birthdays(14));
    $cnt['anniversaries'] = count(sf_upcoming_anniversaries(90));

    $contactsFile = dirname(__DIR__) . '/data/contacts.json';
    foreach (sf_load_json($contactsFile) as $c) {
        if (empty($c['read_at']) && empty($c['replied_at'])) $cnt['contacts']++;
    }

    $bookingsFile = dirname(__DIR__) . '/data/bookings.json';
    foreach (sf_load_json($bookingsFile) as $b) {
        if (($b['status'] ?? '') === 'pending') $cnt['bookings_pending']++;
    }

    // Fällige Wiedervorlagen
    $today = date('Y-m-d');
    foreach (sf_load_json(SF_NOTES) as $n) {
        if (!empty($n['done'])) continue;
        $rem = trim((string)($n['reminder'] ?? ''));
        if ($rem !== '' && $rem <= $today) $cnt['reminders_due']++;
    }

    return $cnt;
}

/* Canva Quick-Templates für Sandra */
function sf_canva_quick_templates(): array {
    return [
        ['icon'=>'🎂','title'=>'Glückwunsch-Karte (5×7)','desc'=>'Schöne Geburtstagskarte mit Vereinsfarben','url'=>'https://www.canva.com/de_de/erstellen/grusskarten/'],
        ['icon'=>'🏅','title'=>'Ehrungs-Urkunde','desc'=>'Klassische Urkunde für Vereinsjubiläen','url'=>'https://www.canva.com/de_de/erstellen/zertifikate/'],
        ['icon'=>'📨','title'=>'Newsletter-Banner','desc'=>'Banner-Bild oben in der Mail (1200×400 px)','url'=>'https://www.canva.com/de_de/erstellen/newsletter/'],
        ['icon'=>'🎪','title'=>'Veranstaltungs-Flyer','desc'=>'A4-Aushang für Vereinsfeste','url'=>'https://www.canva.com/de_de/erstellen/flyer/'],
        ['icon'=>'📝','title'=>'Briefkopf','desc'=>'Eleganter Briefkopf als A4-Header','url'=>'https://www.canva.com/de_de/erstellen/briefkopf/'],
        ['icon'=>'📅','title'=>'Mitgliederversammlung Einladung','desc'=>'Einladungs-Layout mit Logo & Datum','url'=>'https://www.canva.com/de_de/erstellen/einladungen/'],
        ['icon'=>'📌','title'=>'Pinnwand-Aushang','desc'=>'Auffälliger Hinweis für die Vereinspinnwand','url'=>'https://www.canva.com/de_de/erstellen/poster/'],
        ['icon'=>'📰','title'=>'Vereinszeitung','desc'=>'Mehrseitige PDF-Vereinsnachrichten','url'=>'https://www.canva.com/de_de/erstellen/zeitung/'],
    ];
}


/* ───────────────────────────────────────────────────────────────────────── */
/*  Korrespondenz pro Mitglied                                               */
/* ───────────────────────────────────────────────────────────────────────── */

/**
 * Sammelt alle bekannten Interaktionen mit einem Mitglied:
 * - Letter-Log (Standardbriefe)
 * - Newsletter (Sandra → Mitglied)
 * - Kontaktanfragen (Mitglied → Verein)
 * - Buchungen (Mitglied → Vereinshaus)
 *
 * Sortiert nach Datum absteigend.
 */
function sf_member_correspondence(string $memberId, string $memberEmail = '', string $memberName = ''): array {
    $items = [];

    // Letters (sf_letters_log)
    foreach (sf_load_json(SF_LETTERS_LOG) as $l) {
        if (($l['member_id'] ?? '') === $memberId) {
            $items[] = [
                'type'    => 'letter',
                'icon'    => '📝',
                'when'    => $l['ts'] ?? '',
                'title'   => $l['template_title'] ?? ($l['template_id'] ?? 'Standardbrief'),
                'detail'  => 'Ausgang: ' . ($l['output'] ?? 'pdf'),
                'success' => !empty($l['success']),
            ];
        }
    }

    // Contact form submissions (Mitglied → Verein)
    if ($memberEmail !== '') {
        $contactsFile = dirname(__DIR__) . '/data/contacts.json';
        foreach (sf_load_json($contactsFile) as $c) {
            $em = strtolower(trim((string)($c['email'] ?? '')));
            if ($em === '' || strtolower($memberEmail) !== $em) continue;
            $items[] = [
                'type'    => 'contact_in',
                'icon'    => '📨',
                'when'    => $c['received_at'] ?? $c['ts'] ?? '',
                'title'   => 'Kontaktanfrage: ' . ($c['subject'] ?? '(ohne Betreff)'),
                'detail'  => mb_substr((string)($c['message'] ?? ''), 0, 140),
                'success' => true,
                'replied' => !empty($c['replied_at']),
            ];
        }
    }

    // Bookings (Mitglied bucht Vereinshaus)
    if ($memberEmail !== '' || $memberName !== '') {
        $bookingsFile = dirname(__DIR__) . '/data/bookings.json';
        foreach (sf_load_json($bookingsFile) as $b) {
            $em = strtolower(trim((string)($b['email'] ?? '')));
            $nm = trim((string)($b['name']  ?? ''));
            $matchEmail = $memberEmail !== '' && $em !== '' && strtolower($memberEmail) === $em;
            $matchName  = $memberName  !== '' && $nm !== '' && stripos($nm, $memberName) !== false;
            if (!$matchEmail && !$matchName) continue;
            $dates = (array)($b['dates'] ?? []);
            $first = $dates[0] ?? '';
            $items[] = [
                'type'    => 'booking',
                'icon'    => '🏠',
                'when'    => $b['created_at'] ?? '',
                'title'   => 'Vereinshaus-Buchung · ' . ($first !== '' ? date('d.m.Y', strtotime($first) ?: time()) : '?'),
                'detail'  => 'Status: ' . ($b['status'] ?? '?') . ' · ' . count($dates) . ' Tag(e) · ' . (int)($b['guests'] ?? 0) . ' Personen',
                'success' => true,
            ];
        }
    }

    // Newsletter sent (alle Newsletter zählen, da sie an alle Mitglieder gingen)
    foreach (sf_load_json(SF_NEWSLETTERS) as $n) {
        // Skip wenn audience='vorstand' und Mitglied nicht Vorstand → kann ich grob nicht prüfen
        // Pragmatisch: zeige nur wenn audience='all'
        $aud = $n['audience'] ?? 'all';
        if ($aud !== 'all') continue;
        $items[] = [
            'type'    => 'newsletter',
            'icon'    => '📬',
            'when'    => $n['sent_at'] ?? '',
            'title'   => 'Rundbrief: ' . ($n['subject'] ?? ''),
            'detail'  => 'An ' . (int)($n['recipient_count'] ?? 0) . ' Mitglieder',
            'success' => true,
        ];
    }

    usort($items, fn($a, $b) => strcmp($b['when'] ?? '', $a['when'] ?? ''));
    return $items;
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Beschluss-Datenbank                                                      */
/* ───────────────────────────────────────────────────────────────────────── */

/**
 * Extrahiert alle Beschlüsse aus allen Protokollen.
 * Optional Volltext-Filter (case-insensitive, sucht in title, top, beschluss, notes).
 */
function sf_all_beschluesse(string $filter = ''): array {
    $out = [];
    $filter = mb_strtolower(trim($filter), 'UTF-8');

    foreach (sf_load_json(SF_PROTOCOLS) as $p) {
        foreach (($p['tops'] ?? []) as $top) {
            $bes = trim((string)($top['beschluss'] ?? ''));
            if ($bes === '') continue;

            $haystack = mb_strtolower(
                ($p['title'] ?? '') . ' ' . ($top['top'] ?? '') . ' ' . ($top['title'] ?? '') . ' ' . $bes . ' ' . ($top['notes'] ?? ''),
                'UTF-8'
            );
            if ($filter !== '' && strpos($haystack, $filter) === false) continue;

            $out[] = [
                'protocol_id'    => $p['id']    ?? '',
                'protocol_title' => $p['title'] ?? '',
                'protocol_date'  => $p['date']  ?? '',
                'protocol_type'  => $p['type']  ?? '',
                'top_label'      => $top['top']   ?? '',
                'top_title'      => $top['title'] ?? '',
                'beschluss'      => $bes,
                'ja'             => $top['ja']   ?? null,
                'nein'           => $top['nein'] ?? null,
                'enth'           => $top['enth'] ?? null,
            ];
        }
    }

    usort($out, fn($a, $b) => strcmp($b['protocol_date'] ?? '', $a['protocol_date'] ?? ''));
    return $out;
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Druck-Studio: Urkunden + Adressetiketten                                 */
/* ───────────────────────────────────────────────────────────────────────── */

/**
 * Generiert eine offizielle Ehrungs-Urkunde als A4-Querformat-PDF.
 */
function sf_certificate_pdf(string $name, int $jahre, string $datum = '', string $textOverride = ''): string {
    require_once __DIR__ . '/fpdf.php';
    $e = fn(string $s): string => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;
    if ($datum === '') $datum = date('d.m.Y');

    $pdf = new FPDF('L', 'mm', 'A4'); // Querformat
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    // Schmückender Goldrahmen
    $pdf->SetDrawColor(184, 134, 11);
    $pdf->SetLineWidth(1.4);
    $pdf->Rect(8, 8, 297-16, 210-16);
    $pdf->SetLineWidth(0.4);
    $pdf->Rect(11, 11, 297-22, 210-22);

    // Logo zentriert oben
    $logoPath = dirname(__DIR__) . '/logo.png';
    if (file_exists($logoPath)) $pdf->Image($logoPath, (297/2)-15, 22, 30);

    // Titel
    $pdf->SetY(58);
    $pdf->SetFont('Helvetica', 'B', 36);
    $pdf->SetTextColor(184, 134, 11);
    $pdf->Cell(297, 12, $e('Ehrenurkunde'), 0, 1, 'C');

    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', '', 13);
    $pdf->SetTextColor(70, 70, 70);
    $pdf->Cell(297, 6, $e('Muster-Kleingartenverein e.V.'), 0, 1, 'C');

    $pdf->Ln(14);
    $pdf->SetFont('Helvetica', '', 14);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(297, 6, $e('Die Muster-Kleingartenverein e.V. verleiht'), 0, 1, 'C');

    $pdf->Ln(8);
    $pdf->SetFont('Helvetica', 'B', 28);
    $pdf->SetTextColor(61, 107, 65);
    $pdf->Cell(297, 14, $e($name), 0, 1, 'C');

    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', '', 14);
    $pdf->SetTextColor(0, 0, 0);
    if ($textOverride !== '') {
        $pdf->MultiCell(257, 7, $e($textOverride), 0, 'C');
        $pdf->SetX(20);
    } else {
        $pdf->Cell(297, 6, $e('diese Urkunde in dankbarer Anerkennung der'), 0, 1, 'C');
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', 'B', 18);
        $pdf->SetTextColor(184, 134, 11);
        $pdf->Cell(297, 8, $e($jahre . '-jährigen Mitgliedschaft'), 0, 1, 'C');
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->SetTextColor(70, 70, 70);
        $pdf->Cell(297, 5, $e('in unserer Gartengemeinschaft.'), 0, 1, 'C');
    }

    // Footer: Ort, Datum, Unterschrift
    $pdf->SetY(170);
    $pdf->SetFont('Helvetica', '', 11);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(297, 5, $e('Musterstadt, ' . $datum), 0, 1, 'C');

    $pdf->Ln(10);
    $pdf->SetDrawColor(120, 120, 120);
    $pdf->Line(80,  $pdf->GetY(), 130, $pdf->GetY());
    $pdf->Line(167, $pdf->GetY(), 217, $pdf->GetY());
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->Ln(2);
    $pdf->SetX(80);  $pdf->Cell(50, 4, $e('1. Vorsitzender'), 0, 0, 'C');
    $pdf->SetX(167); $pdf->Cell(50, 4, $e('Schriftführer/in'), 0, 1, 'C');

    return $pdf->Output('S');
}

/**
 * Generiert eine A4-Seite mit Adressetiketten (3 Spalten × 7 Zeilen = 21 Etiketten/Seite).
 */
function sf_address_labels_pdf(array $members): string {
    require_once __DIR__ . '/fpdf.php';
    $e = fn(string $s): string => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(false);

    $cols   = 3;
    $rows   = 7;
    $labelW = 70;
    $labelH = 38;
    $marginX = (210 - $cols * $labelW) / 2;
    $marginY = (297 - $rows * $labelH) / 2;

    $i = 0;
    foreach ($members as $m) {
        $row = (int)($i / $cols) % $rows;
        $col = $i % $cols;
        $page = (int)($i / ($cols * $rows));
        if ($i === 0 || ($row === 0 && $col === 0 && $i !== 0)) $pdf->AddPage();

        $x = $marginX + $col * $labelW;
        $y = $marginY + $row * $labelH;

        $pdf->SetDrawColor(220, 220, 220);
        $pdf->SetLineWidth(0.2);
        $pdf->Rect($x, $y, $labelW, $labelH);

        // Vereins-Absender (klein, oben)
        $pdf->SetXY($x + 3, $y + 3);
        $pdf->SetFont('Helvetica', '', 6);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->Cell($labelW - 6, 3, $e('Muster-Kleingartenverein e.V. · Musterstraße 1'), 0, 1);

        // Empfänger
        $pdf->SetXY($x + 5, $y + 11);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell($labelW - 10, 5, $e((string)($m['name'] ?? '')), 0, 1);

        $pdf->SetX($x + 5);
        $pdf->SetFont('Helvetica', '', 9);
        $addr = trim((string)($m['address'] ?? ''));
        if ($addr !== '') $pdf->Cell($labelW - 10, 4, $e($addr), 0, 1);
        $pdf->SetX($x + 5);
        $plzOrt = trim((string)($m['plz'] ?? '') . ' ' . (string)($m['ort'] ?? ''));
        if ($plzOrt !== ' ') $pdf->Cell($labelW - 10, 4, $e($plzOrt), 0, 1);

        // Parzelle
        if (!empty($m['parzelle'])) {
            $pdf->SetXY($x + 5, $y + $labelH - 7);
            $pdf->SetFont('Helvetica', '', 7);
            $pdf->SetTextColor(150, 150, 150);
            $pdf->Cell($labelW - 10, 4, $e('Parzelle ' . (string)$m['parzelle']), 0, 0);
        }

        $i++;
    }

    if ($i === 0) {
        // empty list → render one info page
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(0, 50, $e('Keine Empfänger ausgewählt.'), 0, 1, 'C');
    }

    return $pdf->Output('S');
}

/**
 * Generiert einen großformatigen Aushang (A4, Hochformat) mit Titel + Text.
 */
function sf_aushang_pdf(string $title, string $body, string $accentColor = '#3d6b41'): string {
    require_once __DIR__ . '/fpdf.php';
    $e = fn(string $s): string => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;

    // hex → RGB
    [$r, $g, $bl] = sscanf($accentColor, '#%02x%02x%02x');
    $r  = $r  ?? 61;
    $g  = $g  ?? 107;
    $bl = $bl ?? 65;

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();

    // Top-Band Akzent
    $pdf->SetFillColor($r, $g, $bl);
    $pdf->Rect(0, 0, 210, 38, 'F');

    // Logo
    $logoPath = dirname(__DIR__) . '/logo.png';
    if (file_exists($logoPath)) $pdf->Image($logoPath, 15, 5, 28);

    $pdf->SetY(12);
    $pdf->SetX(50);
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(140, 6, $e('Muster-Kleingartenverein e.V.'), 0, 1);
    $pdf->SetX(50);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->Cell(140, 4, $e('Aushang · ' . date('d.m.Y')), 0, 1);

    // Title
    $pdf->Ln(28);
    $pdf->SetFont('Helvetica', 'B', 32);
    $pdf->SetTextColor($r, $g, $bl);
    $pdf->MultiCell(180, 16, $e($title), 0, 'C');
    $pdf->Ln(4);

    $pdf->SetDrawColor($r, $g, $bl);
    $pdf->SetLineWidth(0.8);
    $pdf->Line(60, $pdf->GetY(), 150, $pdf->GetY());
    $pdf->Ln(8);

    // Body
    $pdf->SetFont('Helvetica', '', 14);
    $pdf->SetTextColor(0, 0, 0);
    foreach (explode("\n", $body) as $line) {
        if (trim($line) === '') { $pdf->Ln(4); continue; }
        $pdf->MultiCell(180, 7, $e($line), 0, 'C');
    }

    // Footer
    $pdf->SetY(-15);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(140, 140, 140);
    $pdf->Cell(0, 4, $e('Der Vorstand · unser Verein · Adresse des Vereins'), 0, 1, 'C');

    return $pdf->Output('S');
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Welle 4: Anträge, Statistik, Daily-Reminder, Canva-Karten                */
/* ───────────────────────────────────────────────────────────────────────── */

define('SF_APPLICATIONS',  SF_DIR . '/applications.json');
define('SF_CONFIG',        SF_DIR . '/config.json');
define('SF_UPLOAD_ANTRAEGE', SF_DIR . '/uploads/antraege');

/** Status-Labels für Antrags-Workflow (Reihenfolge = Dropdown-Reihenfolge) */
function sf_application_statuses(): array {
    return [
        'eingegangen'    => ['#1565c0', 'Eingegangen'],
        'offen'          => ['#0288d1', 'Offen (gelesen)'],
        'in_bearbeitung' => ['#f57c00', 'In Bearbeitung'],
        'genehmigt'      => ['#2e7d32', 'Genehmigt'],
        'abgelehnt'      => ['#c62828', 'Abgelehnt'],
        'erledigt'       => ['#607d8b', 'Erledigt'],
        'zurueckgezogen' => ['#9e9e9e', 'Zurückgezogen'],
    ];
}

/** Endstatus: nach dem Setzen ist eine Mitteilung an das Mitglied möglich/fällig. */
function sf_application_decision_statuses(): array {
    return ['genehmigt', 'abgelehnt', 'erledigt'];
}

/** Auswahlbare Antragsarten (Key => Label). */
function sf_application_arten(): array {
    return [
        'bauantrag'     => 'Bauantrag',
        'themenwunsch'  => 'Themenwunsch für Sitzung',
        'beschwerde'    => 'Beschwerde',
        'anregung'      => 'Anregung',
        'sonstiges'     => 'Sonstiger Antrag',
    ];
}

/** SF-Cockpit-Konfiguration (Empfänger Daily-Reminder etc.) */
function sf_config(): array {
    $defaults = [
        'daily_enabled' => true,
        'daily_to'      => 'schriftfuehrer@example.org',
        'daily_to_cc'   => '',
        'antrag_to'     => 'schriftfuehrer@example.org',
    ];
    $cfg = sf_load_json(SF_CONFIG);
    return array_merge($defaults, $cfg);
}

function sf_config_save(array $patch): void {
    $cfg = sf_load_json(SF_CONFIG);
    foreach ($patch as $k => $v) $cfg[$k] = $v;
    sf_save_json(SF_CONFIG, $cfg);
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Statistik-Dashboard                                                      */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_stats_overview(): array {
    $members  = sf_member_list();
    $bookings = sf_load_json(dirname(__DIR__) . '/data/bookings.json');
    $contacts = sf_load_json(dirname(__DIR__) . '/data/contacts.json');
    $events   = sf_load_json(dirname(__DIR__) . '/data/events.json');
    $newsletters = sf_load_json(SF_NEWSLETTERS);
    $letters  = sf_load_json(SF_LETTERS_LOG);
    $apps     = sf_load_json(SF_APPLICATIONS);

    // Mitglieder
    $mActive   = 0; $mInactive = 0;
    $mWithBday = 0; $mWithSince = 0;
    foreach ($members as $m) {
        if (!empty($m['active'])) $mActive++; else $mInactive++;
        if (!empty($m['geburtstag']))    $mWithBday++;
        if (!empty($m['member_since']))  $mWithSince++;
    }

    // Buchungen pro Monat (letzte 12 Monate)
    $monthly = [];
    $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
    for ($i = 11; $i >= 0; $i--) {
        $key = $today->modify("-{$i} months")->format('Y-m');
        $monthly[$key] = ['bookings' => 0, 'contacts' => 0, 'event_regs' => 0, 'letters' => 0];
    }
    foreach ($bookings as $b) {
        $k = substr((string)($b['created_at'] ?? ''), 0, 7);
        if (isset($monthly[$k])) $monthly[$k]['bookings']++;
    }
    foreach ($contacts as $c) {
        $k = substr((string)($c['received_at'] ?? $c['ts'] ?? ''), 0, 7);
        if (isset($monthly[$k])) $monthly[$k]['contacts']++;
    }
    foreach ($events as $e) {
        foreach (($e['registrations'] ?? []) as $r) {
            $k = substr((string)($r['submitted_at'] ?? ''), 0, 7);
            if (isset($monthly[$k])) $monthly[$k]['event_regs']++;
        }
    }
    foreach ($letters as $l) {
        $k = substr((string)($l['ts'] ?? ''), 0, 7);
        if (isset($monthly[$k])) $monthly[$k]['letters']++;
    }

    // Buchungs-Status-Verteilung
    $bookingStatus = ['pending'=>0,'confirmed'=>0,'rejected'=>0,'expired'=>0];
    foreach ($bookings as $b) {
        $s = $b['status'] ?? 'pending';
        if (isset($bookingStatus[$s])) $bookingStatus[$s]++;
    }

    // Antrags-Status
    $appStatus = [];
    foreach (sf_application_statuses() as $k => $_) $appStatus[$k] = 0;
    foreach ($apps as $a) {
        $s = $a['status'] ?? 'eingegangen';
        if (isset($appStatus[$s])) $appStatus[$s]++;
    }

    return [
        'members' => [
            'total'         => count($members),
            'active'        => $mActive,
            'inactive'      => $mInactive,
            'with_birthday' => $mWithBday,
            'with_since'    => $mWithSince,
        ],
        'bookings_total' => count($bookings),
        'bookings_status' => $bookingStatus,
        'contacts_total' => count($contacts),
        'events_total'   => count($events),
        'event_regs_total' => array_sum(array_map(fn($e) => count($e['registrations'] ?? []), $events)),
        'newsletters_sent' => count($newsletters),
        'letters_sent'  => count($letters),
        'applications_total' => count($apps),
        'applications_status' => $appStatus,
        'monthly' => $monthly,
    ];
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Daily-Reminder Briefing                                                  */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_daily_briefing(): array {
    $today  = date('Y-m-d');
    $bdays7 = sf_upcoming_birthdays(7);
    $bdaysToday = array_values(array_filter($bdays7, fn($b) => $b['days_until'] === 0));
    $bdaysSoon  = array_values(array_filter($bdays7, fn($b) => $b['days_until'] >= 1 && $b['days_until'] <= 7));

    $annivs30 = sf_upcoming_anniversaries(30);

    $duenotes = [];
    foreach (sf_load_json(SF_NOTES) as $n) {
        if (!empty($n['done'])) continue;
        $rem = (string)($n['reminder'] ?? '');
        if ($rem !== '' && $rem <= $today) $duenotes[] = $n;
    }

    $newApps = [];
    foreach (sf_load_json(SF_APPLICATIONS) as $a) {
        if (in_array($a['status'] ?? '', ['eingegangen', 'offen'], true)) $newApps[] = $a;
    }

    $newContacts = [];
    foreach (sf_load_json(dirname(__DIR__) . '/data/contacts.json') as $c) {
        if (empty($c['read_at']) && empty($c['replied_at'])) $newContacts[] = $c;
    }

    return [
        'bdays_today' => $bdaysToday,
        'bdays_week'  => $bdaysSoon,
        'annivs'      => $annivs30,
        'due_notes'   => $duenotes,
        'new_apps'    => $newApps,
        'new_contacts'=> $newContacts,
    ];
}

function sf_daily_briefing_render_html(array $b): string {
    $h = '';
    $h .= "<p>Hallo Sandra 👋,<br>hier ist dein KGV-Briefing für <strong>" . htmlspecialchars(date('d.m.Y')) . "</strong>:</p>";

    if (!empty($b['bdays_today'])) {
        $h .= "<div style='background:#fce4ec;border-left:4px solid #ec407a;padding:14px;margin:14px 0;border-radius:6px'>";
        $h .= "<strong style='color:#ad1457'>🎂 Geburtstag heute</strong><ul style='margin:6px 0 0;padding-left:20px'>";
        foreach ($b['bdays_today'] as $bd) {
            $h .= "<li>" . htmlspecialchars($bd['name']) . ($bd['age'] !== null ? ' — wird ' . (int)$bd['age'] : '') . "</li>";
        }
        $h .= "</ul></div>";
    }
    if (!empty($b['bdays_week'])) {
        $h .= "<div style='background:#fff3e0;border-left:4px solid #f57c00;padding:14px;margin:14px 0;border-radius:6px'>";
        $h .= "<strong style='color:#e65100'>🎂 In den nächsten 7 Tagen</strong><ul style='margin:6px 0 0;padding-left:20px'>";
        foreach ($b['bdays_week'] as $bd) {
            $h .= "<li>" . htmlspecialchars($bd['name']) . " — am " . htmlspecialchars(date('d.m.', strtotime($bd['date']))) . " (" . (int)$bd['days_until'] . " Tg)</li>";
        }
        $h .= "</ul></div>";
    }
    if (!empty($b['annivs'])) {
        $h .= "<div style='background:#fff8e1;border-left:4px solid #f9a825;padding:14px;margin:14px 0;border-radius:6px'>";
        $h .= "<strong style='color:#f57f17'>🏅 Anstehende Jubiläen (30 Tg)</strong><ul style='margin:6px 0 0;padding-left:20px'>";
        foreach ($b['annivs'] as $a) {
            $h .= "<li>" . htmlspecialchars($a['name']) . " — " . (int)$a['years'] . " Jahre am " . htmlspecialchars(date('d.m.Y', strtotime($a['date']))) . "</li>";
        }
        $h .= "</ul></div>";
    }
    if (!empty($b['due_notes'])) {
        $h .= "<div style='background:#ffebee;border-left:4px solid #c62828;padding:14px;margin:14px 0;border-radius:6px'>";
        $h .= "<strong style='color:#b71c1c'>🔥 Wiedervorlagen heute / überfällig</strong><ul style='margin:6px 0 0;padding-left:20px'>";
        foreach ($b['due_notes'] as $n) $h .= "<li>" . htmlspecialchars($n['text'] ?? '') . " (" . htmlspecialchars($n['reminder'] ?? '') . ")</li>";
        $h .= "</ul></div>";
    }
    if (!empty($b['new_apps'])) {
        $h .= "<div style='background:#e3f2fd;border-left:4px solid #1565c0;padding:14px;margin:14px 0;border-radius:6px'>";
        $h .= "<strong style='color:#0d47a1'>📥 Neue Anträge (" . count($b['new_apps']) . ")</strong>";
        $h .= "<p style='margin:4px 0 0;font-size:0.88rem'><a href='" . site_url() . "/intern/?tab=schriftfuehrung&sub=applications'>→ Im Cockpit ansehen</a></p></div>";
    }
    if (!empty($b['new_contacts'])) {
        $h .= "<div style='background:#e8eaf6;border-left:4px solid #3f51b5;padding:14px;margin:14px 0;border-radius:6px'>";
        $h .= "<strong style='color:#283593'>📨 Unbearbeitete Kontaktanfragen (" . count($b['new_contacts']) . ")</strong>";
        $h .= "<p style='margin:4px 0 0;font-size:0.88rem'><a href='" . site_url() . "/intern/?tab=contacts'>→ Im Backoffice ansehen</a></p></div>";
    }

    if (empty($b['bdays_today']) && empty($b['bdays_week']) && empty($b['annivs'])
        && empty($b['due_notes']) && empty($b['new_apps']) && empty($b['new_contacts'])) {
        $h .= "<div style='background:#e8f5e9;border-left:4px solid #2e7d32;padding:18px;margin:14px 0;border-radius:6px;text-align:center'>";
        $h .= "<strong style='color:#1b5e20;font-size:1.05rem'>✨ Inbox Zero heute</strong>";
        $h .= "<p style='margin:6px 0 0;color:#5a6c5a;font-size:0.9rem'>Keine Geburtstage, Jubiläen, fälligen Notizen oder neuen Anfragen. Genieß den Tag! 🌻</p></div>";
    }

    $h .= "<p style='font-size:0.85rem;color:#8a9a8a;margin-top:18px'>Diese Mail kommt automatisch jeden Morgen aus dem Schriftführung-Cockpit. Konfigurieren / abschalten unter 'Schriftführung → Einstellungen'.</p>";
    return $h;
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Canva-Karten-Drucker                                                     */
/* ───────────────────────────────────────────────────────────────────────── */

/**
 * Generiert eine A5-Glückwunsch-Karte mit Canva-Bild als Hintergrund.
 * Akzeptiert: PNG/JPG aus der Canva-Bibliothek.
 *
 * @param string $imageFile  absoluter Dateipfad zum Hintergrundbild
 * @param string $name       Empfänger-Name
 * @param string $message    Glückwunschtext (mehrzeilig)
 * @param string $textPos    'bottom' | 'center' (Position des Text-Overlays)
 * @return string            PDF binary
 */
function sf_card_pdf(string $imageFile, string $name, string $message, string $textPos = 'bottom'): string {
    require_once __DIR__ . '/fpdf.php';
    $e = fn(string $s): string => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;

    // A5 Querformat (210×148)
    $pdf = new FPDF('L', 'mm', 'A5');
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    $W = 210; $H = 148;
    // Hintergrund-Bild voll-fläche
    if (file_exists($imageFile)) {
        $ext = strtolower(pathinfo($imageFile, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            try {
                $pdf->Image($imageFile, 0, 0, $W, $H);
            } catch (\Throwable $e2) {
                // fallback: light bg
                $pdf->SetFillColor(252, 247, 240);
                $pdf->Rect(0, 0, $W, $H, 'F');
            }
        }
    } else {
        $pdf->SetFillColor(252, 247, 240);
        $pdf->Rect(0, 0, $W, $H, 'F');
    }

    // Text-Bereich
    if ($textPos === 'center') {
        $blockY = 50; $blockH = 50;
    } else { // bottom
        $blockY = 95; $blockH = 50;
    }

    // Halbtransparenter weißer Block für Text (FPDF kann keine echte Transparenz,
    // aber wir machen einen leichten weißen Block mit Rahmen)
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect(15, $blockY, $W - 30, $blockH, 'F');
    $pdf->SetDrawColor(184, 134, 11);
    $pdf->SetLineWidth(0.4);
    $pdf->Rect(15, $blockY, $W - 30, $blockH);

    // Name (groß)
    $pdf->SetXY(15, $blockY + 6);
    $pdf->SetFont('Helvetica', 'B', 22);
    $pdf->SetTextColor(184, 134, 11);
    $pdf->Cell($W - 30, 9, $e($name), 0, 1, 'C');

    // Trennlinie
    $pdf->SetDrawColor(184, 134, 11);
    $pdf->Line($W/2 - 20, $blockY + 18, $W/2 + 20, $blockY + 18);

    // Message
    $pdf->SetXY(15, $blockY + 22);
    $pdf->SetFont('Helvetica', '', 11);
    $pdf->SetTextColor(60, 60, 60);
    foreach (explode("\n", $message) as $line) {
        if (trim($line) === '') { $pdf->Ln(2); continue; }
        $pdf->SetX(15);
        $pdf->MultiCell($W - 30, 5.5, $e($line), 0, 'C');
    }

    // Footer: KGV Signatur
    $pdf->SetY($blockY + $blockH - 8);
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->SetTextColor(140, 140, 140);
    $pdf->Cell($W, 4, $e('unser Verein'), 0, 1, 'C');

    return $pdf->Output('S');
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Welle 5: Protokoll Power-Up                                              */
/* ───────────────────────────────────────────────────────────────────────── */

define('SF_UPLOAD_PROTOCOLS', SF_DIR . '/uploads/protocols');
define('SF_SCHAEDEN',         SF_DIR . '/schaeden.json');
define('SF_UPLOAD_SCHAEDEN',  SF_DIR . '/uploads/schaeden');

// 5.5 — Sitzungstyp-spezifische TOP-Vorlagen
function sf_default_tops_by_type(string $type): array {
    $byType = [
        'Vorstandssitzung' => [
            ['top'=>'TOP 1','title'=>'Begrüßung und Feststellung der Beschlussfähigkeit'],
            ['top'=>'TOP 2','title'=>'Genehmigung des Protokolls der letzten Sitzung'],
            ['top'=>'TOP 3','title'=>'Berichte (Vorsitz, Kassier, Vermietung, …)'],
            ['top'=>'TOP 4','title'=>'Aktuelle Themen und Beschlüsse'],
            ['top'=>'TOP 5','title'=>'Verschiedenes'],
        ],
        'Mitgliederversammlung' => [
            ['top'=>'TOP 1','title'=>'Begrüßung und Feststellung der Beschlussfähigkeit'],
            ['top'=>'TOP 2','title'=>'Genehmigung des Protokolls der letzten Mitgliederversammlung'],
            ['top'=>'TOP 3','title'=>'Bericht des Vorstands'],
            ['top'=>'TOP 4','title'=>'Kassenbericht und Bericht der Kassenprüfer'],
            ['top'=>'TOP 5','title'=>'Entlastung des Vorstands'],
            ['top'=>'TOP 6','title'=>'Wahlen (falls anstehend)'],
            ['top'=>'TOP 7','title'=>'Anträge'],
            ['top'=>'TOP 8','title'=>'Verschiedenes'],
        ],
        'Außerordentliche Sitzung' => [
            ['top'=>'TOP 1','title'=>'Begrüßung und Feststellung der Beschlussfähigkeit'],
            ['top'=>'TOP 2','title'=>'Anlass und Hintergrund'],
            ['top'=>'TOP 3','title'=>'Diskussion'],
            ['top'=>'TOP 4','title'=>'Beschluss'],
            ['top'=>'TOP 5','title'=>'Sonstiges'],
        ],
        'Ausschuss-Sitzung' => [
            ['top'=>'TOP 1','title'=>'Begrüßung'],
            ['top'=>'TOP 2','title'=>'Aktuelle Themen des Ausschusses'],
            ['top'=>'TOP 3','title'=>'Ergebnisse / Beschlüsse'],
            ['top'=>'TOP 4','title'=>'Nächste Schritte und Termine'],
        ],
    ];
    return $byType[$type] ?? sf_default_protocol_tops();
}

// 5.4 — Beschluss-Nummerierung (BSL-YYYY-MM-NN)
function sf_next_beschluss_nr(string $date): string {
    [$y,$m] = array_pad(explode('-', $date . '-?-?'), 3, '?');
    $y = preg_match('/^\d{4}$/', $y) ? $y : date('Y');
    $m = preg_match('/^\d{2}$/', $m) ? $m : date('m');

    $max = 0;
    foreach (sf_load_json(SF_PROTOCOLS) as $p) {
        foreach (($p['tops'] ?? []) as $t) {
            $nr = (string)($t['beschluss_nr'] ?? '');
            if (preg_match('/^BSL-' . $y . '-' . $m . '-(\d+)$/', $nr, $mm)) {
                $max = max($max, (int)$mm[1]);
            }
        }
    }
    return sprintf('BSL-%s-%s-%02d', $y, $m, $max + 1);
}

// 5.8 — Protokoll-Status
function sf_protocol_statuses(): array {
    return [
        'draft'    => ['#90a4ae', 'Entwurf'],
        'review'   => ['#f57c00', 'In Prüfung'],
        'approved' => ['#2e7d32', 'Freigegeben'],
    ];
}

// 5.3 — Einladungs-PDF (Template-Stil)
function sf_protocol_invitation_pdf(array $p, array $settings = [], string $time = '', string $location = '', string $extraNote = ''): string {
    require_once __DIR__ . '/fpdf.php';
    $e = fn(string $s): string => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(20, 12, 20);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();

    // ── Briefkopf ──────────────────────────────────────────────────────
    sf_briefkopf_top($pdf, $settings, 12);

    // ── Titel: "Einladung" + Untertitel "[Type] vom [Date]" ─────────────
    try {
        $dDate = (new DateTime($p['date'] ?? 'today'))->format('d. F Y');
        $dDateDe = strtr($dDate, [
            'January'=>'Januar','February'=>'Februar','March'=>'März','April'=>'April',
            'May'=>'Mai','June'=>'Juni','July'=>'Juli','August'=>'August',
            'September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Dezember',
        ]);
    } catch (\Throwable $err) { $dDateDe = $p['date'] ?? ''; }

    sf_doc_title($pdf, 'Einladung', ($p['type'] ?? 'Sitzung') . ' vom ' . $dDateDe);

    // ── Meta-Block (Ort / Zeit / Tagesordnung) ─────────────────────────
    $pdf->Ln(4);
    sf_meta_row($pdf, 'Datum',   $dDateDe);
    if ($time !== '')     sf_meta_row($pdf, 'Uhrzeit', $time);
    if ($location !== '') sf_meta_row($pdf, 'Ort',     $location);

    // ── Tagesordnung ───────────────────────────────────────────────────
    sf_section_underline($pdf, 'Geplante Tagesordnung');
    $pdf->SetFont('Helvetica', '', 10.5);
    $pdf->SetTextColor(0, 0, 0);
    foreach (($p['tops'] ?? []) as $top) {
        $line = ($top['top'] ?? '') . ': ' . ($top['title'] ?? '');
        $pdf->SetX(24);
        $pdf->MultiCell(166, 5.5, $e($line), 0, 'L');
        $pdf->Ln(0.5);
    }

    // ── Optionaler Hinweis ─────────────────────────────────────────────
    if ($extraNote !== '') {
        sf_section_underline($pdf, 'Hinweis');
        sf_body_paragraph($pdf, $extraNote);
    }

    $pdf->Ln(4);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->MultiCell(170, 5.5, $e('Bei Verhinderung bitte rechtzeitig Bescheid geben. Anträge zur Tagesordnung können bis spätestens 7 Tage vor der Sitzung beim Vorstand schriftlich eingereicht werden.'), 0, 'L');
    $pdf->Ln(6);

    // ── Signatur ───────────────────────────────────────────────────────
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(170, 5, $e('Mit freundlichen Grüßen'), 0, 1);
    $pdf->Ln(8);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(170, 5, $e((string)($settings['kontakt_name'] ?? 'Max Mustermann')), 0, 1);
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell(170, 4, $e((string)($settings['kontakt_rolle'] ?? '1. Vorsitzender') . ' · unser Verein'), 0, 1);

    // ── Footer ─────────────────────────────────────────────────────────
    $pdf->SetY(-14);
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetTextColor(140, 140, 140);
    $pdf->Cell(170, 4, $e('Erstellt am ' . date('d.m.Y H:i') . ' · Muster-Kleingartenverein e.V.'), 0, 1, 'C');

    return $pdf->Output('S');
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Schadenmeldungen                                                          */
/* ───────────────────────────────────────────────────────────────────────── */

function sf_schaden_statuses(): array {
    return [
        'gemeldet'       => ['#1565c0', 'Gemeldet'],
        'in_bearbeitung' => ['#f57c00', 'In Bearbeitung'],
        'beauftragt'     => ['#7b1fa2', 'Reparatur beauftragt'],
        'erledigt'       => ['#2e7d32', 'Erledigt'],
        'abgelehnt'      => ['#c62828', 'Abgelehnt'],
    ];
}

function sf_schaden_categories(): array {
    return [
        'vereinshaus' => '🏠 Vereinshaus',
        'sanitaer'    => '🚿 Sanitär / Wasser',
        'elektrik'    => '⚡ Elektrik',
        'wege'        => '🛤 Wege / Außenanlagen',
        'zaun'        => '🚧 Zaun / Tore',
        'spielplatz'  => '🎠 Spielplatz',
        'sonstige'    => '🔧 Sonstige',
    ];
}

/* ───────────────────────────────────────────────────────────────────────── */
/*  Briefkopf-Template (für Protokolle, Briefe, Einladungen)                  */
/*  Layout angelehnt an „DIGITALE PROTOKOLLFÜHRUNG"-Vorlage                  */
/* ───────────────────────────────────────────────────────────────────────── */

/**
 * Zeichnet den Standard-Briefkopf: italic Adresse + Vorsitzender oben links,
 * Logo oben rechts. Startet bei $startY mm.
 */
function sf_briefkopf_top(\FPDF $pdf, array $settings = [], float $startY = 12): void {
    $e = fn(string $s): string => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;

    // Logo oben rechts (möglichst groß für sichtbaren Effekt)
    $logoPath = dirname(__DIR__) . '/logo.png';
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 158, $startY, 32);
    }

    // Italic Adresse + Vorsitzender oben links, KGV-Grün
    $pdf->SetFont('Helvetica', 'I', 10);
    $pdf->SetTextColor(61, 107, 65);

    $addr   = trim((string)($settings['briefkopf_adresse'] ?? 'Vereinsstraße 1, PLZ Ort'));
    // Briefkopf zeigt IMMER den 1. Vorsitzenden (aus Vorstand-Liste, nicht
    // settings.kontakt_name — sonst überschreibt Schriftführer-Eintrag das
    // offizielle Dokument)
    $_vorsitz = kgv_get_vorsitz();
    $vorsitz  = $_vorsitz['name'];
    $rolle    = $_vorsitz['rolle'];

    $pdf->SetXY(20, $startY + 3);
    $pdf->Cell(130, 5, $e($addr), 0, 1);

    $pdf->SetX(20);
    $pdf->Cell(130, 5, $e($rolle . ': ' . $vorsitz), 0, 1);
}

/**
 * Großer unterstrichener Dokumenttitel + optionaler Untertitel.
 * Beispiel:  "Protokoll"  /  "Mitgliederversammlung vom 29. März 2026"
 */
function sf_doc_title(\FPDF $pdf, string $title, string $subtitle = ''): void {
    $e = fn(string $s): string => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;

    $pdf->Ln(15);
    $pdf->SetFont('Helvetica', 'BU', 18);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 8, $e($title), 0, 1, 'L');

    if ($subtitle !== '') {
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(0, 6, $e($subtitle), 0, 1, 'L');
    }
}

/**
 * Unterstrichene Sektions-Überschrift im Body.
 * Beispiel:  "Eröffnung der Versammlung"  /  "Zu TOP 1) Genehmigung der Tagesordnung § 6 Abs. 2"
 */
function sf_section_underline(\FPDF $pdf, string $title): void {
    $e = fn(string $s): string => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;
    $pdf->Ln(5);
    $pdf->SetFont('Helvetica', 'BU', 11);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 6, $e($title), 0, 1, 'L');
    $pdf->Ln(1);
}

/**
 * Meta-Zeile: fett-Label links, Wert rechts.
 * $value darf Zeilenumbrüche enthalten — wird via MultiCell formatiert.
 */
function sf_meta_row(\FPDF $pdf, string $label, string $value, float $labelW = 30): void {
    $e = fn(string $s): string => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;
    $startY = $pdf->GetY();

    $pdf->SetFont('Helvetica', 'B', 10.5);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($labelW, 5.5, $e($label . ':'), 0, 0);

    $pdf->SetFont('Helvetica', '', 10.5);
    $pdf->MultiCell(170 - $labelW, 5.5, $e($value), 0, 'L');
    $pdf->Ln(1);
}

/**
 * Fließtext-Absatz mit Standard-Body-Font.
 * Akzeptiert "\n" für Absatz-Trenner (Leerzeile).
 */
function sf_body_paragraph(\FPDF $pdf, string $text): void {
    $e = fn(string $s): string => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;
    $pdf->SetFont('Helvetica', '', 10.5);
    $pdf->SetTextColor(0, 0, 0);
    foreach (explode("\n", $text) as $line) {
        if (trim($line) === '') { $pdf->Ln(2.5); continue; }
        $pdf->MultiCell(170, 5.5, $e($line), 0, 'J');
        $pdf->Ln(0.5);
    }
}

/**
 * Abstimmungs-Box: drei Zeilen Ja/Nein/Enthaltungen, fett-Zahl rechts.
 */
function sf_abstimmung_box(\FPDF $pdf, ?int $ja, ?int $nein, ?int $enth, string $caption = 'Abstimmung'): void {
    $e = fn(string $s): string => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;
    if ($ja === null && $nein === null && $enth === null) return;
    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', 'B', 10.5);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 5.5, $e($caption . ':'), 0, 1);

    foreach ([['Ja-Stimmen', $ja], ['Nein-Stimmen', $nein], ['Enthaltungen', $enth]] as [$lbl, $val]) {
        $pdf->SetFont('Helvetica', '', 10.5);
        $pdf->Cell(45, 5.5, $e($lbl . ':'), 0, 0);
        $pdf->SetFont('Helvetica', 'B', 10.5);
        $pdf->Cell(20, 5.5, $e((string)((int)$val)), 0, 1);
    }
    $pdf->Ln(2);
}
