<?php

declare(strict_types=1);

namespace GeoLocator\Exception;

/**
 * Base runtime exception for operational failures reported by GeoLocator.
 *
 * Catch this class when the application wants one handler for configuration,
 * provider, cache, security and update failures. Ordinary lookup misses use result
 * statuses instead of exceptions.
 */
class GeoLocatorException extends \RuntimeException
{
}
