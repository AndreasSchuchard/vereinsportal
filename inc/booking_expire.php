<?php
declare(strict_types=1);
require_once __DIR__ . '/settings_loader.php';


/**
 * 24h Auto-Expire für Pending-Buchungsanfragen.
 *
 * Wird beim Öffnen von /intern/ lazy aufgerufen (siehe intern/index.php).
 * Später optional via Strato-Cronjob `https://verein.example.org/cron-expire-bookings.php` ergänzbar.
 *
 * Logik:
 * - Pending-Anfragen, deren created_at > 24h zurückliegt, werden auf status='expired' gesetzt.
 * - Datum gibt im Kalender wieder frei (filterung im Frontend nach status).
 * - Mail an Anmelder geht raus (höflicher Hinweis: Frist abgelaufen, gerne neu anfragen).
 * - Nicole (Buchungs-Kontakt) bekommt BCC.
 * - admin.log bekommt Eintrag.
 *
 * Nicht gelöscht — soft expire, Eintrag bleibt im Backoffice sichtbar (Filter 'expired').
 */

require_once __DIR__ . '/email_template.php';

/**
 * Prüft alle Pending-Buchungen, markiert > 24h alte als 'expired', speichert,
 * versendet Mails. Liefert die Anzahl abgelaufener Buchungen zurück.
 *
 * @param string $bookingsFile  Pfad zu bookings.json
 * @param int    $hours         Stundenzahl bis Ablauf (Default 24)
 * @param string $logFile       Pfad zu admin.log
 * @return int                  Anzahl neu expirierter Buchungen
 */
function kgv_expire_old_pending_bookings(string $bookingsFile, int $hours = 24, string $logFile = ''): int {
    if (!file_exists($bookingsFile)) return 0;

    $raw      = (string)file_get_contents($bookingsFile);
    $bookings = json_decode($raw, true);
    if (!is_array($bookings)) return 0;

    $now      = time();
    $cutoff   = $hours * 3600;
    // Karenz-Klausel: nur Buchungen, die AB Inkrafttreten der 24h-Regel
    // reingekommen sind (2026-06-02 00:00 Europe/Berlin). Pre-existing
    // Pending-Anfragen bleiben unangetastet.
    $ruleStartTs = strtotime('2026-06-02 00:00:00');
    $expired  = [];
    $changed  = false;

    foreach ($bookings as &$b) {
        if (($b['status'] ?? '') !== 'pending') continue;
        if (!empty($b['expired_at'])) continue;
        $createdTs = strtotime((string)($b['created_at'] ?? ''));
        if (!$createdTs) continue;
        if ($createdTs < $ruleStartTs) continue;       // Karenz: vor Inkrafttreten → exempt
        if (($now - $createdTs) < $cutoff) continue;    // noch innerhalb der 24h

        $b['status']     = 'expired';
        $b['expired_at'] = date('Y-m-d H:i:s');
        $b['updated_at'] = date('Y-m-d H:i:s');
        $expired[]       = $b;
        $changed         = true;
    }
    unset($b);

    if (!$changed) return 0;

    // Persist
    @file_put_contents(
        $bookingsFile,
        json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    // Mails verschicken
    foreach ($expired as $b) {
        @kgv_send_booking_expire_mail($b);
        if ($logFile !== '') {
            @error_log(
                "[intern] auto-expired pending booking: id={$b['id']} name={$b['name']} email={$b['email']}\n",
                3, $logFile
            );
        }
    }

    return count($expired);
}

/**
 * Sendet die Ablauf-Mail an den Anmelder (BCC an Buchungs-Kontakt / Nicole).
 */
function kgv_send_booking_expire_mail(array $b): bool {
    $email = trim((string)($b['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    $name  = (string)($b['name']    ?? '');
    $dates = $b['dates'] ?? [];
    if (is_array($dates) && count($dates) > 0) {
        $fmt = [];
        foreach ($dates as $d) {
            $ts = strtotime((string)$d);
            if ($ts) $fmt[] = date('d.m.Y', $ts);
        }
        $dateStr = implode(' / ', $fmt);
    } else {
        $dateStr = is_string($dates) ? $dates : '';
    }

    // Buchungs-Kontakt (Nicole) als BCC + Signatur
    $contact   = kgv_get_booking_contact();
    $fromEmail = 'kontakt@example.org';
    $fromName  = 'KGV Musterstadt e.V.';
    $sigName   = (string)($contact['name']  ?? 'Erika Musterfrau');
    $sigEmail  = (string)($contact['email'] ?? 'kontakt@example.org');
    $sigPhone  = (string)($contact['phone'] ?? '');
    $sigRole   = (string)($contact['rolle'] ?? 'Vermietung');

    // Nicole als BCC (falls Email gültig und nicht identisch mit Anmelder)
    $bccList = [];
    if (filter_var($sigEmail, FILTER_VALIDATE_EMAIL) && strcasecmp($sigEmail, $email) !== 0) {
        $bccList[] = $sigEmail;
    }

    $subject = 'Deine Buchungsanfrage ist abgelaufen — KGV Musterstadt Vereinshaus';

    $textBody =
          "Hallo {$name},\n\n"
        . "deine Anfrage für das KGV-461-Vereinshaus" . ($dateStr !== '' ? " am {$dateStr}" : '') . " ist leider innerhalb der 24-Stunden-Frist nicht beantwortet worden.\n\n"
        . "Die Reservierung wurde automatisch freigegeben — der Termin ist im Kalender wieder buchbar.\n\n"
        . "Falls dein Termin noch aktuell ist, melde dich gerne erneut:\n"
        . site_url() . "/vereinshaus\n\n"
        . "Wir kümmern uns dann schneller. Sorry für die Wartezeit.\n\n"
        . "Freundliche Grüße\n{$sigName}\n{$sigRole} · KGV Musterstadt e.V.";

    $htmlBody =
          "<p>deine Anfrage für das KGV-461-Vereinshaus"
        . ($dateStr !== '' ? " am <strong>" . htmlspecialchars($dateStr) . "</strong>" : '')
        . " ist leider innerhalb der 24-Stunden-Frist nicht beantwortet worden.</p>"
        . "<p>Die Reservierung wurde automatisch freigegeben — der Termin ist im Kalender wieder buchbar.</p>"
        . "<div style='background:#fff8e1;border-left:4px solid #f9a825;border-radius:8px;padding:14px 18px;margin:18px 0'>"
        .   "<p style='margin:0;font-size:0.92rem;line-height:1.6;color:#5a6c5a'>Falls dein Termin noch aktuell ist, melde dich gerne erneut:</p>"
        .   "<p style='margin:8px 0 0'><a href='" . site_url() . "/vereinshaus' style='display:inline-block;background:#3d6b41;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;font-weight:600'>→ Neue Anfrage stellen</a></p>"
        . "</div>"
        . "<p>Wir kümmern uns dann schneller. Sorry für die Wartezeit.</p>";

    $html = kgv_email_html(
        'Hallo ' . htmlspecialchars($name) . ' 👋,',
        $htmlBody,
        'Buchungsanfrage abgelaufen',
        $sigName, $sigPhone, $sigEmail, $sigRole
    );

    // CRLF injection guard
    foreach (array_merge([$email, $subject, $fromEmail, $sigEmail], $bccList) as $h) {
        if (preg_match('/[\r\n]/', $h)) return false;
    }

    $boundary = 'b_' . md5(uniqid('', true));
    $bodyMime  = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$textBody}\r\n\r\n";
    $bodyMime .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n--{$boundary}--";

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$sigEmail}\r\n";
    $headers .= "Return-Path: {$fromEmail}\r\n";
    if (!empty($bccList)) {
        $headers .= "Bcc: " . implode(', ', $bccList) . "\r\n";
    }

    return mail($email, $subject, $bodyMime, $headers, "-f{$fromEmail}");
}
