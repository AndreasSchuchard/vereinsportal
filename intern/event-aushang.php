<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/settings_loader.php';

ini_set('display_errors', '0');
ini_set('log_errors',     '1');
session_start();

// Auth: SuperAdmin + vorstand + web + schriftfuehrer
$_isSuperAdmin = !empty($_SESSION['kgv_admin']);
$_roles = $_SESSION['kgv_member']['roles'] ?? [];
if (!($_isSuperAdmin || !empty(array_intersect($_roles, ['vorstand','web','schriftfuehrer'])))) {
    header('Location: /intern/'); exit;
}

require_once dirname(__DIR__) . '/inc/events.php';
require_once dirname(__DIR__) . '/inc/fpdf.php';
require_once dirname(__DIR__) . '/inc/local_qr.php';

$evId = trim((string)($_GET['id'] ?? ''));
$event = null;
foreach (kgv_events_all() as $e) {
    if (($e['id'] ?? '') === $evId) { $event = $e; break; }
}
if (!$event) { http_response_code(404); exit('Veranstaltung nicht gefunden'); }

// Wenn Sandra einen eigenen Flyer hochgeladen hat (z.B. aus Canva) — den ausliefern statt PDF-Generator
$forceStandard = isset($_GET['standard']); // ?standard=1 → immer Standard-Vorlage
if (!$forceStandard && !empty($event['use_custom_flyer']) && !empty($event['custom_flyer_file'])) {
    $flyerPath = dirname(__DIR__) . '/images/event_flyers/' . basename((string)$event['custom_flyer_file']);
    if (is_file($flyerPath)) {
        $ext  = strtolower(pathinfo($flyerPath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="flyer_' . ($event['slug'] ?? 'event') . '.' . $ext . '"');
        header('Content-Length: ' . filesize($flyerPath));
        header('X-Content-Type-Options: nosniff');
        readfile($flyerPath);
        exit;
    }
}

$url = site_url() . '/event/' . rawurlencode($event['slug'] ?? '');
$qrPng = kgv_qr_png($url, 900);
$qrTmp = tempnam(sys_get_temp_dir(), 'kgv_qr_') . '.png';
file_put_contents($qrTmp, $qrPng);

// FPDF arbeitet mit ISO-8859-1 — wir konvertieren UTF-8 Text
$e = fn(string $s): string => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s;

// Sandras anpassbare PDF-Felder (mit Defaults)
$pdfEyebrow    = trim((string)($event['pdf_eyebrow']    ?? '')) ?: '🎪  EINLADUNG ZUR VERANSTALTUNG';
$pdfCtaMain    = trim((string)($event['pdf_cta_main']    ?? '')) ?: '📱  Jetzt mit dem Smartphone scannen';
$pdfCtaSub     = trim((string)($event['pdf_cta_sub']     ?? '')) ?: '… oder direkt im Browser besuchen:';
$pdfIntroText  = trim((string)($event['pdf_intro_text']  ?? ''));
$pdfFooterNote = trim((string)($event['pdf_footer_note'] ?? ''));
$pdfShowDesc   = !empty($event['pdf_show_description']);

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(20, 18, 20);
$pdf->SetAutoPageBreak(false, 18);
$pdf->AddPage();

// ── Logo (rechts oben)
$logoPath = dirname(__DIR__) . '/logo.png';
if (file_exists($logoPath)) {
    $pdf->Image($logoPath, 165, 14, 25);
}

// ── Header (KGV-Identität)
$pdf->SetFont('Helvetica', 'BI', 9);
$pdf->SetTextColor(61, 107, 65);
$pdf->Cell(140, 5, $e('Muster-Kleingartenverein e.V.'), 0, 1);
$pdf->SetFont('Helvetica', '', 8);
$pdf->SetTextColor(120, 120, 120);
$pdf->Cell(140, 4, $e('Vereinsstraße 1 · PLZ Ort'), 0, 1);
$pdf->Ln(2);
$pdf->SetDrawColor(220, 220, 220);
$pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
$pdf->Ln(14);

// ── Eyebrow (anpassbar)
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->SetTextColor(90, 140, 94);
$pdf->MultiCell(0, 5, $e($pdfEyebrow), 0, 'C');
$pdf->Ln(4);

// ── Titel (groß)
$pdf->SetFont('Helvetica', 'B', 26);
$pdf->SetTextColor(45, 62, 45);
$pdf->MultiCell(0, 12, $e((string)($event['title'] ?? '')), 0, 'C');
$pdf->Ln(1);

// ── Untertitel
if (!empty($event['subtitle'])) {
    $pdf->SetFont('Helvetica', '', 13);
    $pdf->SetTextColor(90, 108, 90);
    $pdf->MultiCell(0, 7, $e((string)$event['subtitle']), 0, 'C');
}

// ── Frist-Hinweis
if (!empty($event['deadline'])) {
    $dl = DateTimeImmutable::createFromFormat('Y-m-d', $event['deadline']);
    if ($dl) {
        $pdf->Ln(1);
        $pdf->SetFont('Helvetica', 'I', 10);
        $pdf->SetTextColor(229, 81, 0);
        $pdf->Cell(0, 5, $e('⏰  Anmeldeschluss: ' . $dl->format('d.m.Y')), 0, 1, 'C');
    }
}

// ── Veranstaltungs-Beschreibung (optional)
if ($pdfShowDesc && !empty($event['description'])) {
    $pdf->Ln(4);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(80, 90, 80);
    $pdf->MultiCell(0, 5, $e((string)$event['description']), 0, 'L');
}

// ── Zusätzlicher Hinweis-Text (Sandras Freitext)
if ($pdfIntroText !== '') {
    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', 'I', 10.5);
    $pdf->SetTextColor(80, 90, 80);
    $pdf->MultiCell(0, 5.5, $e($pdfIntroText), 0, 'C');
}

$pdf->Ln(6);

// ── QR-Code mittig
$qrW = 90;
$qrX = (210 - $qrW) / 2;
// Bei vielen Text-Blöcken oben: Wenn QR-Y > 200, dann QR etwas kleiner
$curY = $pdf->GetY();
if ($curY > 165) { $qrW = 70; $qrX = (210 - $qrW) / 2; }
$pdf->Image($qrTmp, $qrX, $curY, $qrW, $qrW, 'PNG');
$pdf->SetY($curY + $qrW + 4);

// ── Call-to-Action (anpassbar)
$pdf->SetFont('Helvetica', 'B', 12.5);
$pdf->SetTextColor(61, 107, 65);
$pdf->MultiCell(0, 6, $e($pdfCtaMain), 0, 'C');
$pdf->Ln(1);
$pdf->SetFont('Helvetica', '', 9.5);
$pdf->SetTextColor(120, 120, 120);
$pdf->MultiCell(0, 5, $e($pdfCtaSub), 0, 'C');
$pdf->SetFont('Helvetica', 'B', 10.5);
$pdf->SetTextColor(61, 107, 65);
$pdf->MultiCell(0, 5, $e($url), 0, 'C');

// ── Sandras Fußzeilen-Hinweis (optional, oberhalb des Standard-Footers)
if ($pdfFooterNote !== '') {
    $pdf->SetY(-30);
    $pdf->SetFont('Helvetica', 'I', 9);
    $pdf->SetTextColor(90, 108, 90);
    $pdf->MultiCell(0, 4.5, $e($pdfFooterNote), 0, 'C');
}

// ── Standard-Footer (am unteren Seitenrand)
$pdf->SetY(-18);
$pdf->SetDrawColor(220, 220, 220);
$pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
$pdf->Ln(2);
$pdf->SetFont('Helvetica', 'I', 7.5);
$pdf->SetTextColor(150, 150, 150);
$pdf->Cell(0, 4, $e('unser Verein · www.' . site_url() . ' · Druck-Aushang ' . date('d.m.Y')), 0, 1, 'C');

@unlink($qrTmp);

$filename = 'aushang_' . ($event['slug'] ?? 'event') . '_' . date('Y-m-d') . '.pdf';
$pdf->Output('I', $filename);
