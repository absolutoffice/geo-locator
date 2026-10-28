<?php

declare(strict_types=1);

namespace GeoLocator\Cache;

use DateTimeImmutable;
use DateTimeZone;
use GeoLocator\Exception\CacheException;
use GeoLocator\Exception\SecurityException;
use GeoLocator\Result\GeoLocation;
use GeoLocator\Result\GeoStatus;
use Exception;
use JsonException;

/**
 * Serializes cache records as authenticated, readable Base64url JSON payloads.
 *
 * The value consists of a payload and authentication tag separated by a period.
 * Decoding verifies integrity and record structure; current-time expiry and IP
 * matching are separate checks performed by GeoCachePolicy.
 */
final class GeoCacheCodec
{
    /**
     * Selects the authenticator used for both encoding and decoding.
     *
     * @param CookieAuthenticator $authenticator Authenticator configured with the cache signing key.
     */
    public function __construct(
        /**
         * Authenticates the encoded payload before any JSON field is trusted.
         */
        private readonly CookieAuthenticator $authenticator,
    ) {
    }

    /**
     * Serializes, authenticates and verifies a current-format cache entry.
     *
     * The completed value is decoded again and compared with the original entry before
     * it is returned. Location data remains readable by anyone who holds the value.
     *
     * @param GeoCacheEntry $entry Entry to serialize, using CURRENT_FORMAT_VERSION.
     * @return string Cookie-safe Base64url payload and tag separated by a period.
     * @throws CacheException If the version is unsupported or JSON serialization fails.
     * @throws SecurityException If the generated value cannot be decoded back to the same entry.
     */
    public function encode(GeoCacheEntry $entry): string
    {
        if ($entry->formatVersion !== GeoCacheEntry::CURRENT_FORMAT_VERSION) {
            throw new CacheException(sprintf(
                'Unsupported GeoLocator cache format version for encoding: %d.',
                $entry->formatVersion,
            ));
        }

        try {
            $json = json_encode(
                $this->toArray($entry),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $e) {
            throw new CacheException('Unable to serialize GeoLocator cache entry.', 0, $e);
        }

        $payload = self::base64UrlEncode($json);
        $tag = $this->authenticator->authenticate($payload);
        $encoded = $payload . '.' . $tag;

        $roundTrip = $this->decode($encoded);
        if ($roundTrip === null || !$this->sameEntry($entry, $roundTrip)) {
            throw new SecurityException('GeoLocator generated a cache value that it could not verify.');
        }

        return $encoded;
    }

    /**
     * Verifies and decodes an authenticated cache value.
     *
     * Malformed encodings, invalid tags, unsupported versions and inconsistent entry
     * fields return null. A successfully decoded entry may still be expired or belong
     * to another IP and must be checked with GeoCachePolicy before use.
     *
     * @param string $encoded Complete payload-and-tag value from the cache store.
     * @return GeoCacheEntry|null Parsed authenticated entry, or null when validation fails.
     */
    public function decode(string $encoded): ?GeoCacheEntry
    {
        $parts = explode('.', $encoded);
        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $tag] = $parts;
        if ($payload === '' || !$this->authenticator->verify($payload, $tag)) {
            return null;
        }

        $json = self::base64UrlDecode($payload);
        if ($json === null) {
            return null;
        }

        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        try {
            return $this->fromArray($data);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Builds the JSON-ready representation of a cache entry.
     *
     * Timestamps are stored as Unix seconds and nullable location fields are preserved.
     *
     * @param GeoCacheEntry $entry Entry whose public values will be serialized.
     * @return array<string, mixed> Versioned payload containing timestamps, token, outcome and optional location.
     */
    private function toArray(GeoCacheEntry $entry): array
    {
        return [
            'v' => $entry->formatVersion,
            'iat' => $entry->issuedAt->getTimestamp(),
            'exp' => $entry->expiresAt->getTimestamp(),
            'ip' => $entry->ipToken,
            'status' => $entry->status->value,
            'provider' => $entry->providerName,
            'geo' => $entry->location === null ? null : [
                'countryCode' => $entry->location->countryCode,
                'regionCode' => $entry->location->regionCode,
                'regionName' => $entry->location->regionName,
                'city' => $entry->location->city,
                'postalCode' => $entry->location->postalCode,
                'latitude' => $entry->location->latitude,
                'longitude' => $entry->location->longitude,
                'accuracyRadiusKm' => $entry->location->accuracyRadiusKm,
                'timezone' => $entry->location->timezone,
            ],
        ];
    }

    /**
     * Validates decoded top-level fields and constructs the cache record.
     *
     * This method accepts only the current format version. Entry invariants are also
     * checked by GeoCacheEntry; decode() turns those construction failures into null.
     *
     * @param array<array-key, mixed> $data JSON-decoded payload to inspect.
     * @return GeoCacheEntry|null Current-format entry, or null for unsupported or malformed fields.
     * @throws \InvalidArgumentException If status-dependent fields or lifetime violate entry invariants.
     */
    private function fromArray(array $data): ?GeoCacheEntry
    {
        $version = $this->intValue($data, 'v');
        $issuedAt = $this->intValue($data, 'iat');
        $expiresAt = $this->intValue($data, 'exp');
        $ipToken = $this->stringValue($data, 'ip');
        $statusValue = $this->stringValue($data, 'status');

        if (
            $version !== GeoCacheEntry::CURRENT_FORMAT_VERSION
            || $issuedAt === null
            || $expiresAt === null
            || $ipToken === null
            || $statusValue === null
        ) {
            return null;
        }

        $status = GeoStatus::tryFrom($statusValue);
        if ($status === null) {
            return null;
        }

        $providerName = $data['provider'] ?? null;
        if ($providerName !== null && !is_string($providerName)) {
            return null;
        }

        $location = $this->locationFromValue($data['geo'] ?? null);
        if (($data['geo'] ?? null) !== null && $location === null) {
            return null;
        }

        return new GeoCacheEntry(
            formatVersion: $version,
            issuedAt: $this->timeFromUnix($issuedAt),
            expiresAt: $this->timeFromUnix($expiresAt),
            ipToken: $ipToken,
            status: $status,
            location: $location,
            providerName: $providerName,
        );
    }

    /**
     * Builds location data from an array, normalizing unsupported field types to null.
     *
     * @param mixed $value Decoded location field, normally an array or null.
     * @return GeoLocation|null Location for an array value; null for missing or non-array values.
     */
    private function locationFromValue(mixed $value): ?GeoLocation
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            return null;
        }

        return new GeoLocation(
            countryCode: $this->nullableString($value, 'countryCode'),
            regionCode: $this->nullableString($value, 'regionCode'),
            regionName: $this->nullableString($value, 'regionName'),
            city: $this->nullableString($value, 'city'),
            postalCode: $this->nullableString($value, 'postalCode'),
            latitude: $this->nullableFloat($value, 'latitude'),
            longitude: $this->nullableFloat($value, 'longitude'),
            accuracyRadiusKm: $this->nullableInt($value, 'accuracyRadiusKm'),
            timezone: $this->nullableString($value, 'timezone'),
        );
    }

    /**
     * Reads an integer field without accepting numeric strings or floats.
     *
     * @param array<array-key, mixed> $data Decoded payload containing the field.
     * @param string $key Field name to inspect.
     * @return int|null Integer value, or null when absent or of another type.
     */
    private function intValue(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * Reads a nonempty string field without trimming its contents.
     *
     * @param array<array-key, mixed> $data Decoded payload containing the field.
     * @param string $key Field name to inspect.
     * @return string|null String value, or null when absent, empty or not a string.
     */
    private function stringValue(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Reads an optional string, retaining an explicitly empty string.
     *
     * @param array<array-key, mixed> $data Decoded location fields.
     * @param string $key Field name to inspect.
     * @return string|null String value, or null for absent, null or incorrectly typed values.
     */
    private function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return $value === null || is_string($value) ? $value : null;
    }

    /**
     * Reads an optional floating-point field, accepting integer JSON numbers.
     *
     * @param array<array-key, mixed> $data Decoded location fields.
     * @param string $key Field name to inspect.
     * @return float|null Numeric value converted to float, or null for other values.
     */
    private function nullableFloat(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Reads an optional integer field without coercion.
     *
     * @param array<array-key, mixed> $data Decoded location fields.
     * @param string $key Field name to inspect.
     * @return int|null Integer value, or null for absent, null or incorrectly typed values.
     */
    private function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return $value === null || is_int($value) ? $value : null;
    }

    /**
     * Converts Unix seconds to an immutable UTC timestamp.
     *
     * @param int $timestamp Seconds since the Unix epoch; negative values represent earlier instants.
     * @return DateTimeImmutable Timestamp with UTC as its displayed timezone.
     */
    private function timeFromUnix(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Checks that a serialization round trip preserved the entry's stored values.
     *
     * Times are compared at Unix-second precision, and location objects by their values.
     *
     * @param GeoCacheEntry $left Original entry before encoding.
     * @param GeoCacheEntry $right Entry reconstructed from the encoded value.
     * @return bool Whether the version, times, token, status, provider and location agree.
     */
    private function sameEntry(GeoCacheEntry $left, GeoCacheEntry $right): bool
    {
        return $left->formatVersion === $right->formatVersion
            && $left->issuedAt->getTimestamp() === $right->issuedAt->getTimestamp()
            && $left->expiresAt->getTimestamp() === $right->expiresAt->getTimestamp()
            && hash_equals($left->ipToken, $right->ipToken)
            && $left->status === $right->status
            && $left->providerName === $right->providerName
            && $left->location == $right->location;
    }

    /**
     * Encodes binary data using the unpadded URL-safe Base64 alphabet.
     *
     * @param string $value Arbitrary bytes to encode.
     * @return string Encoded bytes without trailing padding characters.
     */
    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Decodes an unpadded URL-safe Base64 value after checking its alphabet.
     *
     * @param string $value Encoded bytes; empty values and padding characters are rejected.
     * @return string|null Decoded bytes, or null for invalid alphabet or failed decoding.
     */
    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(
            strtr($value, '-_', '+/') . str_repeat('=', $padding),
            true,
        );

        return $decoded === false ? null : $decoded;
    }
}
