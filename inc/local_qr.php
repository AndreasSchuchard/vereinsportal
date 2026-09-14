<?php
declare(strict_types=1);

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QROutputInterface;

/**
 * Minimal project-local autoloader for the vendored QR library.
 * No network request is performed while generating QR codes.
 */
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'chillerlan\\QRCode\\' => dirname(__DIR__) . '/vendor/chillerlan/php-qrcode/src/',
        'chillerlan\\Settings\\' => dirname(__DIR__) . '/vendor/chillerlan/php-settings-container/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
        return;
    }
});

function kgv_qr_png(string $payload, int $targetSize = 600, int $eccLevel = EccLevel::M): string {
    if ($payload === '') {
        throw new InvalidArgumentException('QR payload must not be empty.');
    }

    $options = new QROptions();
    $options->outputType = QROutputInterface::GDIMAGE_PNG;
    $options->outputBase64 = false;
    $options->eccLevel = $eccLevel;
    $options->scale = max(5, min(20, (int)ceil($targetSize / 50)));
    $options->addQuietzone = true;

    $png = (new QRCode($options))->render($payload);
    if ($png === '') {
        throw new RuntimeException('QR rendering returned no data.');
    }

    return $png;
}

