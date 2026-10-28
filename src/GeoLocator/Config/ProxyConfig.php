<?php

declare(strict_types=1);

namespace GeoLocator\Config;

use GeoLocator\Proxy\TrustedProxyProvider;

/**
 * Immutable selection of built-in and custom trusted proxy sources.
 *
 * Custom networks are retained as supplied and validated when a CustomProxyProvider
 * is created. Custom entries supplement the selected built-in provider.
 */
final class ProxyConfig
{
    /**
     * Record trust sources without inspecting request headers or validating networks.
     *
     * @param TrustedProxyProvider $provider Built-in provider selection; NONE trusts no built-in network.
     * @param list<string> $customTrustedProxies Additional trusted IPv4/IPv6 addresses or CIDR networks.
     */
    public function __construct(
        /**
         * Selected built-in proxy provider; independent of any additional custom networks.
         */
        public readonly TrustedProxyProvider $provider = TrustedProxyProvider::NONE,
        /**
         * Additional trusted IP addresses or CIDR ranges, validated during provider creation.
         *
         * @var list<string>
         */
        public readonly array $customTrustedProxies = [],
    ) {
    }
}
