<?php

declare(strict_types=1);

namespace GeoLocator\Config;

/**
 * Immutable aggregate of the security, provider, visitor and update configuration.
 */
final class GeoLocatorConfig
{
    /**
     * Assemble validated configuration objects without accessing the database or network.
     *
     * @param SecurityConfig $security Master key and derived-key configuration for the visitor cache.
     * @param MaxMindConfig $maxMind Database location, locale order and optional download credentials.
     * @param CacheConfig $cache Lifetime settings used for newly created visitor cache entries.
     * @param CookieConfig $cookie Attributes used when reading and emitting the cache cookie.
     * @param ProxyConfig $proxy Built-in and custom trust settings for forwarded client addresses.
     * @param UpdateConfig $update Scheduling, state storage and HTTP settings for database updates.
     */
    public function __construct(
        /**
         * Validated master secret used to derive independent cache authentication and IP-token keys.
         */
        public readonly SecurityConfig $security,
        /**
         * MaxMind database, preferred locales and optional update credentials.
         */
        public readonly MaxMindConfig $maxMind,
        /**
         * Visitor cache lifetime settings.
         */
        public readonly CacheConfig $cache = new CacheConfig(),
        /**
         * Cache cookie attributes and optional Secure override.
         */
        public readonly CookieConfig $cookie = new CookieConfig(),
        /**
         * Trusted proxy provider and custom IP/CIDR settings.
         */
        public readonly ProxyConfig $proxy = new ProxyConfig(),
        /**
         * Database update interval, state location and HTTP limits.
         */
        public readonly UpdateConfig $update = new UpdateConfig(),
    ) {
    }
}
