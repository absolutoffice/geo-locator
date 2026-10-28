<?php

declare(strict_types=1);

namespace GeoLocator\Http;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use GeoLocator\Cache\GeoCacheEntry;
use GeoLocator\Cache\GeoCachePolicy;
use GeoLocator\Cache\IpTokenGenerator;
use GeoLocator\Config\CacheConfig;
use GeoLocator\Contract\GeoCacheStoreInterface;
use GeoLocator\GeoLocator;
use GeoLocator\Ip\IpAddress;
use GeoLocator\Result\GeoResult;
use GeoLocator\Result\GeoStatus;
use LogicException;

/**
 * Coordinates client-IP resolution, cache validation and provider lookup.
 *
 * Successful, not-found and non-public outcomes can be cached for the configured
 * lifetime. Cache and provider failures are propagated to the caller.
 */
final class VisitorGeoLocator
{
    /**
     * Assembles the request-level geolocation workflow.
     *
     * @param GeoLocator $geoLocator Core IP classification and provider lookup service.
     * @param ClientIpResolver $clientIpResolver Resolver applying the configured proxy trust policy.
     * @param GeoCacheStoreInterface $cacheStore Store used to read, replace or delete visitor cache entries.
     * @param GeoCachePolicy $cachePolicy Validator for format, expiry and current-IP binding.
     * @param IpTokenGenerator $ipTokenGenerator Generator used to bind new entries to the current IP.
     * @param CacheConfig $cacheConfig Lifetime settings for newly cached results.
     */
    public function __construct(
        /**
         * Core locator called when the visitor cache cannot be reused.
         */
        private readonly GeoLocator $geoLocator,
        /**
         * Resolves the client address using immediate-peer trust rules.
         */
        private readonly ClientIpResolver $clientIpResolver,
        /**
         * Storage backend responsible for cache persistence and deletion.
         */
        private readonly GeoCacheStoreInterface $cacheStore,
        /**
         * Checks cache version, lifetime and binding to the current address.
         */
        private readonly GeoCachePolicy $cachePolicy,
        /**
         * Creates the current visitor's keyed IP-binding token.
         */
        private readonly IpTokenGenerator $ipTokenGenerator,
        /**
         * Lifetime applied when issuing a fresh cache entry.
         */
        private readonly CacheConfig $cacheConfig,
    ) {
    }

    /**
     * Resolves and geolocates a visitor, reusing only a valid IP-bound cache entry.
     *
     * An unresolved peer deletes the cache and returns INVALID_IP. An expired or
     * mismatched entry is deleted before lookup. Fresh cacheable outcomes are written
     * with a new issuance time and the configured lifetime in days.
     *
     * @param HttpContext $context Request peer, transport and forwarding headers.
     * @return GeoResult Lookup result, with cacheHit set when a valid entry was reused.
     * @throws \GeoLocator\Exception\CacheException If the selected cache store cannot read, write or delete an entry.
     * @throws \GeoLocator\Exception\SecurityException If cookie encoding cannot verify its generated value.
     * @throws \GeoLocator\Exception\ProviderException If the lookup provider fails or returns inconsistent data.
     * @throws LogicException If a cached outcome violates the invariants required to reconstruct a result.
     * @throws \Throwable If an injected provider or cache store fails; its exception is propagated unchanged.
     */
    public function locate(HttpContext $context): GeoResult
    {
        $ip = $this->clientIpResolver->resolve($context);
        if ($ip === null) {
            $this->cacheStore->delete();
            return GeoResult::invalidIp($context->remoteAddress ?? '');
        }

        $cached = $this->cacheStore->read();
        if ($cached !== null) {
            if ($this->cachePolicy->isValid($cached, $ip)) {
                return $this->resultFromCache($cached, $ip);
            }
            $this->cacheStore->delete();
        }

        $result = $this->geoLocator->locate($ip->value());
        if ($result->status !== GeoStatus::INVALID_IP) {
            $this->cacheStore->write($this->entryFromResult($result, $ip));
        }

        return $result;
    }

    /**
     * Runs the visitor workflow using a context created from $_SERVER.
     *
     * @return GeoResult Resolved geolocation outcome for the current request.
     * @throws \GeoLocator\Exception\CacheException If a cache operation fails.
     * @throws \GeoLocator\Exception\SecurityException If cookie encoding cannot verify its generated value.
     * @throws \GeoLocator\Exception\ProviderException If lookup fails or the provider result is inconsistent.
     * @throws LogicException If a cached outcome cannot be reconstructed consistently.
     * @throws \Throwable If an injected provider or cache store fails; its exception is propagated unchanged.
     */
    public function locateFromGlobals(): GeoResult
    {
        return $this->locate(HttpContext::fromGlobals());
    }

    /**
     * Creates an IP-bound cache entry with a new UTC issuance time.
     *
     * @param GeoResult $result Cacheable lookup outcome to persist.
     * @param IpAddress $ip Resolved address to bind to the entry.
     * @return GeoCacheEntry Entry expiring after the configured number of UTC calendar days.
     * @throws \InvalidArgumentException If the result cannot satisfy cache-entry invariants.
     */
    private function entryFromResult(GeoResult $result, IpAddress $ip): GeoCacheEntry
    {
        $issuedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $issuedAt->add(new DateInterval(sprintf('P%dD', $this->cacheConfig->ttlDays)));

        return new GeoCacheEntry(
            formatVersion: GeoCacheEntry::CURRENT_FORMAT_VERSION,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            ipToken: $this->ipTokenGenerator->generate($ip),
            status: $result->status,
            location: $result->location,
            providerName: $result->providerName,
        );
    }

    /**
     * Reconstructs a typed lookup result and marks it as originating from cache.
     *
     * @param GeoCacheEntry $entry Entry already accepted by the cache policy.
     * @param IpAddress $ip Current address whose token matched the entry.
     * @return GeoResult Cached outcome with the current normalized address.
     * @throws LogicException If a required cached provider/location is missing or the status is INVALID_IP.
     */
    private function resultFromCache(GeoCacheEntry $entry, IpAddress $ip): GeoResult
    {
        return match ($entry->status) {
            GeoStatus::OK => GeoResult::ok(
                $ip->value(),
                $ip,
                $entry->location ?? throw new LogicException('Cached OK result has no location.'),
                $entry->providerName ?? throw new LogicException('Cached OK result has no provider.'),
                true,
            ),
            GeoStatus::NON_PUBLIC_IP => GeoResult::nonPublicIp($ip->value(), $ip, true),
            GeoStatus::NOT_FOUND => GeoResult::notFound(
                $ip->value(),
                $ip,
                $entry->providerName ?? throw new LogicException('Cached NOT_FOUND result has no provider.'),
                true,
            ),
            GeoStatus::INVALID_IP => throw new LogicException('INVALID_IP cannot exist in visitor cache.'),
        };
    }
}
