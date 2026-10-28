<?php

declare(strict_types=1);

namespace GeoLocator\Result;

/**
 * String-backed distinction between a found provider record and an ordinary record miss.
 */
enum ProviderStatus: string
{
    /**
     * A location record was found and is present in the provider result.
     */
    case FOUND = 'found';
    /**
     * The lookup completed without a record; the provider result has no location.
     */
    case NOT_FOUND = 'not_found';
}
