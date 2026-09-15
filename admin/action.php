<?php
declare(strict_types=1);
session_start();

$_isSuperAdmin  = !empty($_SESSION['kgv_admin']);
$_memberRoles   = $_SESSION['kgv_member']['roles'] ?? [];
$_bookingRoles  = ['vorstand', 'buchung', 'schriftfuehrer', 'web'];
$_isMemberAdmin = !$_isSuperAdmin && !empty(array_intersect($_memberRoles, $_bookingRoles));
$_canDeleteBooking = $_isSuperAdmin || !empty(array_intersect($_memberRoles, ['vorstand', 'buchung']));

if (!$_isSuperAdmin && !$_isMemberAdmin) {
    header('Location: /intern/');
    exit;
}

// CSRF-Check
$csrf = $_SESSION['csrf'] ?? '';
if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    die('Ungültiger CSRF-Token.');
}

define('BOOKINGS_FILE', dirname(__DIR__) . '/data/bookings.json');
require_once dirname(__DIR__) . '/inc/email_template.php';
require_once dirname(__DIR__) . '/inc/vorabinfo_pdf.php';
define('CONTENT_FILE',  dirname(__DIR__) . '/data/content.json');
define('LOG_FILE',      dirname(__DIR__) . '/data/admin.log');

function removeBookingBufferDay(array $bDates, string $bookingId = '', string $bookingName = ''): void {
    if (empty($bDates)) return;
    sort($bDates);
    $bufferBefore = (new DateTimeImmutable($bDates[0]))->modify('-1 day')->format('Y-m-d');
    $bufferAfter  = (new DateTimeImmutable(end($bDates)))->modify('+1 day')->format('Y-m-d');
    if (!file_exists(CONTENT_FILE)) return;
    $cData = json_decode((string)file_get_contents(CONTENT_FILE), true) ?: [];
    $before = count($cData['blocked_dates'] ?? []);
    $cData['blocked_dates'] = array_values(array_filter(
        $cData['blocked_dates'] ?? [],
        function($bd) use ($bufferBefore, $bufferAfter, $bookingId, $bookingName) {
            $date = $bd['date'] ?? '';
            if (!in_array($date, [$bufferBefore, $bufferAfter], true)) return true;
            $reason = $bd['reason'] ?? '';
            if (!str_starts_with($reason, 'Vorbereitung/Übergabe:')
                && !str_starts_with($reason, 'Aufräumen/Übergabe:')) return true;
            if ($bookingId !== '' && str_contains($reason, '[' . $bookingId . ']')) return false;
            if ($bookingName !== '' && str_contains($reason, ': ' . $bookingName . ' (')) return false;
            return true;
        }
    ));
    if (count($cData['blocked_dates']) !== $before) {
        file_put_contents(CONTENT_FILE, json_encode($cData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

$fromEmail = 'kontakt@example.org';
$fromName  = 'KGV Musterstadt e.V.';

// Einstellungen und E-Mail-Vorlagen aus content.json laden
$_contentData  = file_exists(CONTENT_FILE) ? (json_decode((string)file_get_contents(CONTENT_FILE), true) ?: []) : [];
$_cfg          = $_contentData['settings'] ?? [];
$cfgIban       = $_cfg['iban']         ?? '';
$cfgKontoInhaber = $_cfg['kontoinhaber'] ?? 'KGV Musterstadt e.V.';
$cfgBank       = $_cfg['bank']         ?? '';
// Buchungs-Kontakt: Auto-Lookup im Vorstand (Kassier/Vermietung), Fallback auf settings.kontakt_*
require_once dirname(__DIR__) . '/inc/email_template.php';
$_cfgBookingContact = kgv_get_booking_contact($_contentData);
$cfgKontaktName  = $_cfgBookingContact['name'];
$cfgKontaktRolle = $_cfgBookingContact['rolle'];
$cfgTelefon      = $_cfgBookingContact['phone'];
$cfgEmail        = $_cfgBookingContact['email'];
$cfgZahlungsziel = (int)($_cfg['zahlungsziel_wochen'] ?? 4);
$_etDefault = [
    'confirm_subject' => 'Buchungsbestätigung – KGV Musterstadt Vereinshaus am {datum}',
    'confirm_body'    => "Liebe/r {name},\n\nwir freuen uns, Ihre Buchungsanfrage hiermit verbindlich zu bestätigen!\n\nZeitraum: {datum}\n\nGesamtbetrag: {betrag}\nKaution: 200,00 EUR\nZu überweisen: {gesamt}\n\nEmpfänger: {kontoinhaber}\nIBAN: {iban}\n\nKontakt: {kontakt_name} · {telefon} · {email_kontakt}\n\nMit freundlichen Grüßen\n{kontakt_name}\nKGV Musterstadt e.V.",
    'reject_subject'  => 'Zu Ihrer Anfrage – KGV Musterstadt Vereinshaus am {datum}',
    'reject_body'     => "Liebe/r {name},\n\nvielen Dank für Ihre Anfrage zur Nutzung unseres Vereinshauses am {datum}.\n\nLeider können wir Ihnen diesen Zeitraum nicht anbieten.\n\nFür alternative Terminanfragen:\n{kontakt_name} · {telefon} · {email_kontakt}\n\nMit freundlichen Grüßen\n{kontakt_name}\nKGV Musterstadt e.V.",
];
$cfgEmailTpl = array_merge($_etDefault, $_contentData['email_templates'] ?? []);
unset($_contentData, $_cfg, $_etDefault);

function send_mail_simple(string $to, string $subject, string $text, string $html,
                           string $fromName, string $fromEmail, string $replyTo = '',
                           array $inlineImages = [], array $attachments = []): bool {
    // CRLF injection prevention
    foreach ([$to, $subject, $fromEmail, $replyTo] as $h) {
        if (preg_match('/[\r\n]/', $h)) return false;
    }
    $rt   = $replyTo !== '' ? $replyTo : $fromEmail;
    $bAlt = 'a_' . md5(uniqid('', true));
    $bRel = 'r_' . md5(uniqid('', true));
    $bMix = 'm_' . md5(uniqid('', true));

    // Build the inner content block (alternative + optional inline images)
    $altBlock  = "--{$bAlt}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$text}\r\n\r\n";
    $altBlock .= "--{$bAlt}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n--{$bAlt}--";

    if (empty($inlineImages)) {
        // Inner = multipart/alternative
        $innerType = "multipart/alternative; boundary=\"{$bAlt}\"";
        $innerBody = $altBlock;
    } else {
        // Inner = multipart/related wrapping alternative + CID images
        $relBody = "--{$bRel}\r\nContent-Type: multipart/alternative; boundary=\"{$bAlt}\"\r\n\r\n{$altBlock}\r\n\r\n";
        foreach ($inlineImages as $img) {
            $cid  = $img['cid'];
            $mime = $img['mime'] ?? 'image/png';
            $name = $img['name'] ?? 'image.png';
            $b64  = chunk_split(base64_encode($img['data']));
            $relBody .= "--{$bRel}\r\nContent-Type: {$mime}; name=\"{$name}\"\r\n";
            $relBody .= "Content-Transfer-Encoding: base64\r\nContent-ID: <{$cid}>\r\n";
            $relBody .= "Content-Disposition: inline; filename=\"{$name}\"\r\n\r\n{$b64}\r\n";
        }
        $relBody .= "--{$bRel}--";
        $innerType = "multipart/related; boundary=\"{$bRel}\"";
        $innerBody = $relBody;
    }

    if (empty($attachments)) {
        // No attachments — send inner block directly
        $h  = "MIME-Version: 1.0\r\n";
        $h .= "Content-Type: {$innerType}\r\n";
        $h .= "From: {$fromName} <{$fromEmail}>\r\nReply-To: {$rt}\r\nReturn-Path: {$fromEmail}\r\n";
        return mail($to, $subject, $innerBody, $h, "-f{$fromEmail}");
    }

    // With attachments — wrap everything in multipart/mixed
    $h  = "MIME-Version: 1.0\r\n";
    $h .= "Content-Type: multipart/mixed; boundary=\"{$bMix}\"\r\n";
    $h .= "From: {$fromName} <{$fromEmail}>\r\nReply-To: {$rt}\r\nReturn-Path: {$fromEmail}\r\n";

    $body  = "--{$bMix}\r\nContent-Type: {$innerType}\r\n\r\n{$innerBody}\r\n\r\n";
    foreach ($attachments as $att) {
        $fname = $att['filename'] ?? 'attachment';
        $mime  = $att['mime']     ?? 'application/octet-stream';
        $b64   = chunk_split(base64_encode($att['data']));
        $body .= "--{$bMix}\r\n";
        $body .= "Content-Type: {$mime}; name=\"{$fname}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$fname}\"\r\n\r\n";
        $body .= $b64 . "\r\n";
    }
    $body .= "--{$bMix}--";
    return mail($to, $subject, $body, $h, "-f{$fromEmail}");
}

function formatDatesDE(array $b): string {
    $dates = $b['dates'] ?? (isset($b['date']) ? [$b['date']] : []);
    if (count($dates) === 0) return '—';
    if (count($dates) === 1) {
        $d = DateTime::createFromFormat('Y-m-d', $dates[0]);
        $fmt = new IntlDateFormatter('de_DE', IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'Europe/Berlin');
        return $fmt && $d ? $fmt->format($d) : ($d ? $d->format('d.m.Y') : $dates[0]);
    }
    $fmt   = new IntlDateFormatter('de_DE', IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'Europe/Berlin');
    $first = DateTime::createFromFormat('Y-m-d', $dates[0]);
    $last  = DateTime::createFromFormat('Y-m-d', end($dates));
    $f     = $fmt && $first ? $fmt->format($first) : ($first ? $first->format('d.m.Y') : $dates[0]);
    $l     = $fmt && $last  ? $fmt->format($last)  : ($last  ? $last->format('d.m.Y')  : end($dates));
    return "{$f} – {$l} (" . count($dates) . " Tage)";
}

function applyTemplate(string $tpl, array $vars): string {
    foreach ($vars as $k => $v) {
        $tpl = str_replace('{' . $k . '}', (string)$v, $tpl);
    }
    return $tpl;
}

$bookingId    = trim((string)($_POST['booking_id']    ?? ''));
$action       = trim((string)($_POST['action']       ?? ''));
$extrasJson     = trim((string)($_POST['extras']         ?? '[]'));
$adminNote      = trim((string)($_POST['admin_note']     ?? ''));
$adminMessage   = trim((string)($_POST['admin_message']  ?? ''));
$isMemberTarif  = ($_POST['is_member_tarif'] ?? '0') === '1';

$extras = [];
$decoded = json_decode($extrasJson, true);
if (is_array($decoded)) {
    foreach ($decoded as $e) {
        if (!isset($e['label']) || (string)$e['label'] === '') continue;
        $extras[] = [
            'label' => trim((string)$e['label']),
            'qty'   => max(1, (int)($e['qty'] ?? 1)),
            'price' => max(0.0, (float)($e['price'] ?? 0)),
        ];
    }
}

// ── Manuelle Buchung anlegen ───────────────────────────────────────────────
if ($action === 'create_booking') {
    $cbName    = trim(strip_tags((string)($_POST['cb_name']    ?? '')));
    $cbEmail   = strtolower(trim((string)($_POST['cb_email']   ?? '')));
    $cbPhone   = trim(strip_tags((string)($_POST['cb_phone']   ?? '')));
    $cbPurpose = trim(strip_tags((string)($_POST['cb_purpose'] ?? '')));
    $cbGuests  = max(1, (int)($_POST['cb_guests'] ?? 1));
    $cbNote    = trim(strip_tags((string)($_POST['cb_note']    ?? '')));
    $cbStatus  = in_array($_POST['cb_status'] ?? '', ['pending','confirmed'], true) ? $_POST['cb_status'] : 'confirmed';
    $cbDates   = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['cb_dates'] ?? '')))));
    // validate dates — strict roundtrip
    $_tz = new DateTimeZone('Europe/Berlin');
    $cbDates = array_values(array_filter($cbDates, function($d) use ($_tz) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $d, $_tz);
        return $dt && $dt->format('Y-m-d') === $d;
    }));
    if ($cbName !== '' && count($cbDates) > 0) {
        $bookings = file_exists(BOOKINGS_FILE) ? (json_decode((string)file_get_contents(BOOKINGS_FILE), true) ?: []) : [];
        $now = date('Y-m-d H:i:s');
        $newB = [
            'id'         => 'b_' . uniqid('', true),
            'name'       => $cbName,
            'email'      => $cbEmail,
            'phone'      => $cbPhone,
            'purpose'    => $cbPurpose,
            'guests'     => $cbGuests,
            'dates'      => $cbDates,
            'extras'     => $extras,
            'status'     => $cbStatus,
            'admin_note' => $cbNote,
            'created_at' => $now,
            'updated_at' => $now,
            'manual'     => true,
        ];
        if ($cbStatus === 'confirmed') {
            $newB['paid_at'] = !empty($_POST['cb_paid']) ? $now : null;
        }
        $bookings[] = $newB;
        file_put_contents(BOOKINGS_FILE, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        kgv_log_action('Buchung manuell angelegt', $cbName . ' · ' . implode(', ', array_map(fn($d) => (new DateTime($d))->format('d.m.Y'), $cbDates)));
        error_log("[admin] create_booking manual: {$cbName}\n", 3, LOG_FILE);
    }
    header('Location: /intern/?tab=bookings');
    exit;
}

if ($bookingId === '' || !in_array($action, ['confirm', 'reject', 'mark_paid', 'unmark_paid', 'mark_kaution_returned', 'update_note'], true)) {
    header('Location: /intern/');
    exit;
}

// Load bookings
$bookings = [];
if (file_exists(BOOKINGS_FILE)) {
    $raw = json_decode((string)file_get_contents(BOOKINGS_FILE), true);
    if (is_array($raw)) $bookings = $raw;
}

$found = false;
foreach ($bookings as &$b) {
    if (($b['id'] ?? '') !== $bookingId) continue;
    $found = true;
    if ($action === 'update_note') {
        $b['admin_note'] = trim((string)($_POST['admin_note'] ?? ''));
        error_log("[admin] update_note: {$bookingId}\n", 3, LOG_FILE);
        break;
    }
    if ($action === 'mark_paid') {
        $b['paid_at'] = date('Y-m-d H:i:s');
        $found = true;
        break;
    }
    if ($action === 'unmark_paid') {
        unset($b['paid_at']);
        $found = true;
        break;
    }
    if ($action === 'mark_kaution_returned') {
        $b['kaution_returned_at'] = date('Y-m-d H:i:s');
        $found = true;
        break;
    }

    $b['status']     = ($action === 'confirm') ? 'confirmed' : 'rejected';
    $b['updated_at'] = date('Y-m-d H:i:s');
    if ($action === 'confirm') {
        $b['extras']          = $extras;
        $b['admin_note']      = $adminNote;
        $b['admin_message']   = $adminMessage;
        $b['is_member_tarif'] = $isMemberTarif;
        $b['raummiete']       = $isMemberTarif ? 150.0 : 300.0;
    }

    $name         = $b['name']  ?? '';
    $email        = $b['email'] ?? '';
    $phone        = $b['phone'] ?? '';
    $purpose      = $b['purpose'] ?? '';
    $guests       = (int)($b['guests'] ?? 0);
    $dateFormatted = formatDatesDE($b);
    $dates        = $b['dates'] ?? (isset($b['date']) ? [$b['date']] : []);
    $dateShort    = count($dates) > 0
        ? (new DateTime($dates[0]))->format('d.m.Y')
        : '';

    if ($action === 'confirm') {
        // ---------------------------------------------------------------
        // Bestätigungs-E-Mail an Buchenden
        // ---------------------------------------------------------------
        // Kostenberechnung
        $raummiete   = $isMemberTarif ? 150.0 : 300.0;
        $extrasTotal = $raummiete;
        $extrasTextLines = '';
        $extrasHtmlRows  = '';
        foreach ($extras as $ex) {
            $sub  = $ex['price'] * $ex['qty'];
            $extrasTotal += $sub;
            $extrasTextLines .= $ex['label'] . ($ex['qty'] > 1 ? ' × ' . $ex['qty'] : '') . ': ' . number_format($sub, 2, ',', '.') . " EUR\n";
            $extrasHtmlRows  .= "<tr><td style='padding:5px 0;color:#5a6c5a;font-size:0.9rem;border-top:1px solid #e0ead6;'>"
                             . htmlspecialchars($ex['label']) . ($ex['qty'] > 1 ? ' × ' . $ex['qty'] : '')
                             . "</td><td style='padding:5px 0;font-weight:600;color:#2d3e2d;border-top:1px solid #e0ead6;text-align:right;'>"
                             . number_format($sub, 2, ',', '.') . " €</td></tr>";
        }
        $grandTotal = $extrasTotal + 200.0;

        $tplVars = [
            'name'           => $name,
            'datum'          => $dateFormatted,
            'betrag'         => number_format($extrasTotal, 2, ',', '.') . ' EUR',
            'gesamt'         => number_format($grandTotal,  2, ',', '.') . ' EUR',
            'zahlungsziel'   => $cfgZahlungsziel . ' Wochen',
            'iban'          => $cfgIban,
            'kontoinhaber'  => $cfgKontoInhaber,
            'kontakt_name'  => $cfgKontaktName,
            'telefon'       => $cfgTelefon,
            'email_kontakt' => $cfgEmail,
        ];
        $subject = applyTemplate($cfgEmailTpl['confirm_subject'], $tplVars);
        $text    = applyTemplate($cfgEmailTpl['confirm_body'],    $tplVars);
        if ($extrasTextLines !== '') {
            $text .= "\n\nExtras:\n" . $extrasTextLines;
        }
        if ($adminMessage !== '') {
            $text = $adminMessage . "\n\n" . $text;
        }

        // ── EPC/GiroCode QR generieren ──────────────────────────────────────
        $epcIban      = preg_replace('/\s+/', '', $cfgIban); // IBAN ohne Leerzeichen
        $epcAmount    = 'EUR' . number_format($grandTotal, 2, '.', '');
        $epcReference = 'Vereinshaus ' . $dateShort . ' | ' . $name;
        $epcData      = implode("\n", [
            'BCD',        // Service Tag
            '002',        // Version
            '1',          // Encoding: UTF-8
            'SCT',        // SEPA Credit Transfer
            '',           // BIC (optional, leer für SEPA-Inland)
            $cfgKontoInhaber,
            $epcIban,
            $epcAmount,
            '',           // Purpose code (leer)
            '',           // Structured reference (leer)
            $epcReference,
        ]);
        $epcQrHtml   = '';
        $qrInlineImg = [];
        if ($epcIban !== '') {
            require_once dirname(__DIR__) . '/inc/local_qr.php';
            try {
                $qrImg = kgv_qr_png($epcData, 180);
                $qrCid       = 'qr_' . bin2hex(random_bytes(8)) . '@verein';
                $qrInlineImg = [['cid' => $qrCid, 'data' => $qrImg, 'mime' => 'image/png', 'name' => 'girocode.png']];
                $epcQrHtml =
                    "<div style='background:#f8faf8;border-radius:10px;padding:20px;margin-bottom:20px;"
                  . "border:1px solid #d4e6c3;text-align:center'>"
                  . "<p style='margin:0 0 6px;font-size:0.72rem;font-weight:700;color:#3d6b41;"
                  .    "text-transform:uppercase;letter-spacing:.08em'>Direkt mit Banking-App bezahlen</p>"
                  . "<p style='margin:0 0 14px;font-size:0.82rem;color:#5a6c5a;line-height:1.5'>"
                  .    "QR-Code mit deiner Banking-App scannen →<br>Überweisung wird automatisch befüllt</p>"
                  . "<img src='cid:{$qrCid}' width='160' height='160'"
                  .    " alt='GiroCode QR' style='display:block;margin:0 auto 14px;border:6px solid #fff;"
                  .    "border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.12)'>"
                  . "<p style='margin:0;font-size:0.75rem;color:#8a9a8a;line-height:1.4'>"
                  .    "Unterstützt von Sparkasse, Volksbank, DKB, ING, Commerzbank u.v.m.</p>"
                  . "</div>";
            } catch (Throwable $e) {
                error_log('[girocode] ' . $e->getMessage());
            }
        }

        // IBAN formatiert für Anzeige (Gruppen zu 4)
        $epcIbanFormatted = trim(chunk_split($epcIban, 4, ' '));

        // Sandras Custom-Text aus dem confirm_body-Template — Anrede entfernen, da Greeting bereits oben
        $_confTextNoGreeting = preg_replace('/^\s*Liebe\/r\s+' . preg_quote($name, '/') . '\s*,?\s*\r?\n+/u', '', $text) ?? $text;

        $_confirmContent =
              "<div style='background:#e8f5e9;border-radius:8px;padding:14px 18px;margin-bottom:22px'>"
            . "<p style='margin:0;font-weight:700;color:#2e7d32;font-size:1.02rem'>✓ Deine Buchung ist bestätigt!</p>"
            . "<p style='margin:4px 0 0;font-size:0.85rem;color:#5a6c5a'>Verbindliche Buchungsbestätigung</p></div>"
            . "<div style='color:#5a6c5a;line-height:1.7;margin-bottom:20px;white-space:pre-wrap'>"
            . nl2br(htmlspecialchars($_confTextNoGreeting), false)
            . "</div>"
            . ($adminMessage !== '' ? "<div style='background:#e8f5e9;border-radius:8px;padding:14px 18px;margin-bottom:20px;border-left:4px solid #3d6b41'><p style='margin:0;color:#2d3e2d;line-height:1.7'>" . nl2br(htmlspecialchars($adminMessage)) . "</p></div>" : '')

            // Buchungsdetails
            . "<div style='background:#f5f7f2;border-radius:8px;padding:16px 20px;margin-bottom:20px;border-left:4px solid #3d6b41'>"
            . "<p style='margin:0 0 10px;font-size:0.72rem;font-weight:700;color:#5a8c5e;text-transform:uppercase;letter-spacing:.08em'>Buchungsdetails</p>"
            . "<table style='width:100%;border-collapse:collapse'>"
            . "<tr><td style='padding:6px 0;color:#5a6c5a;width:90px;font-size:0.9rem'>Zeitraum</td><td style='padding:6px 0;font-weight:700;color:#2d3e2d'>" . htmlspecialchars($dateFormatted) . "</td></tr>"
            . ($purpose !== '' ? "<tr><td style='padding:6px 0;color:#5a6c5a;font-size:0.9rem;border-top:1px solid #e8f0e0'>Anlass</td><td style='padding:6px 0;color:#2d3e2d;border-top:1px solid #e8f0e0'>" . htmlspecialchars($purpose) . "</td></tr>" : '')
            . ($guests > 0 ? "<tr><td style='padding:6px 0;color:#5a6c5a;font-size:0.9rem;border-top:1px solid #e8f0e0'>Personen</td><td style='padding:6px 0;color:#2d3e2d;border-top:1px solid #e8f0e0'>{$guests}</td></tr>" : '')
            . "</table></div>"

            // Kostenaufstellung
            . "<div style='background:#fff8e1;border-radius:8px;padding:16px 20px;margin-bottom:20px;border-left:4px solid #f9a825'>"
            . "<p style='margin:0 0 10px;font-size:0.72rem;font-weight:700;color:#f57f17;text-transform:uppercase;letter-spacing:.08em'>Kostenaufstellung</p>"
            . ($isMemberTarif ? "<div style='display:inline-block;background:#e8f5e9;color:#2e7d32;font-size:0.75rem;font-weight:700;padding:3px 10px;border-radius:20px;margin-bottom:10px'>✓ Mitgliedstarif · 50% Rabatt</div>" : '')
            . "<table style='width:100%;border-collapse:collapse'>"
            . "<tr><td style='padding:5px 0;color:#5a6c5a;font-size:0.9rem'>" . ($isMemberTarif ? 'Raummiete <span style="color:#2e7d32;font-size:0.8rem">(Mitgliedstarif)</span>' : 'Raummiete') . "</td><td style='padding:5px 0;font-weight:600;color:#2d3e2d;text-align:right'>" . number_format($raummiete, 2, ',', '.') . " €</td></tr>"
            . $extrasHtmlRows
            . "<tr><td style='padding:8px 0 0;color:#2d3e2d;font-weight:700;border-top:2px solid #e0c000'>Raummiete gesamt</td><td style='padding:8px 0 0;font-weight:700;color:#2d3e2d;border-top:2px solid #e0c000;text-align:right;font-size:1.05rem'>" . number_format($extrasTotal, 2, ',', '.') . " €</td></tr>"
            . "<tr><td style='padding:5px 0;color:#8a9a8a;font-size:0.85rem;border-top:1px solid #f5e090'>+ Kaution (wird nach der Veranstaltung zurückerstattet)</td><td style='padding:5px 0;color:#8a9a8a;font-size:0.85rem;border-top:1px solid #f5e090;text-align:right'>200,00 €</td></tr>"
            . "<tr style='background:rgba(249,168,37,0.08)'>"
            .   "<td style='padding:10px 8px 10px 0;color:#3d6b41;font-weight:700;font-size:1.05rem'>Zu überweisen gesamt</td>"
            .   "<td style='padding:10px 0 10px 8px;font-weight:700;color:#3d6b41;text-align:right;font-size:1.2rem;border-top:2px solid #f9a825'>" . number_format($grandTotal, 2, ',', '.') . " €</td>"
            . "</tr>"
            . "</table>"
            . "<p style='margin:10px 0 0;font-size:0.8rem;color:#8a9a8a'>⚠ Bitte bis spätestens {$cfgZahlungsziel} Wochen vor der Veranstaltung überweisen.</p>"
            . "</div>"

            // QR Code
            . $epcQrHtml

            // Überweisungsdaten
            . "<div style='background:#f5f7f2;border-radius:8px;padding:16px 20px;margin-bottom:8px;border-left:4px solid #3d6b41'>"
            . "<p style='margin:0 0 12px;font-size:0.72rem;font-weight:700;color:#5a8c5e;text-transform:uppercase;letter-spacing:.08em'>Überweisungsdaten</p>"
            . "<table style='width:100%;border-collapse:collapse'>"
            . "<tr><td style='padding:6px 0;color:#5a6c5a;font-size:0.85rem;width:110px;vertical-align:top'>Empfänger</td>"
            .     "<td style='padding:6px 0;color:#2d3e2d;font-weight:600'>" . htmlspecialchars($cfgKontoInhaber) . "</td></tr>"
            . "<tr><td style='padding:6px 0;color:#5a6c5a;font-size:0.85rem;border-top:1px solid #e8f0e0;vertical-align:top'>IBAN</td>"
            .     "<td style='padding:6px 0;color:#2d3e2d;font-weight:600;font-family:monospace;letter-spacing:.05em;border-top:1px solid #e8f0e0'>" . htmlspecialchars($epcIbanFormatted) . "</td></tr>"
            . ($cfgBank !== '' ? "<tr><td style='padding:6px 0;color:#5a6c5a;font-size:0.85rem;border-top:1px solid #e8f0e0'>Bank</td><td style='padding:6px 0;color:#2d3e2d;border-top:1px solid #e8f0e0'>" . htmlspecialchars($cfgBank) . "</td></tr>" : '')
            . "<tr><td style='padding:6px 0;color:#5a6c5a;font-size:0.85rem;border-top:1px solid #e8f0e0;vertical-align:top'>Betrag</td>"
            .     "<td style='padding:6px 0;color:#3d6b41;font-weight:700;font-size:1.05rem;border-top:1px solid #e8f0e0'>" . number_format($grandTotal, 2, ',', '.') . " €</td></tr>"
            . "<tr><td style='padding:6px 0;color:#5a6c5a;font-size:0.85rem;border-top:1px solid #e8f0e0;vertical-align:top'>Verwendungszweck</td>"
            .     "<td style='padding:6px 0;color:#2d3e2d;font-weight:600;border-top:1px solid #e8f0e0'>" . htmlspecialchars($epcReference) . "</td></tr>"
            . "</table>"
            . "</div>";
        // Buchungs-Kontakt als Signatur (statt eingeloggtem Vorstand)
        $html = kgv_email_html(
            'Hallo ' . htmlspecialchars($name) . ' 👋,',
            $_confirmContent,
            'Buchungsbestätigung – Vereinshaus',
            $cfgKontaktName, $cfgTelefon, $cfgEmail, $cfgKontaktRolle
        );

        // Vorabinfo-PDF generieren und anhängen (immer — Preis im PDF berücksichtigt Mitgliedstarif)
        $_vorabAttachments = [];
        try {
            $_vContent  = file_exists(CONTENT_FILE) ? (json_decode((string)file_get_contents(CONTENT_FILE), true) ?: []) : [];
            $_vPrices   = $_vContent['prices']   ?? [];
            $_vSettings = $_vContent['settings'] ?? [];
            $_vPdf = generate_vorabinfo_pdf($b, $_vPrices, $_vSettings);
            $_vorabAttachments[] = [
                'filename' => 'Vorabinfo_Vereinshausmietung.pdf',
                'mime'     => 'application/pdf',
                'data'     => $_vPdf,
            ];
        } catch (\Throwable $_ve) {
            error_log("[admin] vorabinfo PDF error: " . $_ve->getMessage() . "\n", 3, LOG_FILE);
        }
        send_mail_simple($email, $subject, $text, $html, $fromName, $fromEmail, $fromEmail, $qrInlineImg, $_vorabAttachments);
        error_log("[admin] confirmed: {$bookingId} email={$email}\n", 3, LOG_FILE);
        kgv_log_action('Buchung bestätigt', $name . ' · ' . $dateFormatted);

        // ── Vortag (Übergabe/Vorbereitung) + Folgetag (Aufräumen) automatisch sperren ──
        if (!empty($dates)) {
            sort($dates);
            $_bufferBefore = (new DateTimeImmutable($dates[0]))->modify('-1 day')->format('Y-m-d');
            $_bufferAfter  = (new DateTimeImmutable(end($dates)))->modify('+1 day')->format('Y-m-d');
            $_cData       = file_exists(CONTENT_FILE) ? (json_decode((string)file_get_contents(CONTENT_FILE), true) ?: []) : [];
            $_allBookings = file_exists(BOOKINGS_FILE) ? (json_decode((string)file_get_contents(BOOKINGS_FILE), true) ?: []) : [];
            $_changed = false;
            foreach ([
                [$_bufferBefore, 'Vorbereitung/Übergabe: '],
                [$_bufferAfter,  'Aufräumen/Übergabe: '],
            ] as $_bufSpec) {
                [$_bDate, $_reasonPrefix] = $_bufSpec;
                $_alreadyBlocked = false;
                foreach ($_cData['blocked_dates'] ?? [] as $_bd) {
                    if (($_bd['date'] ?? '') === $_bDate) { $_alreadyBlocked = true; break; }
                }
                if (!$_alreadyBlocked) {
                    foreach ($_allBookings as $_ob) {
                        if (($_ob['id'] ?? '') === $bookingId) continue;
                        if (!in_array(($_ob['status'] ?? ''), ['confirmed', 'pending'], true)) continue;
                        foreach ($_ob['dates'] ?? [] as $_od) {
                            if ($_od === $_bDate) { $_alreadyBlocked = true; break 2; }
                        }
                    }
                }
                if (!$_alreadyBlocked) {
                    $_cData['blocked_dates'][] = [
                        'date'   => $_bDate,
                        'reason' => $_reasonPrefix . $name . ' (' . $dateShort . ') [' . $bookingId . ']',
                    ];
                    $_changed = true;
                    error_log("[admin] Buffer-Tag gesperrt: {$_bDate} ({$_reasonPrefix}) für Buchung {$bookingId}\n", 3, LOG_FILE);
                }
            }
            if ($_changed) {
                file_put_contents(CONTENT_FILE, json_encode($_cData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
        }

    } else {
        // ---------------------------------------------------------------
        // Absage-E-Mail an Buchenden
        // ---------------------------------------------------------------
        $tplVarsReject = [
            'name'          => $name,
            'datum'         => $dateFormatted,
            'kontakt_name'  => $cfgKontaktName,
            'telefon'       => $cfgTelefon,
            'email_kontakt' => $cfgEmail,
        ];
        $subject = applyTemplate($cfgEmailTpl['reject_subject'], $tplVarsReject);
        $text    = applyTemplate($cfgEmailTpl['reject_body'],    $tplVarsReject);

        // HTML-Body aus dem editierbaren reject_body-Template (statt hartkodiert)
        $_rejTextNoGreeting = preg_replace('/^\s*Liebe\/r\s+' . preg_quote($name, '/') . '\s*,?\s*\r?\n+/u', '', $text) ?? $text;
        $_rejectContent = "<div style='color:#5a6c5a;line-height:1.7;margin-bottom:18px;white-space:pre-wrap'>"
                        . nl2br(htmlspecialchars($_rejTextNoGreeting), false)
                        . "</div>";
        // Buchungs-Kontakt als Signatur (statt eingeloggtem Vorstand)
        $html = kgv_email_html(
            'Hallo ' . htmlspecialchars($name) . ',',
            $_rejectContent,
            'Zu deiner Anfrage – Vereinshaus',
            $cfgKontaktName, $cfgTelefon, $cfgEmail, $cfgKontaktRolle
        );

        send_mail_simple($email, $subject, $text, $html, $fromName, $fromEmail, $fromEmail);
        error_log("[admin] rejected: {$bookingId} email={$email}\n", 3, LOG_FILE);
        kgv_log_action('Buchung abgelehnt', $name . ' · ' . $dateFormatted);
        removeBookingBufferDay($dates, $bookingId, $name);
    }
    break;
}
unset($b);

if ($found) {
    file_put_contents(BOOKINGS_FILE, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ── Buchung löschen (nur web-Rolle + SuperAdmin) ──────────────────────────────
if ($action === 'delete_booking') {
    if (!$_canDeleteBooking) {
        header('Location: /intern/?tab=bookings&err=no_permission');
        exit;
    }
    $bookings = [];
    if (file_exists(BOOKINGS_FILE)) {
        $raw = json_decode((string)file_get_contents(BOOKINGS_FILE), true);
        if (is_array($raw)) $bookings = $raw;
    }
    $delName  = '';
    $delDates = [];
    $bookings = array_values(array_filter($bookings, function($b) use ($bookingId, &$delName, &$delDates) {
        if (($b['id'] ?? '') === $bookingId) {
            $delName  = $b['name']  ?? $bookingId;
            $delDates = $b['dates'] ?? [];
            return false;
        }
        return true;
    }));
    file_put_contents(BOOKINGS_FILE, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    removeBookingBufferDay($delDates, $bookingId, $delName);
    kgv_log_action('Buchung gelöscht', $delName . ' · ' . $bookingId);
    header('Location: /intern/?tab=bookings&deleted=1');
    exit;
}

header('Location: /intern/?tab=bookings');
exit;
