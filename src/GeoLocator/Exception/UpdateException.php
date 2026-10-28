<?php

declare(strict_types=1);

namespace GeoLocator\Exception;

/**
 * Reports an operational failure during MaxMind metadata checks, downloads or installation.
 *
 * Previous exceptions may preserve transport or filesystem diagnostics. Callers
 * should filter nested diagnostic details before logging or exposing them.
 */
class UpdateException extends GeoLocatorException
{
}
