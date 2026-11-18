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
 * @throws RuntimeException When PHP is outside the xCurl 1.1.1 supported ranges (8.4.27+ or 8.5.12+, below 8.6).
 */

declare(strict_types=1);

$phpVersionSupported = (PHP_VERSION_ID >= 80427 && PHP_VERSION_ID < 80500)
    || (PHP_VERSION_ID >= 80512 && PHP_VERSION_ID < 80600);

if (!$phpVersionSupported) {
    throw new RuntimeException('GeoLocator 1.0.1 requires PHP >=8.4.27 <8.5 or >=8.5.12 <8.6 (xCurl 1.1.1).');
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
