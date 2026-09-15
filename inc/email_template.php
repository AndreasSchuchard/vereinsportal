<?php
declare(strict_types=1);
require_once __DIR__ . '/settings_loader.php';


/**
 * Buchungs-Kontakt: für ALLE Vereinshaus-Buchungsmails (Inquiry, Confirm,
 * Reject, Vorabinfo-PDF) wird automatisch das Vorstandsmitglied mit
 * 'Kassier' oder 'Vermietung' in der Rolle gezogen (typischerweise Nicole
 * Musterfrau „Kassiererin & Vermietung"). Fallback: settings.kontakt_*.
 *
 * So bleibt die generische `settings.kontakt_*`-Sektion für andere Mails
 * (Schriftführerin etc.) ungestört, während Buchungs-Mails immer die für
 * Vermietung zuständige Person ausweisen — unabhängig davon, wer im
 * Backoffice die Bestätigung anklickt.
 */
function kgv_get_booking_contact(?array $content = null): array {
    if ($content === null) {
        $ccf = dirname(__DIR__) . '/data/content.json';
        $content = file_exists($ccf) ? (json_decode((string)file_get_contents($ccf), true) ?: []) : [];
    }
    $settings = (array)($content['settings'] ?? []);
    $fallback = [
        'name'  => (string)($settings['kontakt_name']  ?? 'KGV Musterstadt e.V.'),
        'rolle' => (string)($settings['kontakt_rolle'] ?? 'Vermietung'),
        'phone' => (string)($settings['telefon']       ?? ''),
        'email' => (string)($settings['email']         ?? 'kontakt@example.org'),
    ];
    foreach ((array)($content['vorstand'] ?? []) as $v) {
        $role = strtolower((string)($v['role'] ?? ''));
        if (str_contains($role, 'kassier') || str_contains($role, 'vermiet')) {
            return [
                'name'  => (string)($v['name']  ?? $fallback['name']),
                'rolle' => (string)($v['role']  ?? $fallback['rolle']),
                'phone' => (string)($v['phone'] ?? $fallback['phone']),
                'email' => (string)($v['email'] ?? $fallback['email']),
            ];
        }
    }
    return $fallback;
}

/**
 * 1. Vorsitzender — für offizielle Dokumente (Vorabinfo, Briefkopf, Impressum-Equivalent).
 *
 * Sucht im content.vorstand-Array das Mitglied mit Rolle "Vorsitz" / "Vorsitzend" /
 * "1. Vorsitzender". Fällt zurück auf settings.kontakt_name nur dann, wenn KEIN
 * Vorstandsmitglied gefunden wird. So kann Sandra in settings.kontakt_name
 * gerne ihre Daten als Schriftführerin pflegen — offizielle Briefe ziehen
 * trotzdem korrekt den Vorsitzenden aus der Vorstand-Liste.
 */
function kgv_get_vorsitz(?array $content = null): array {
    if ($content === null) {
        $ccf = dirname(__DIR__) . '/data/content.json';
        $content = file_exists($ccf) ? (json_decode((string)file_get_contents($ccf), true) ?: []) : [];
    }
    foreach ((array)($content['vorstand'] ?? []) as $v) {
        $role = (string)($v['role'] ?? '');
        if (preg_match('/vorsit(z|zend)/i', $role)) {
            return [
                'name'  => (string)($v['name']  ?? 'Max Mustermann'),
                'rolle' => $role !== '' ? $role : '1. Vorsitzender',
                'phone' => (string)($v['phone'] ?? ''),
                'email' => (string)($v['email'] ?? ''),
            ];
        }
    }
    // Fallback: hartcodierter Default
    return [
        'name'  => 'Max Mustermann',
        'rolle' => '1. Vorsitzender',
        'phone' => '',
        'email' => '',
    ];
}

/**
 * Unified KGV Musterstadt e-mail template — Option A "Warm & Klar"
 *
 * @param string $greeting  "Hallo Andreas 👋," (HTML or plain)
 * @param string $content   main body HTML
 * @param string $subtitle  small text next to logo in header
 * @param string $sigName   signer's display name
 * @param string $sigPhone  signer's phone  ('' = omit)
 * @param string $sigEmail  signer's e-mail
 * @param string $sigRole   signer's role (default: '1. Vorsitzende')
 */
/**
 * Returns signature data for the currently logged-in admin.
 * Member-admins use their own profile; super-admin falls back to content.json.
 *
 * @return array{name:string, phone:string, email:string, rolle:string}
 */
function kgv_log_action(string $action, string $detail = ''): void {
    $actFile = defined('ACTIVITY_FILE') ? ACTIVITY_FILE : (dirname(__DIR__) . '/data/activity.json');
    $who = !empty($_SESSION['kgv_admin']) ? 'SuperAdmin' : htmlspecialchars($_SESSION['kgv_member']['name'] ?? 'Unbekannt');
    $entry = ['ts' => date('Y-m-d H:i:s'), 'user' => $who, 'action' => $action, 'detail' => $detail];
    $log = file_exists($actFile) ? (json_decode((string)file_get_contents($actFile), true) ?: []) : [];
    array_unshift($log, $entry);
    if (count($log) > 200) $log = array_slice($log, 0, 200);
    @file_put_contents($actFile, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function kgv_get_admin_sig(): array {
    $m = $_SESSION['kgv_member'] ?? [];
    if (!empty($m['name'])) {
        return [
            'name'  => $m['name'],
            'phone' => $m['phone']     ?? '',
            'email' => $m['email']     ?? 'vorstand@example.org',
            'rolle' => $m['sig_rolle'] ?? 'Vorstand',
        ];
    }
    // Super-admin fallback → content.json settings
    $ccf = dirname(__DIR__) . '/data/content.json';
    $cc  = file_exists($ccf) ? (json_decode((string)file_get_contents($ccf), true) ?: []) : [];
    return [
        'name'  => $cc['settings']['kontakt_name']  ?? 'KGV Musterstadt e.V.',
        'phone' => $cc['settings']['telefon']       ?? '',
        'email' => $cc['settings']['email']         ?? 'vorstand@example.org',
        'rolle' => $cc['settings']['kontakt_rolle'] ?? '1. Vorsitzender',
    ];
}

function kgv_email_html(
    string $greeting,
    string $content,
    string $subtitle       = 'Nachricht vom Vorstand',
    string $sigName        = 'KGV Musterstadt e.V.',
    string $sigPhone       = '',
    string $sigEmail       = 'vorstand@example.org',
    string $sigRole        = 'Vorstand',
    string $unsubscribeUrl = '',
    string $recipientEmail = ''
): string {
    $sig  = '<strong>' . htmlspecialchars($sigName) . '</strong><br>';
    $sig .= htmlspecialchars($sigRole) . ' · KGV Musterstadt e.V.<br>';
    $sig .= ($sigPhone !== '' ? '📞 ' . htmlspecialchars($sigPhone) . ' &nbsp;·&nbsp; ' : '');
    $sig .= '✉ ' . htmlspecialchars($sigEmail);

    return
        "<!DOCTYPE html><html lang='de'>"
      . "<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>"
      . "<body style='margin:0;padding:0;background:#f2f6f0;font-family:Arial,Helvetica,sans-serif'>"
      . "<div style='max-width:580px;margin:0 auto;padding:28px 16px'>"

      // ── Header ──────────────────────────────────────────────────────────
      . "<div style='background:#3d6b41;border-radius:12px 12px 0 0;padding:18px 26px;"
      .              "display:flex;align-items:center;gap:16px'>"
      . "<img src='" . site_url() . "/images/logo.png' alt='KGV Musterstadt e.V.' height='42'"
      .      " style='display:block;max-height:42px;flex-shrink:0'>"
      . "<span style='color:#b8d9ba;font-size:0.82rem;line-height:1.4'>" . htmlspecialchars($subtitle) . "</span>"
      . "</div>"

      // ── Card body ────────────────────────────────────────────────────────
      . "<div style='background:#ffffff;padding:28px 32px;"
      .              "border-radius:0 0 12px 12px;border:1px solid #d4e6c3;border-top:none'>"
      . "<p style='margin:0 0 22px;font-size:1.02rem;color:#2d3e2d;font-weight:700'>" . $greeting . "</p>"
      . $content

      // ── Signature ────────────────────────────────────────────────────────
      . "<div style='margin-top:28px;padding-top:16px;border-top:2px solid #e8f0e0;"
      .              "font-size:0.85rem;color:#3d6b41;line-height:1.7'>"
      . "<p style='margin:0 0 10px;color:#5a6c5a;font-size:0.9rem'>Viele Grüße aus Musterstadt 🌱</p>"
      . $sig
      . "</div>"
      . "</div>"

      // ── Footer with address + optional unsubscribe ──────────────────────────
      . "<div style='text-align:center;padding:14px;font-size:0.68rem;color:#9aaa9a;line-height:1.7'>"
      . "KGV Musterstadt e.V. · Kleingartenverein<br>"
      . "Musterstraße 1 · 12345 Musterstadt<br>"
      . ($recipientEmail !== '' ? "Diese E-Mail wurde gesendet an " . htmlspecialchars($recipientEmail) . ".<br>" : "")
      . "Diese E-Mail wurde automatisch generiert."
      . ($unsubscribeUrl !== ''
          ? "<br><a href='" . htmlspecialchars($unsubscribeUrl) . "' "
          . "style='color:#9aaa9a;text-decoration:underline;font-size:0.65rem'>Benachrichtigungen abmelden</a>"
          : "")
      . "</div>"

      . "</div></body></html>";
}
