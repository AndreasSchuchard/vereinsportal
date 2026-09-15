<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/settings_loader.php';

$s = load_settings();
echo "SMOKE_TEST: settings loaded\n";
echo "IMAP_HOST=" . ($s['imap_host'] ?? '') . "\n";
echo "IMAP_USER=" . ($s['imap_user'] ?? '') . "\n";
echo "IMAP_PASS_SET=" . (!empty($s['imap_password']) ? 'yes' : 'no') . "\n";
echo "ANLEITUNG_PASS_SET=" . (!empty($s['anleitung_password']) ? 'yes' : 'no') . "\n";
