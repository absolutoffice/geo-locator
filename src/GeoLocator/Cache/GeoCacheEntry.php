<?php

declare(strict_types=1);

namespace GeoLocator\Cache;

use DateTimeImmutable;
use GeoLocator\Result\GeoLocation;
use GeoLocator\Result\GeoStatus;
use InvalidArgumentException;

/**
 * Immutable cache record with validated status, location and lifetime invariants.
 *
 * Construction validates the record shape, but does not check whether its lifetime
 * includes the current time or whether its token matches the current visitor.
 */
final class GeoCacheEntry
{
    /**
     * Serialization version accepted by the current codec and cache policy.
     */
    public const CURRENT_FORMAT_VERSION = 1;

    /**
     * Creates a cache entry whose data is consistent with its result status.
     *
     * @param int $formatVersion Positive serialization version; current-version checks belong to the codec and policy.
     * @param DateTimeImmutable $issuedAt Creation time of the cached result.
     * @param DateTimeImmutable $expiresAt Expiration time, strictly later than issuance.
     * @param string $ipToken Nonempty keyed binding to the client IP address.
     * @param GeoStatus $status Cached outcome; INVALID_IP is not cacheable.
     * @param GeoLocation|null $location Required for OK and forbidden for every other status.
     * @param string|null $providerName Required for OK and NOT_FOUND; null for NON_PUBLIC_IP.
     * @throws InvalidArgumentException If the version, lifetime, token or status-dependent fields are inconsistent.
     */
    public function __construct(
        /**
         * Serialization format version carried by this entry.
         */
        public readonly int $formatVersion,
        /**
         * Time at which the cached lookup result was issued.
         */
        public readonly DateTimeImmutable $issuedAt,
        /**
         * Exclusive expiration time; an entry is expired at this instant.
         */
        public readonly DateTimeImmutable $expiresAt,
        /**
         * Keyed token binding this result to the normalized visitor IP.
         */
        public readonly string $ipToken,
        /**
         * Cacheable geolocation outcome represented by the entry.
         */
        public readonly GeoStatus $status,
        /**
         * Location details, present only for a successful lookup.
         */
        public readonly ?GeoLocation $location,
        /**
         * Provider identifier for OK or NOT_FOUND; null for non-public addresses.
         */
        public readonly ?string $providerName,
    ) {
        if ($formatVersion < 1) {
            throw new InvalidArgumentException('Cache format version must be at least 1.');
        }

        if ($ipToken === '') {
            throw new InvalidArgumentException('IP token cannot be empty.');
        }

        if ($expiresAt <= $issuedAt) {
            throw new InvalidArgumentException('Cache expiration must be later than issue time.');
        }

        if ($status === GeoStatus::INVALID_IP) {
            throw new InvalidArgumentException('INVALID_IP results cannot be stored in visitor cache.');
        }

        if ($status === GeoStatus::OK) {
            if ($location === null) {
                throw new InvalidArgumentException('OK cache entries require a location.');
            }

            if ($providerName === null || trim($providerName) === '') {
                throw new InvalidArgumentException('OK cache entries require a provider name.');
            }

            return;
        }

        if ($location !== null) {
            throw new InvalidArgumentException('Only OK cache entries may contain a location.');
        }

        if ($status === GeoStatus::NOT_FOUND) {
            if ($providerName === null || trim($providerName) === '') {
                throw new InvalidArgumentException('NOT_FOUND cache entries require a provider name.');
            }

            return;
        }

        if ($providerName !== null) {
            throw new InvalidArgumentException('NON_PUBLIC_IP cache entries cannot contain a provider name.');
        }
    }
}
