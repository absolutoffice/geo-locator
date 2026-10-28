<?php

declare(strict_types=1);

namespace GeoLocator\Config;

use GeoLocator\Exception\ConfigurationException;

/**
 * Immutable MaxMind lookup settings and optional database download credentials.
 *
 * Construction validates configuration values, but does not open the MMDB file or
 * authenticate credentials. Database availability is checked when a lookup runs.
 */
final class MaxMindConfig
{
    /**
     * Validate the database path and ordered locale preferences.
     *
     * @param string $databasePath Nonblank path to the MMDB file; stored as supplied.
     * @param list<string> $preferredLocales Nonempty, ordered locale identifiers used for localized names.
     * @param string|null $accountId Optional MaxMind account identifier; required by the updater factory.
     * @param string|null $licenseKey Optional MaxMind license secret; required by the updater factory.
     *
     * @throws ConfigurationException If the path is blank or the locale list is empty/contains blank values.
     */
    public function __construct(
        /**
         * Path to the MMDB file used for lookups and replaced by successful updates.
         */
        public readonly string $databasePath,
        /**
         * Locale identifiers tried in order when selecting region and city names.
         *
         * @var list<string>
         */
        public readonly array $preferredLocales = ['es', 'en'],
        /**
         * Optional MaxMind download account identifier; not needed for local lookups.
         */
        public readonly ?string $accountId = null,
        /**
         * Optional MaxMind download secret; must not be exposed in diagnostics or logs.
         */
        public readonly ?string $licenseKey = null,
    ) {
        if (trim($databasePath) === '') {
            throw new ConfigurationException('MaxMind database path cannot be empty.');
        }

        if ($preferredLocales === []) {
            throw new ConfigurationException('At least one MaxMind preferred locale is required.');
        }

        foreach ($preferredLocales as $locale) {
            if (trim($locale) === '') {
                throw new ConfigurationException('MaxMind preferred locales cannot contain empty values.');
            }
        }
    }
}
