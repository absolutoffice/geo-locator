<?php

declare(strict_types=1);

namespace GeoLocator\Result;

use LogicException;

/**
 * Immutable provider-level result with enforced status/location consistency.
 *
 * Provider failures are exceptions; a valid lookup without a record is represented
 * by NOT_FOUND. A provider identifier is required in either case.
 */
final class ProviderResult
{
    /**
     * Validate the invariant shared by the named provider-result factories.
     *
     * @param ProviderStatus $status Whether the provider found a record.
     * @param GeoLocation|null $location Location for FOUND, or null for NOT_FOUND.
     * @param string $providerName Nonempty provider identifier, trimmed by the named factories.
     *
     * @throws LogicException If the provider name is empty or the status and location disagree.
     */
    private function __construct(
        /**
         * Whether a provider returned a record or reported its absence.
         */
        public readonly ProviderStatus $status,
        /**
         * Normalized location for FOUND, and null for NOT_FOUND.
         */
        public readonly ?GeoLocation $location,
        /**
         * Nonempty, trimmed identifier of the provider that produced this result.
         */
        public readonly string $providerName,
    ) {
        if ($providerName === '') {
            throw new LogicException('Provider name cannot be empty.');
        }

        if ($status === ProviderStatus::FOUND && $location === null) {
            throw new LogicException('FOUND provider result requires a location.');
        }

        if ($status === ProviderStatus::NOT_FOUND && $location !== null) {
            throw new LogicException('NOT_FOUND provider result cannot contain a location.');
        }
    }

    /**
     * Create a successful provider result and normalize its provider identifier.
     *
     * @param GeoLocation $location Normalized location fields from the provider.
     * @param string $providerName Nonblank provider identifier; surrounding whitespace is removed.
     * @return self FOUND result containing the supplied location.
     *
     * @throws LogicException If the provider identifier is blank after trimming.
     */
    public static function found(GeoLocation $location, string $providerName): self
    {
        return new self(ProviderStatus::FOUND, $location, trim($providerName));
    }

    /**
     * Create a negative provider result without location data.
     *
     * @param string $providerName Nonblank provider identifier; surrounding whitespace is removed.
     * @return self NOT_FOUND result retaining the normalized provider identifier.
     *
     * @throws LogicException If the provider identifier is blank after trimming.
     */
    public static function notFound(string $providerName): self
    {
        return new self(ProviderStatus::NOT_FOUND, null, trim($providerName));
    }
}
