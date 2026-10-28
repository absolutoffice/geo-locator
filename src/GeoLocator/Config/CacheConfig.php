<?php

declare(strict_types=1);

namespace GeoLocator\Config;

use GeoLocator\Exception\ConfigurationException;

/**
 * Immutable lifetime settings for visitor geolocation cache entries.
 */
final class CacheConfig
{
    /**
     * Validate the cache lifetime in whole days.
     *
     * @param int $ttlDays Lifetime in days; must be at least one.
     *
     * @throws ConfigurationException If the lifetime is less than one day.
     */
    public function __construct(
        /**
         * Number of days added when creating a visitor cache entry.
         */
        public readonly int $ttlDays = 30,
    ) {
        if ($ttlDays < 1) {
            throw new ConfigurationException('Cache TTL must be at least 1 day.');
        }
    }
}
