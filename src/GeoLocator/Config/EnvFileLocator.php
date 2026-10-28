<?php

declare(strict_types=1);

namespace GeoLocator\Config;

use GeoLocator\Exception\ConfigurationException;

/**
 * Locates an explicitly chosen environment file or the legacy standalone default.
 *
 * The default assumes the package sits directly below an htdocs directory. The
 * factory does not call this locator: consumers pass their environment path there.
 */
final class EnvFileLocator
{
    /**
     * Choose the package location used by the legacy default-path convention.
     *
     * @param string $packageRoot Optional package directory; an empty string uses the installed package root.
     */
    public function __construct(
        /**
         * Package directory override for the legacy htdocs/.private layout.
         */
        private readonly string $packageRoot = '',
    ) {
    }

    /**
     * Resolve a readable environment file without loading its contents.
     *
     * @param string|null $explicitPath File to require; null checks the legacy default location.
     * @return string|null Trimmed explicit path or readable default path; null if the default is absent/unreadable.
     *
     * @throws ConfigurationException If an explicit path is blank or not a readable regular file.
     */
    public function locate(?string $explicitPath = null): ?string
    {
        if ($explicitPath !== null) {
            $path = trim($explicitPath);
            if ($path === '') {
                throw new ConfigurationException('Explicit GeoLocator .env path cannot be empty.');
            }

            if (!is_file($path) || !is_readable($path)) {
                throw new ConfigurationException(sprintf(
                    'GeoLocator environment file is not readable: %s',
                    $path,
                ));
            }

            return $path;
        }

        $path = $this->defaultPath();

        return is_file($path) && is_readable($path) ? $path : null;
    }

    /**
     * Build the legacy .private/GeoLocator/.env path two levels above the package.
     *
     * @return string Computed path, regardless of whether the file exists.
     */
    public function defaultPath(): string
    {
        $packageRoot = $this->packageRoot !== ''
            ? rtrim($this->packageRoot, DIRECTORY_SEPARATOR)
            : dirname(__DIR__, 3);

        $htdocsRoot = dirname($packageRoot);
        $projectRoot = dirname($htdocsRoot);

        return $projectRoot
            . DIRECTORY_SEPARATOR . '.private'
            . DIRECTORY_SEPARATOR . 'GeoLocator'
            . DIRECTORY_SEPARATOR . '.env';
    }
}
