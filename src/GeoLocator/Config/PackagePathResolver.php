<?php

declare(strict_types=1);

namespace GeoLocator\Config;

use GeoLocator\Exception\ConfigurationException;

/**
 * Resolves paths that belong to the GeoLocator package itself.
 *
 * Relative paths are anchored to the directory containing GeoLocator's
 * composer.json, regardless of where the consuming application installs the
 * package (for example ./GeoLocator or ./lib/GeoLocator).
 */
final class PackagePathResolver
{
    /**
     * Select the base directory used for package-owned relative paths.
     *
     * @param string $packageRoot Optional package root; an empty value derives it from the installed source tree.
     */
    public function __construct(
        /**
         * Optional override of the directory containing this package's composer.json.
         */
        private readonly string $packageRoot = '',
    ) {
    }

    /**
     * Return the configured package root or derive it from this class location.
     *
     * @return string Base directory with trailing slash/backslash characters removed from an explicit override.
     */
    public function packageRoot(): string
    {
        return $this->packageRoot !== ''
            ? rtrim($this->packageRoot, "/\\")
            : dirname(__DIR__, 3);
    }

    /**
     * Anchor a relative path to the package root while preserving absolute paths.
     *
     * This is lexical resolution: it neither calls realpath() nor removes .. segments,
     * checks file existence or restricts the resulting path to the package directory.
     *
     * @param string $path Nonblank absolute or relative path, with either slash convention.
     * @return string Trimmed absolute input, or the package root joined to the normalized relative path.
     *
     * @throws ConfigurationException If the path is blank after trimming.
     */
    public function resolve(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new ConfigurationException('Path cannot be empty.');
        }

        if ($this->isAbsolute($path)) {
            return $path;
        }

        $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $relative = ltrim($relative, DIRECTORY_SEPARATOR);

        return $this->packageRoot() . DIRECTORY_SEPARATOR . $relative;
    }

    /**
     * Recognize root-prefixed and drive-letter absolute paths.
     *
     * @param string $path Nonempty path already checked by resolve().
     * @return bool True for a leading slash/backslash or a drive letter followed by a separator.
     */
    private function isAbsolute(string $path): bool
    {
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
