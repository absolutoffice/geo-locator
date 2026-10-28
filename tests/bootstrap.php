<?php

/**
 * Load the standalone test environment and expose shared assertion helpers.
 *
 * Requires PHP ^8.5.12. Composer is preferred; the local PSR-4 fallback supports
 * dependency-free tests, while HTTP tests explicitly call requireXcurl().
 * ComposerInstallationTest exercises the generated Composer loader separately
 * so this development fallback cannot conceal an installation/autoload failure.
 *
 * @throws RuntimeException When the PHP runtime is outside the supported version range.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80512 || PHP_VERSION_ID >= 90000) {
    throw new RuntimeException('GeoLocator tests require PHP ^8.5.12 (at least 8.5.12 and below 9.0).');
}

$environmentFile = dirname(__DIR__) . '/config/environment.php';

if (is_file($environmentFile)) {
    require_once $environmentFile;
} else {
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
}

/**
 * Fail a standalone test at the first unmet expectation.
 *
 * @param bool $condition Evaluated assertion; true lets the test continue.
 * @param string $message Failure detail printed by the CLI test runner.
 * @return void
 * @throws RuntimeException When the assertion is false.
 */
function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Require the Composer HTTP client before running a test that depends on it.
 *
 * Dependency-free tests may still use the local GeoLocator autoloader.
 *
 * @return void
 * @throws RuntimeException When absolutoffice/xcurl cannot be autoloaded.
 */
function requireXcurl(): void
{
    if (!class_exists(\AbsolutOffice\XCurl\Client\HttpClient::class)) {
        throw new RuntimeException(
            'absolutoffice/xcurl is required for HTTP integration tests. Run composer install in GeoLocator.',
        );
    }
}
