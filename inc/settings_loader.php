<?php
declare(strict_types=1);
/**
 * Simple settings loader.
 * - Loads `data/settings.json` if present
 * - Allows overriding sensitive values via environment variables
 *
 * Usage: require_once __DIR__ . '/settings_loader.php';
 *        $_settings = load_settings();
 */
function load_settings(): array {
    // Load local `.env` into environment for convenience (no external dependency)
    $envFile = dirname(__DIR__) . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) continue;
            $k = trim($parts[0]);
            $v = trim($parts[1]);
            // Remove surrounding quotes
            if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                $v = substr($v, 1, -1);
            }
            putenv($k . '=' . $v);
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
    }

    $file = dirname(__DIR__) . '/data/settings.json';
    $settings = file_exists($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];

    // Environment overrides (useful for secrets). Empty string means 'not set'.
    $overrides = [
        'imap_host' => getenv('IMAP_HOST'),
        'imap_port' => getenv('IMAP_PORT'),
        'imap_user' => getenv('IMAP_USER'),
        'imap_password' => getenv('IMAP_PASSWORD'),
        'anleitung_password' => getenv('ANLEITUNG_PASSWORD'),
    ];

    foreach ($overrides as $k => $v) {
        if ($v !== false && $v !== null && $v !== '') {
            if ($k === 'imap_port') {
                $settings[$k] = (int)$v;
            } else {
                $settings[$k] = $v;
            }
        }
    }

    return $settings;
}
