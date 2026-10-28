<?php

/**
 * GeoLocator runtime environment.
 *
 * Composer autoload is preferred when available. For development or a
 * standalone copy under htdocs/GeoLocator, a small PSR-4 fallback keeps the
 * library's own classes usable before `composer install` has been executed.
 * Applications installing GeoLocator as a dependency load their own Composer
 * vendor/autoload.php directly; this standalone bootstrap is not required there.
 * The fallback does not discover an application's vendor directory.
 *
 * External runtime dependencies require Composer: maxmind-db/reader for
 * public-IP lookups and absolutoffice/xcurl for MaxMind updates.
 *
 * @throws RuntimeException When PHP is older than 8.5.12 or is version 9.0 or newer.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80512 || PHP_VERSION_ID >= 90000) {
    throw new RuntimeException('GeoLocator requires PHP ^8.5.12 (at least 8.5.12 and below 9.0).');
}

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';

if (is_file($composerAutoload)) {
    require_once $composerAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'GeoLocator\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = dirname(__DIR__)
            . '/src/GeoLocator/'
            . str_replace('\\', '/', $relativeClass)
            . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    });
}
