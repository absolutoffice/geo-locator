<?php

declare(strict_types=1);

namespace GeoLocator\Config;

use GeoLocator\Exception\ConfigurationException;

/**
 * Immutable attributes for the authenticated geolocation cache cookie.
 *
 * The Secure flag may be inferred from the request and debug mode. The combination
 * SameSite=None with Secure=false is rejected later by CookieGeoCacheStore.
 */
final class CookieConfig
{
    /**
     * Validate the cookie name, SameSite value and path.
     *
     * @param string $name Nonblank cookie name used for reads, writes and deletion.
     * @param bool|null $secure Explicit Secure flag; null enables request/debug-mode inference.
     * @param bool $httpOnly Whether browsers should prevent script access to the cookie.
     * @param string $sameSite Case-sensitive SameSite attribute: Lax, Strict or None.
     * @param string $path Nonempty cookie path; defaults to the entire host.
     * @param string|null $domain Optional cookie domain; null or an empty value omits the attribute.
     *
     * @throws ConfigurationException If the name is blank, SameSite unsupported or path empty.
     */
    public function __construct(
        /**
         * Name of the visitor cache cookie.
         */
        public readonly string $name = 'geolocator',
        /**
         * Explicit Secure attribute, or null to derive it from request and debug mode.
         */
        public readonly ?bool $secure = null,
        /**
         * Whether browser scripts should be denied access to the cookie.
         */
        public readonly bool $httpOnly = true,
        /**
         * Validated SameSite policy, preserving its required case.
         */
        public readonly string $sameSite = 'Lax',
        /**
         * Nonempty URL path to which the cookie applies.
         */
        public readonly string $path = '/',
        /**
         * Optional domain attribute; null and empty values are omitted by the cookie store.
         */
        public readonly ?string $domain = null,
    ) {
        if (trim($name) === '') {
            throw new ConfigurationException('Cookie name cannot be empty.');
        }

        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new ConfigurationException('Cookie SameSite must be Lax, Strict or None.');
        }

        if ($path === '') {
            throw new ConfigurationException('Cookie path cannot be empty.');
        }
    }
}
