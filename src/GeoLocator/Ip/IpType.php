<?php

declare(strict_types=1);

namespace GeoLocator\Ip;

/**
 * String-backed address categories used to skip geolocation for non-public inputs.
 */
enum IpType: string
{
    /**
     * Address not matched by explicit local categories or PHP private/reserved range filters.
     */
    case PUBLIC = 'public';
    /**
     * RFC 1918 IPv4 range or IPv6 unique-local fc00::/7 range.
     */
    case PRIVATE = 'private';
    /**
     * IPv4 127.0.0.0/8 range or the IPv6 ::1 loopback address.
     */
    case LOOPBACK = 'loopback';
    /**
     * IPv4 169.254.0.0/16 or IPv6 fe80::/10 local-link range.
     */
    case LINK_LOCAL = 'link_local';
    /**
     * Address excluded by PHP private/reserved filters after the explicit categories are checked.
     */
    case RESERVED = 'reserved';
    /**
     * IPv4 0.0.0.0 or IPv6 :: unspecified address.
     */
    case UNSPECIFIED = 'unspecified';
}
