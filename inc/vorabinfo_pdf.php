<?php
declare(strict_types=1);

/**
 * Generates the "Vorabinfo zur Vereinshausmietung" as PDF (FPDF).
 * Returns the PDF as binary string (ready for email attachment).
 */
function generate_vorabinfo_pdf(array $b, array $prices, array $settings): string
{
    require_once __DIR__ . '/fpdf.php';
    require_once __DIR__ . '/email_template.php';

    // FPDF uses ISO-8859-1 — helper to convert from UTF-8
    $e = fn(string $s): string => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;

    // ── Data ─────────────────────────────────────────────────────────────────
    $name    = $b['name']    ?? '';
    $phone   = $b['phone']   ?? '';
    $purpose = $b['purpose'] ?? '';

    $dates = $b['dates'] ?? [];
    if (is_array($dates) && count($dates) > 0) {
        $fmt = [];
        foreach ($dates as $d) {
            $ts = strtotime((string)$d);
            if ($ts) $fmt[] = date('d.m.Y', $ts);
        }
        $dateStr = implode(' – ', $fmt);
    } else {
        $dateStr = is_string($dates) ? $dates : '';
    }

    $musikboxGebucht = false;
    foreach (($b['extras'] ?? []) as $ex) {
        $exName = is_array($ex) ? ($ex['label'] ?? $ex['name'] ?? '') : (string)$ex;
        if (stripos($exName, 'musikbox') !== false) { $musikboxGebucht = true; break; }
    }

    $zahlungsziel = (int)($settings['zahlungsziel_wochen'] ?? 4);
    $stromKwh     = number_format((float)($prices['strom_kwh'] ?? 0.50), 2, ',', '.');
    // Mietpreis bevorzugt aus der Buchung (berücksichtigt Mitgliedstarif), Fallback auf Standardpreis
    $isMember     = !empty($b['is_member_tarif']);
    $mieteRaw     = (float)($b['raummiete'] ?? ($isMember ? (float)($prices['miete'] ?? 300) / 2 : (float)($prices['miete'] ?? 300)));
    $kautionRaw   = (float)($prices['kaution'] ?? 200);
    // Endreinigung tarif-abhängig: Mitglieder bekommen den Mitglieder-Wert (Default 0)
    $endrnRaw     = $isMember
        ? (float)($prices['endreinigung_mitglied'] ?? 0)
        : (float)($prices['endreinigung'] ?? 50);
    $miete        = number_format($mieteRaw,                                 2, ',', '.');
    $kaution      = number_format($kautionRaw,                               2, ',', '.');
    $endreinigung = number_format($endrnRaw,                                 2, ',', '.');
    $gesamt       = number_format($mieteRaw + $endrnRaw + $kautionRaw,       2, ',', '.');
    // 1. Vorsitzenden aus Vorstand-Liste ziehen (NICHT settings.kontakt_name,
    // denn dort kann die Schriftführerin sich eintragen — offizielle Briefe
    // müssen immer den Vorsitzenden zeigen)
    $_vorsitz     = kgv_get_vorsitz();
    $vorstand     = $_vorsitz['name'];
    $iban         = $settings['iban']         ?? 'DE87 2135 2240 0179 2191 83';
    $bank         = $settings['bank']         ?? 'Sparkasse Holstein';

    // ── PDF setup ────────────────────────────────────────────────────────────
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(20, 10, 20);
    $pdf->SetAutoPageBreak(false, 15); // no auto-break — we fit on one page
    $pdf->AddPage();

    $W = 170; // usable width

    // ── LOGO ─────────────────────────────────────────────────────────────────
    $logoPath = dirname(__DIR__) . '/logo.png';
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 165, 5, 21);
    }

    // ── HEADER ───────────────────────────────────────────────────────────────
    $pdf->SetFont('Helvetica', 'BI', 8);
    $pdf->SetTextColor(61, 107, 65); // KGV green
    $pdf->Cell(140, 4, $e('Vereinsstraße 1, PLZ Ort'), 0, 1);
    $pdf->Cell(140, 4, $e('Vorsitzender: ' . $vorstand), 0, 1);

    $pdf->SetFont('Helvetica', 'B', 10.5);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(140, 5.5, $e('Muster-Kleingartenverein e.V.'), 0, 1);

    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell(140, 4, $e('Bankverbindung: ' . $bank . '    IBAN: ' . $iban), 0, 1);
    $pdf->Ln(2);

    $pdf->SetDrawColor(160, 160, 160);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    $pdf->Ln(4);

    // ── TITLE ────────────────────────────────────────────────────────────────
    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($W, 8, $e('Vorabinfo zur Vereinshausmietung'), 0, 1);
    $pdf->Ln(4);

    // ── FORM FIELDS ──────────────────────────────────────────────────────────
    $lh = 6;   // line height for fields
    $sl = 3;   // small label height

    $pdf->SetDrawColor(80, 80, 80);

    // Row 1: Name | Anschrift
    $pdf->SetFont('Helvetica', '', 9.5);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(95, $lh, $e($name), 'B', 0);
    $pdf->Cell(5,  $lh, '', 0, 0);
    $pdf->Cell(70, $lh, '', 'B', 1);
    $pdf->SetFont('Helvetica', '', 7); $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell(95, $sl, $e('Vorname  Nachname'), 0, 0);
    $pdf->Cell(5,  $sl, '', 0, 0);
    $pdf->Cell(70, $sl, $e('Anschrift'), 0, 1);
    $pdf->Ln(3);

    // Row 2: Telefon | PLZ/Ort — right column aligned with row 1 (start at x=120)
    $pdf->SetFont('Helvetica', '', 9.5); $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(95, $lh, $e($phone), 'B', 0);
    $pdf->Cell(5,  $lh, '', 0, 0);
    $pdf->Cell(70, $lh, '', 'B', 1);
    $pdf->SetFont('Helvetica', '', 7); $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell(95, $sl, $e('Telefonnummer'), 0, 0);
    $pdf->Cell(5,  $sl, '', 0, 0);
    $pdf->Cell(70, $sl, $e('PLZ / Ort'), 0, 1);
    $pdf->Ln(3);

    // Row 3: Veranstaltung | Datum
    $pdf->SetFont('Helvetica', '', 9.5); $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(95, $lh, $e($purpose), 'B', 0);
    $pdf->Cell(5,  $lh, '', 0, 0);
    $pdf->Cell(70, $lh, $e($dateStr), 'B', 1);
    $pdf->SetFont('Helvetica', '', 7); $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell(95, $sl, $e('Für die Veranstaltung (Anlass)'), 0, 0);
    $pdf->Cell(5,  $sl, '', 0, 0);
    $pdf->Cell(70, $sl, $e('Datum'), 0, 1);
    $pdf->Ln(6);

    // ── BODY TEXT ────────────────────────────────────────────────────────────
    $lhT = 5; // text line height
    $pdf->SetFont('Helvetica', '', 9.5);
    $pdf->SetTextColor(0, 0, 0);

    $pdf->MultiCell($W, $lhT,
        $e('Ich/wir beabsichtigen das Vereinshaus der Muster-Kleingartenverein e.V. zu mieten.'), 0, 'J');
    $pdf->Ln(2);

    $pdf->MultiCell($W, $lhT,
        $e("Mir/uns ist bewusst, dass der Mietpreis und die Kaution in voller Höhe bis spätestens {$zahlungsziel} Wochen vor Mietung auf das unten angegebene Konto eingegangen sein muss. ACHTUNG, Barzahlungen nehmen wir grundsätzlich NICHT entgegen."), 0, 'J');
    $pdf->Ln(3);

    // ── BULLETS ──────────────────────────────────────────────────────────────
    $reinigungBullet = $endrnRaw > 0
        ? "Das Vereinshaus ist nach der Veranstaltung besenrein zu übergeben (Mülleimer geleert, Boden gefegt, Tische sauber gewischt). Die verpflichtende Endreinigung in Höhe von {$endreinigung} € ist im Gesamtbetrag bereits enthalten."
        : "Das Vereinshaus ist nach der Veranstaltung besenrein zu übergeben (Mülleimer geleert, Boden gefegt, Tische sauber gewischt). Für Mitglieder ist die Endreinigung in der Vereinsgemeinschaft enthalten — es fallen keine zusätzlichen Kosten an.";
    $bullets = [
        "Ist das Geld nicht rechtzeitig auf dem Konto zu verzeichnen, kommt kein Vertrag zu Stande und wir behalten uns vor, das Vereinshaus anderweitig zu vermieten.",
        "Strom in Höhe von {$stromKwh} €/kWh wird nach der Vermietung von der Kaution abgezogen und der Differenzbetrag zurück überwiesen.",
        $reinigungBullet,
        "Die Vorabinfo muss im Vorfeld gelesen und unterzeichnet an uns zurückgeschickt werden, dieses geht auch per E-Mail.",
    ];
    foreach ($bullets as $bullet) {
        $pdf->SetX(24);
        $pdf->Cell(5, $lhT, '-', 0, 0);
        $pdf->MultiCell(161, $lhT, $e($bullet), 0, 'J');
        $pdf->Ln(0.5);
    }

    // Musikbox bullet
    $pdf->SetX(24);
    $pdf->Cell(5, $lhT, '-', 0, 0);
    $pdf->MultiCell(161, $lhT,
        $e('Ist die zusätzliche Mietung unserer Musikbox in Höhe von 10,00 € gewünscht?'), 0, 'J');

    // Checkboxes — always empty, tenant fills in manually
    $cbY = $pdf->GetY();
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->Rect(29, $cbY + 0.5, 3.5, 3.5);
    $pdf->SetXY(33.5, $cbY); $pdf->Cell(8, 4.5, $e('Ja'), 0, 0);
    $pdf->Rect(43, $cbY + 0.5, 3.5, 3.5);
    $pdf->SetXY(47.5, $cbY); $pdf->Cell(20, 4.5, $e('Nein'), 0, 1);
    $pdf->Ln(0.5);

    $pdf->SetX(29);
    $pdf->SetFont('Helvetica', '', 9.5);
    $pdf->MultiCell(161, $lhT,
        $e('(bitte angeben) Wenn „Ja" so wird dieser Betrag ebenfalls mit der Kaution verrechnet.'), 0, 'J');
    $pdf->Ln(4);

    // ── CONFIRMATION ─────────────────────────────────────────────────────────
    $pdf->SetFont('Helvetica', '', 9.5);
    $pdf->MultiCell($W, $lhT,
        $e($endrnRaw > 0
            ? "Ich bestätige, dass ich die oben genannten Punkte zur Kenntnis genommen habe und akzeptiere. Mir ist bewusst, dass ein Vertrag zur Vermietung nur zu Stande kommt, wenn der Geldeingang in Höhe von {$gesamt} € ({$miete} € Miete + {$endreinigung} € Endreinigung + {$kaution} € Kaution) rechtzeitig zu verzeichnen ist."
            : "Ich bestätige, dass ich die oben genannten Punkte zur Kenntnis genommen habe und akzeptiere. Mir ist bewusst, dass ein Vertrag zur Vermietung nur zu Stande kommt, wenn der Geldeingang in Höhe von {$gesamt} € ({$miete} € Miete + {$kaution} € Kaution) rechtzeitig zu verzeichnen ist."
        ), 0, 'J');

    $pdf->Ln(10);

    // ── SIGNATURE ────────────────────────────────────────────────────────────
    $sigY = $pdf->GetY();
    $pdf->SetDrawColor(80, 80, 80);
    $pdf->Line(20,  $sigY, 95,  $sigY);
    $pdf->Line(110, $sigY, 190, $sigY);
    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', '', 7.5); $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell(75, 4, $e('Ort, Datum'), 0, 0);
    $pdf->Cell(35, 4, '', 0, 0);
    $pdf->Cell(80, 4, $e('Unterschrift'), 0, 1);

    // ── FOOTER ───────────────────────────────────────────────────────────────
    $pdf->SetY(-14);
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', '', 7); $pdf->SetTextColor(140, 140, 140);
    $pdf->Cell($W, 4,
        $e('Muster-Kleingartenverein e.V.    Bankverbindung: ' . $bank . '    IBAN: ' . $iban),
        0, 1, 'C');

    return $pdf->Output('S');
}
