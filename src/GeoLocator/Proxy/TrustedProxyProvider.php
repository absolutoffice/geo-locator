<?php

declare(strict_types=1);

namespace GeoLocator\Proxy;

/**
 * Built-in proxy-provider selections accepted by configuration.
 *
 * Custom networks are configured separately rather than represented by an enum case.
 */
enum TrustedProxyProvider: string
{
    /**
     * Selects no built-in trusted proxy provider.
     */
    case NONE = 'none';
    /**
     * Selects the bundled Cloudflare origin-facing network snapshot.
     */
    case CLOUDFLARE = 'cloudflare';
}
