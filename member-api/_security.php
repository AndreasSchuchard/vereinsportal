<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/settings_loader.php';


/**
 * Member-API Security-Helper.
 * Muss VOR session_start() geladen werden.
 *  - Setzt sichere Session-Cookie-Flags
 *  - Origin-/Referer-Check gegen https://verein.example.org blockt klassische CSRF
 *  - CRLF-Sanitizer für Mail-Header-Felder
 */

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure',   '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_only_cookies','1');
ini_set('session.use_strict_mode', '1');
ini_set('display_errors',          '0');
ini_set('log_errors',              '1');

/**
 * Verifiziert Origin/Referer für state-changing Requests.
 * Schickt 403 + json bei Mismatch und beendet sofort.
 */
function memberapi_check_origin(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $allowed = [site_url()];
    $origin  = (string)($_SERVER['HTTP_ORIGIN']  ?? '');
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');

    if ($origin !== '') {
        if (!in_array($origin, $allowed, true)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'forbidden_origin']);
            exit;
        }
    } elseif ($referer !== '') {
        $ok = false;
        foreach ($allowed as $a) {
            if (str_starts_with($referer, $a . '/')) { $ok = true; break; }
        }
        if (!$ok) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'forbidden_referer']);
            exit;
        }
    }
    // Wenn weder Origin noch Referer gesendet: SameSite=Strict-Cookie blockt CSRF zusätzlich.
}

/**
 * Entfernt Zeilenumbrüche/Nullbytes aus Strings, die in Mail-Headern (Subject, To, From)
 * verwendet werden — verhindert CRLF-Injection (Header-Spaltung).
 */
function memberapi_safe_header_value(string $s, int $max = 200): string {
    $s = preg_replace('/[\r\n\x00]+/', ' ', $s) ?? '';
    return substr(trim(strip_tags($s)), 0, $max);
}
