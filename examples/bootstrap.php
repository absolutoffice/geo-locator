<?php

/**
 * Shared CLI-only setup for the Composer and standalone usage examples.
 *
 * Both installation modes supply an explicit Composer autoloader path. The
 * examples never infer an application's root or search parent directories.
 * Configuration belongs to the consuming application, and its MMDB/state paths
 * should be absolute paths outside vendor and the public document root.
 */

declare(strict_types=1);

namespace GeoLocator\Examples;

use GeoLocator\Config\GeoLocatorConfig;
use GeoLocator\GeoLocatorFactory;
use RuntimeException;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Validate an explicitly supplied, readable file path before using it in an example.
 *
 * @param string $path Absolute POSIX, drive-letter or UNC path selected by the caller.
 * @param string $label Public argument name used in validation messages.
 * @return string Canonical absolute path to the readable regular file.
 * @throws RuntimeException When the path is relative, missing or unreadable.
 */
function exampleFilePath(string $path, string $label): string
{
    $absolute = str_starts_with($path, '/')
        || str_starts_with($path, '\\\\')
        || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;

    if (!$absolute) {
        throw new RuntimeException($label . ' must be an absolute file path.');
    }

    $resolved = realpath($path);
    if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
        throw new RuntimeException($label . ' must identify a readable file.');
    }

    return $resolved;
}

/**
 * Load the chosen installation and read an application-owned environment file.
 *
 * Composer consumers pass their application's vendor/autoload.php. A standalone
 * Release ZIP uses the vendor/autoload.php bundled in its extracted directory.
 * The factory retains its normal process-environment-over-file precedence.
 *
 * @param string $autoloadPath Absolute path to the installation's Composer autoloader.
 * @param string $envFile Absolute path to the application's readable private configuration.
 * @return GeoLocatorConfig Validated configuration; no database is opened and no request is sent.
 * @throws RuntimeException When either file is unavailable or the loader does not provide GeoLocator.
 * @throws \GeoLocator\Exception\ConfigurationException When environment values are invalid or required keys are absent.
 */
function exampleConfiguration(string $autoloadPath, string $envFile): GeoLocatorConfig
{
    require_once exampleFilePath($autoloadPath, 'autoload.php');

    if (!class_exists(GeoLocatorFactory::class)) {
        throw new RuntimeException('The selected Composer autoloader does not provide GeoLocator.');
    }

    return GeoLocatorFactory::configFromEnvironment(exampleFilePath($envFile, 'Environment file'));
}
