<?php

declare(strict_types=1);

namespace GeoLocator\Result;

use GeoLocator\Ip\IpAddress;

/**
 * Immutable outcome of an IP lookup, including input and cache provenance.
 *
 * Use the named factories to keep status, parsed IP and optional location/provider
 * fields consistent. Invalid and non-public IPs are normal outcomes rather than
 * provider errors.
 */
final class GeoResult
{
    /**
     * Store a result assembled by one of the status-specific named factories.
     *
     * @param GeoStatus $status Lookup outcome selected by the named factory.
     * @param string $requestedIp Original input retained without trimming or normalization.
     * @param IpAddress|null $ip Parsed normalized address; null for invalid input.
     * @param GeoLocation|null $location Normalized location; present only for OK outcomes.
     * @param string|null $providerName Provider identifier; absent when no provider lookup was needed.
     * @param bool $cacheHit Whether this outcome was reconstructed from a visitor cache entry.
     */
    private function __construct(
        /**
         * Outcome distinguishing successful, invalid, non-public and missing-record lookups.
         */
        public readonly GeoStatus $status,
        /**
         * Original lookup input, preserved independently of its normalized address.
         */
        public readonly string $requestedIp,
        /**
         * Parsed normalized address, or null for an invalid input.
         */
        public readonly ?IpAddress $ip,
        /**
         * Normalized location for an OK outcome, otherwise null.
         */
        public readonly ?GeoLocation $location,
        /**
         * Provider identifier when a provider participated, otherwise null.
         */
        public readonly ?string $providerName,
        /**
         * Whether the result came from a reusable visitor cache entry.
         */
        public readonly bool $cacheHit,
    ) {
    }

    /**
     * Create a successful lookup result containing normalized location data.
     *
     * @param string $requestedIp Original lookup input.
     * @param IpAddress $ip Parsed normalized address associated with the result.
     * @param GeoLocation $location Provider-normalized location data.
     * @param string $providerName Identifier of the provider that supplied the location.
     * @param bool $cacheHit Whether the outcome was restored from the visitor cache.
     * @return self Result with status OK and the supplied location.
     */
    public static function ok(
        string $requestedIp,
        IpAddress $ip,
        GeoLocation $location,
        string $providerName,
        bool $cacheHit = false,
    ): self {
        return new self(GeoStatus::OK, $requestedIp, $ip, $location, $providerName, $cacheHit);
    }

    /**
     * Represent input that could not be parsed as an IPv4 or IPv6 address.
     *
     * @param string $requestedIp Original invalid lookup input.
     * @return self INVALID_IP result without a parsed address, location, provider or cache hit.
     */
    public static function invalidIp(string $requestedIp): self
    {
        return new self(GeoStatus::INVALID_IP, $requestedIp, null, null, null, false);
    }

    /**
     * Represent a valid address that does not qualify for public-IP lookup.
     *
     * @param string $requestedIp Original lookup input.
     * @param IpAddress $ip Parsed address classified as non-public by the calling lookup flow.
     * @param bool $cacheHit Whether this non-public outcome was restored from the cache.
     * @return self NON_PUBLIC_IP result without location or provider metadata.
     */
    public static function nonPublicIp(string $requestedIp, IpAddress $ip, bool $cacheHit = false): self
    {
        return new self(GeoStatus::NON_PUBLIC_IP, $requestedIp, $ip, null, null, $cacheHit);
    }

    /**
     * Represent a public-IP lookup for which the provider has no record.
     *
     * @param string $requestedIp Original lookup input.
     * @param IpAddress $ip Parsed address submitted to the provider.
     * @param string $providerName Identifier of the provider that reported no record.
     * @param bool $cacheHit Whether this negative result was restored from the cache.
     * @return self NOT_FOUND result retaining the provider but no location.
     */
    public static function notFound(
        string $requestedIp,
        IpAddress $ip,
        string $providerName,
        bool $cacheHit = false,
    ): self {
        return new self(GeoStatus::NOT_FOUND, $requestedIp, $ip, null, $providerName, $cacheHit);
    }

    /**
     * Check whether the lookup produced a successful location outcome.
     *
     * @return bool True exactly when the result status is GeoStatus::OK.
     */
    public function isOk(): bool
    {
        return $this->status === GeoStatus::OK;
    }
}
