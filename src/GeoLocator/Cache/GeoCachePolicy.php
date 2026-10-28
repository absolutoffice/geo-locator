<?php

declare(strict_types=1);

namespace GeoLocator\Cache;

use DateTimeImmutable;
use GeoLocator\Ip\IpAddress;

/**
 * Checks cache version, validity interval and binding to the current IP.
 *
 * Authentication and parsing belong to the codec used by the cache store.
 */
final class GeoCachePolicy
{
    /**
     * Selects the secret-backed verifier used to check a cached IP binding.
     *
     * @param IpTokenGenerator $ipTokenGenerator Token generator configured with the current IP-binding key.
     */
    public function __construct(
        /**
         * Verifier for the cached token's binding to the current IP address.
         */
        private readonly IpTokenGenerator $ipTokenGenerator,
    ) {
    }

    /**
     * Accepts an entry only for its supported version, current lifetime and IP.
     *
     * The issue time is inclusive and the expiration time is exclusive. Entries issued
     * in the future are rejected even if their expiration time has not been reached.
     *
     * @param GeoCacheEntry $entry Parsed cache entry to evaluate.
     * @param IpAddress $currentIp Resolved client address for this request.
     * @param DateTimeImmutable|null $now Evaluation time; null uses the current time.
     * @return bool Whether every version, lifetime and IP-binding check passes.
     */
    public function isValid(
        GeoCacheEntry $entry,
        IpAddress $currentIp,
        ?DateTimeImmutable $now = null,
    ): bool {
        $now ??= new DateTimeImmutable('now');

        if ($entry->formatVersion !== GeoCacheEntry::CURRENT_FORMAT_VERSION) {
            return false;
        }

        if ($entry->issuedAt > $now || $entry->expiresAt <= $now) {
            return false;
        }

        return $this->ipTokenGenerator->matches($currentIp, $entry->ipToken);
    }
}
