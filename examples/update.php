<?php

/**
 * Validate updater wiring and optionally run an explicitly requested MaxMind update.
 *
 * Usage: php examples/update.php /absolute/vendor/autoload.php /absolute/private.env [--run]
 * Without --run, this constructs the updater to check configuration and available
 * transport capabilities, then exits without network or filesystem writes.
 * With --run, updateIfDue() applies the configured schedule, uses MaxMind credentials,
 * and may download/install an MMDB and persist state in application-owned paths.
 *
 * Use absolute MMDB/state paths outside vendor so package upgrades do not replace
 * application data. Exit status is zero on success, 64 for invalid arguments and
 * one on failure. Previous exception chains and credentials are not printed.
 */

declare(strict_types=1);

use GeoLocator\GeoLocatorFactory;

use function GeoLocator\Examples\exampleConfiguration;

require __DIR__ . '/bootstrap.php';

if ($argc < 3 || $argc > 4 || ($argc === 4 && $argv[3] !== '--run')) {
    fwrite(STDERR, 'Usage: php update.php /absolute/vendor/autoload.php /absolute/private.env [--run]' . PHP_EOL);
    exit(64);
}

try {
    $config = exampleConfiguration($argv[1], $argv[2]);
    $updater = GeoLocatorFactory::createUpdater($config);

    if ($argc === 3) {
        echo 'Updater configuration accepted. Add --run to execute updateIfDue().' . PHP_EOL;
        exit(0);
    }

    $result = $updater->updateIfDue();
    echo json_encode([
        'status' => $result->status->value,
        'checkedAt' => $result->checkedAt->format(DATE_ATOM),
        'previousBuildAt' => $result->previousBuildAt?->format(DATE_ATOM),
        'currentBuildAt' => $result->currentBuildAt?->format(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
