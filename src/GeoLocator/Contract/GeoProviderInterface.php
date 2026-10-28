<?php

declare(strict_types=1);

namespace GeoLocator\Contract;

use GeoLocator\Ip\IpAddress;
use GeoLocator\Result\ProviderResult;

/**
 * Provider boundary that maps a parsed IP address to a location or an ordinary record miss.
 */
interface GeoProviderInterface
{
    /**
     * Look up a parsed address and distinguish missing records from provider failures.
     *
     * GeoLocator invokes this contract only for addresses it classifies as public.
     *
     * @param IpAddress $ip Validated, normalized address to look up.
     * @return ProviderResult FOUND with a location, or NOT_FOUND with the provider identifier.
     *
     * @throws \GeoLocator\Exception\ProviderException If the provider cannot complete the lookup.
     */
    public function locate(IpAddress $ip): ProviderResult;
}
