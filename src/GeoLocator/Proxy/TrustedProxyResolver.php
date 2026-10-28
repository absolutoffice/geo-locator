<?php

declare(strict_types=1);

namespace GeoLocator\Proxy;

use GeoLocator\Contract\TrustedProxyProviderInterface;
use GeoLocator\Ip\IpAddress;

/**
 * Combines enabled trust providers and supports provider-specific trust checks.
 */
final class TrustedProxyResolver
{
    /**
     * Enabled trust providers evaluated in their configured order.
     *
     * @var list<TrustedProxyProviderInterface>
     */
    private readonly array $providers;

    /**
     * Retains trusted proxy providers in their configured order.
     *
     * @param list<TrustedProxyProviderInterface> $providers Providers to consult; an empty list trusts no address.
     */
    public function __construct(array $providers = [])
    {
        $this->providers = $providers;
    }

    /**
     * Checks whether any enabled provider trusts the address.
     *
     * @param IpAddress $ip Parsed immediate-peer or forwarded-hop address.
     * @return bool True as soon as one provider accepts the address; false when none do.
     */
    public function isTrusted(IpAddress $ip): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->isTrusted($ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether an enabled provider uses the exact supplied identifier.
     *
     * @param string $name Case-sensitive provider name, such as cloudflare or custom.
     * @return bool Whether at least one configured provider reports this name.
     */
    public function trustsProvider(string $name): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->name() === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks address trust only among providers with the exact supplied name.
     *
     * @param string $name Case-sensitive provider identifier to restrict the check to.
     * @param IpAddress $ip Address whose membership is being evaluated.
     * @return bool Whether a matching enabled provider trusts the address.
     */
    public function isTrustedBy(string $name, IpAddress $ip): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->name() === $name && $provider->isTrusted($ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lists enabled provider identifiers without deduplicating them.
     *
     * @return list<string> Provider names in the same order as the configured instances.
     */
    public function providerNames(): array
    {
        return array_map(
            static fn (TrustedProxyProviderInterface $provider): string => $provider->name(),
            $this->providers,
        );
    }
}
