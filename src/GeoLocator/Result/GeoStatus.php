<?php

declare(strict_types=1);

namespace GeoLocator\Result;

/**
 * Stable string-backed outcomes exposed by the public geolocation API and cache payload.
 */
enum GeoStatus: string
{
    /**
     * A provider supplied a normalized location.
     */
    case OK = 'ok';
    /**
     * The requested text could not be parsed as an IP address.
     */
    case INVALID_IP = 'invalid_ip';
    /**
     * The address is valid but classified outside the public-IP category.
     */
    case NON_PUBLIC_IP = 'non_public_ip';
    /**
     * The provider has no location record for the requested address.
     */
    case NOT_FOUND = 'not_found';
}
