<?php

declare(strict_types=1);

use OpenTelemetry\DistroTools\Build\LaravelMessagingSemconvPatcher;

require __DIR__ . '/LaravelMessagingSemconvPatcher.php';

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php {$argv[0]} <vendor-dir>" . PHP_EOL);
    exit(2);
}

LaravelMessagingSemconvPatcher::patchVendor($argv[1]);
