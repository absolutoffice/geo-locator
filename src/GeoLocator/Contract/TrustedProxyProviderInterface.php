<?php

declare(strict_types=1);

namespace GeoLocator\Contract;

use GeoLocator\Ip\IpAddress;

/**
 * Named source of trusted proxy addresses used before accepting forwarded request headers.
 */
interface TrustedProxyProviderInterface
{
    /**
     * Check whether an address belongs to this provider's trust set.
     *
     * @param IpAddress $ip Parsed address of a request peer or proxy-chain hop.
     * @return bool True when this provider recognizes the address as a trusted proxy.
     */
    public function isTrusted(IpAddress $ip): bool;

    /**
     * Expose the provider identifier used for diagnostics and provider-specific header handling.
     *
     * @return string Provider name used for exact comparisons by TrustedProxyResolver.
     */
    public function name(): string;
}
