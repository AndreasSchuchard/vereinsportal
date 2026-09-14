<?php
declare(strict_types=1);

/** Configure every PHP session consistently before session_start(). */
function kgv_configure_session(): void {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_trans_sid', '0');
}

/**
 * Start a session only when the browser already has a session cookie.
 * Public visitors therefore receive no unnecessary PHPSESSID.
 */
function kgv_start_existing_session(): bool {
    kgv_configure_session();

    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }

    $cookieName = session_name();
    $incomingId = (string)($_COOKIE[$cookieName] ?? '');
    if ($incomingId === '') {
        return false;
    }

    if (!session_start()) {
        throw new RuntimeException('Unable to start PHP session.');
    }

    // Upgrade valid cookies created before the site-wide flags were introduced.
    // If strict mode replaced an invalid ID, session_start() already set the cookie.
    if (hash_equals($incomingId, session_id())) {
        setcookie($cookieName, session_id(), [
            'expires' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    return true;
}

/** Start a new or existing secure session for login-protected pages. */
function kgv_start_secure_session(): void {
    kgv_configure_session();
    if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
        throw new RuntimeException('Unable to start PHP session.');
    }
}
