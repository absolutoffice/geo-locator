<?php

declare(strict_types=1);

namespace GeoLocator\Result;

/**
 * Immutable normalized location fields supplied by a geolocation provider.
 *
 * Each field is independently optional. Null means that the provider did not
 * supply a usable value; this container does not validate ranges or infer missing
 * fields. Coordinates represent an approximate IP location, not a precise address.
 */
final class GeoLocation
{
    /**
     * Store the provider-normalized location without further validation.
     *
     * @param string|null $countryCode Country code, usually the provider's ISO 3166-1 alpha-2 code.
     * @param string|null $regionCode Most specific subdivision code supplied by the provider.
     * @param string|null $regionName Localized name of the selected subdivision.
     * @param string|null $city Localized city name.
     * @param string|null $postalCode Postal code as text, preserving letters and leading zeroes.
     * @param float|null $latitude Latitude in decimal degrees.
     * @param float|null $longitude Longitude in decimal degrees.
     * @param int|null $accuracyRadiusKm Provider-supplied location accuracy radius in kilometres.
     * @param string|null $timezone Provider-supplied time-zone identifier, usually an IANA name.
     */
    public function __construct(
        /**
         * Provider country code, or null if unavailable.
         */
        public readonly ?string $countryCode = null,
        /**
         * Code of the most specific available subdivision, or null.
         */
        public readonly ?string $regionCode = null,
        /**
         * Localized subdivision name selected by the provider, or null.
         */
        public readonly ?string $regionName = null,
        /**
         * Localized city name selected by the provider, or null.
         */
        public readonly ?string $city = null,
        /**
         * Postal code as supplied by the provider, or null.
         */
        public readonly ?string $postalCode = null,
        /**
         * Approximate latitude in decimal degrees, or null.
         */
        public readonly ?float $latitude = null,
        /**
         * Approximate longitude in decimal degrees, or null.
         */
        public readonly ?float $longitude = null,
        /**
         * Approximate location accuracy radius in kilometres, or null.
         */
        public readonly ?int $accuracyRadiusKm = null,
        /**
         * Time-zone identifier associated with the location, or null.
         */
        public readonly ?string $timezone = null,
    ) {
    }
}
