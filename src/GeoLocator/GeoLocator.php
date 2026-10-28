<?php

declare(strict_types=1);

namespace GeoLocator;

use GeoLocator\Contract\GeoProviderInterface;
use GeoLocator\Ip\IpAddress;
use GeoLocator\Result\GeoResult;
use GeoLocator\Result\ProviderStatus;
use GeoLocator\Exception\ProviderException;

/**
 * Validates and classifies IP input before delegating public-IP lookups to a provider.
 *
 * Invalid, non-public and missing-record cases are returned as GeoResult statuses.
 * Operational provider failures propagate as exceptions. This core service has no
 * request-header, cookie or network-download behavior.
 */
final class GeoLocator
{
    /**
     * Inject the provider used after IP parsing and public-address classification.
     *
     * @param GeoProviderInterface $provider Provider responsible for finding normalized location records.
     */
    public function __construct(
        /**
         * Provider queried only for addresses classified as public by IpAddress.
         */
        private readonly GeoProviderInterface $provider,
    ) {
    }

    /**
     * Resolve one input while preserving the original text in the result.
     *
     * The provider is skipped for invalid and non-public addresses. A provider miss
     * becomes NOT_FOUND; a found record becomes OK with its normalized location.
     *
     * @param string $ip IPv4 or IPv6 input; parsing ignores surrounding whitespace.
     * @return GeoResult Lookup outcome containing the original input and available normalized fields.
     *
     * @throws ProviderException If the provider fails or reports FOUND without a location.
     */
    public function locate(string $ip): GeoResult
    {
        $address = IpAddress::tryFrom($ip);
        if ($address === null) {
            return GeoResult::invalidIp($ip);
        }

        if (!$address->isPublic()) {
            return GeoResult::nonPublicIp($ip, $address);
        }

        $providerResult = $this->provider->locate($address);

        if ($providerResult->status === ProviderStatus::NOT_FOUND) {
            return GeoResult::notFound($ip, $address, $providerResult->providerName);
        }

        $location = $providerResult->location;
        if ($location === null) {
            throw new ProviderException(sprintf(
                'Provider %s returned FOUND without location data.',
                $providerResult->providerName,
            ));
        }

        return GeoResult::ok(
            $ip,
            $address,
            $location,
            $providerResult->providerName,
        );
    }

    /**
     * Resolve inputs in iteration order and retain their keys in the result array.
     *
     * Processing stops at the first thrown exception. If an iterable repeats a key,
     * its later result replaces the earlier value under PHP array-assignment rules.
     *
     * @param iterable<array-key, string> $ips Keyed IPv4/IPv6 input strings.
     * @return array<array-key, GeoResult> Results indexed by the supplied keys.
     *
     * @throws ProviderException If any individual lookup fails or violates the provider contract.
     */
    public function locateMany(iterable $ips): array
    {
        $results = [];
        foreach ($ips as $key => $ip) {
            $results[$key] = $this->locate($ip);
        }

        return $results;
    }
}
