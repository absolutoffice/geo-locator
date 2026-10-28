<?php

/**
 * Run one local lookup using either a Composer installation or a standalone Release ZIP.
 *
 * Usage: php examples/lookup.php /absolute/vendor/autoload.php /absolute/private.env [IP]
 * The default loopback address exercises installation without requiring an MMDB.
 * A public address requires an existing configured MMDB. This example never
 * downloads a database, performs HTTP requests or creates a visitor cookie.
 *
 * Exit status is zero for a completed lookup outcome, 64 for invalid arguments,
 * and one for configuration or provider failure. Only the public exception
 * message is printed; previous exceptions and secrets are not dumped.
 */

declare(strict_types=1);

use GeoLocator\GeoLocatorFactory;

use function GeoLocator\Examples\exampleConfiguration;

require __DIR__ . '/bootstrap.php';

if ($argc < 3 || $argc > 4) {
    fwrite(STDERR, 'Usage: php lookup.php /absolute/vendor/autoload.php /absolute/private.env [IP]' . PHP_EOL);
    exit(64);
}

try {
    $config = exampleConfiguration($argv[1], $argv[2]);
    $result = GeoLocatorFactory::create($config)->locate($argv[3] ?? '127.0.0.1');

    echo json_encode([
        'status' => $result->status->value,
        'requestedIp' => $result->requestedIp,
        'ip' => $result->ip?->value(),
        'provider' => $result->providerName,
        'location' => $result->location,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
