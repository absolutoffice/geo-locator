<?php

declare(strict_types=1);

namespace GeoLocator\Proxy;

use GeoLocator\Contract\TrustedProxyProviderInterface;
use GeoLocator\Exception\ConfigurationException;
use GeoLocator\Ip\IpAddress;

/**
 * Recognizes trusted proxies from explicitly supplied IP literals and CIDRs.
 */
final class CustomProxyProvider implements TrustedProxyProviderInterface
{
    /**
     * Validated network strings, retained in caller-supplied order.
     *
     * @var list<string>
     */
    private readonly array $networks;

    /**
     * Validates and retains the application's trusted proxy networks.
     *
     * @param list<string> $networks IPv4/IPv6 literals or CIDR networks; an empty list trusts no address.
     * @throws ConfigurationException If any entry is not a valid address or network.
     */
    public function __construct(array $networks)
    {
        foreach ($networks as $network) {
            if (!CidrMatcher::isValidNetwork($network)) {
                throw new ConfigurationException(sprintf('Invalid trusted proxy IP/CIDR: %s', $network));
            }
        }

        $this->networks = $networks;
    }

    /**
     * Checks whether the address matches any configured literal or CIDR.
     *
     * @param IpAddress $ip Parsed immediate-peer or forwarded-hop address.
     * @return bool True when one of this provider's configured networks contains the address.
     */
    public function isTrusted(IpAddress $ip): bool
    {
        foreach ($this->networks as $network) {
            if (CidrMatcher::contains($ip, $network)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the identifier for application-supplied trusted proxy networks.
     *
     * @return string The stable identifier custom.
     */
    public function name(): string
    {
        return 'custom';
    }
}
