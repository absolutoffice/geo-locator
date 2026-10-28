<?php

declare(strict_types=1);

namespace GeoLocator\Proxy;

use GeoLocator\Contract\TrustedProxyProviderInterface;
use GeoLocator\Ip\IpAddress;

/**
 * Recognizes Cloudflare peers using the bundled origin-facing network snapshot.
 *
 * The list is static and requires an explicit package update when maintained ranges
 * change; no network request is made while resolving a visitor.
 */
final class CloudflareProxyProvider implements TrustedProxyProviderInterface
{
    /**
     * Snapshot of Cloudflare origin-facing IP ranges from https://www.cloudflare.com/ips/.
     * Verified against Cloudflare's definitive list on 2026-09-16; upstream list last changed 2023-09-28.
     * Keep this list isolated here so it can be updated without changing resolver logic.
     *
     * @var list<string>
     */
    private const CIDRS = [
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '108.162.192.0/18',
        '131.0.72.0/22',
        '141.101.64.0/18',
        '162.158.0.0/15',
        '172.64.0.0/13',
        '173.245.48.0/20',
        '188.114.96.0/20',
        '190.93.240.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * Tests whether a peer belongs to one of the bundled Cloudflare networks.
     *
     * @param IpAddress $ip Parsed immediate-peer or forwarded-hop address.
     * @return bool True when at least one configured IPv4 or IPv6 range contains the address.
     */
    public function isTrusted(IpAddress $ip): bool
    {
        foreach (self::CIDRS as $cidr) {
            if (CidrMatcher::contains($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the provider identifier used for Cloudflare-specific header handling.
     *
     * @return string The stable identifier cloudflare.
     */
    public function name(): string
    {
        return TrustedProxyProvider::CLOUDFLARE->value;
    }
}
