<?php

declare(strict_types=1);

namespace GeoLocator\Provider\MaxMind;

use GeoLocator\Config\MaxMindConfig;
use GeoLocator\Contract\GeoProviderInterface;
use GeoLocator\Exception\MaxMindProviderException;
use GeoLocator\Ip\IpAddress;
use GeoLocator\Provider\MaxMind\Contract\MaxMindReaderFactoryInterface;
use GeoLocator\Provider\MaxMind\Contract\MaxMindReaderInterface;
use GeoLocator\Result\GeoLocation;
use GeoLocator\Result\ProviderResult;
use Throwable;

/**
 * Resolves addresses against a local MMDB and normalizes the supported location fields.
 *
 * The reader opens lazily and is replaced when the database filesystem fingerprint
 * changes. Geographic names follow the configured locale order, with no implicit
 * fallback to languages outside that list.
 */
final class MaxMindProvider implements GeoProviderInterface
{
    /**
     * Stable provider identifier included in successful and missing-record results.
     */
    public const PROVIDER_NAME = 'maxmind';

    /**
     * Currently open reader, or null until the first lookup or after close().
     */
    private ?MaxMindReaderInterface $reader = null;

    /**
     * Device, inode, size and timestamps observed when the current reader was opened.
     */
    private ?string $databaseFingerprint = null;

    /**
     * Factory used to open the initial reader and replacements after a database change.
     */
    private readonly MaxMindReaderFactoryInterface $readerFactory;

    /**
     * Configures lazy database access without opening the database yet.
     *
     * @param MaxMindConfig $config Database path and ordered preferred locales.
     * @param MaxMindReaderFactoryInterface|null $readerFactory Reader factory; null selects the Composer adapter.
     */
    public function __construct(
        /**
         * Database location and locale preferences used for each lookup.
         */
        private readonly MaxMindConfig $config,
        ?MaxMindReaderFactoryInterface $readerFactory = null,
    ) {
        $this->readerFactory = $readerFactory ?? new MaxMindDbReaderFactory();
    }

    /**
     * Releases a reader still held when the provider is destroyed.
     *
     * @return void No value is returned.
     * @throws \Throwable When the underlying reader fails to close; close errors are not suppressed.
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Looks up a validated address and converts the raw record into a provider result.
     *
     * A missing record yields NOT_FOUND. Missing or malformed individual location
     * fields become null; an entire non-array record is treated as a provider error.
     *
     * @param IpAddress $ip Parsed address to look up; public-address filtering belongs to GeoLocator.
     * @return ProviderResult FOUND with normalized location data, or NOT_FOUND when no record exists.
     * @throws MaxMindProviderException When opening, reading or validating the record fails.
     */
    public function locate(IpAddress $ip): ProviderResult
    {
        try {
            $record = $this->reader()->get($ip->value());
        } catch (MaxMindProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MaxMindProviderException(
                sprintf('MaxMind lookup failed for IP %s: %s', $ip->value(), $e->getMessage()),
                0,
                $e,
            );
        }

        if ($record === null) {
            return ProviderResult::notFound(self::PROVIDER_NAME);
        }

        if (!is_array($record)) {
            throw new MaxMindProviderException('MaxMind returned an unexpected non-array record.');
        }

        return ProviderResult::found(
            $this->normalizeRecord($record),
            self::PROVIDER_NAME,
        );
    }

    /**
     * Closes the active reader and clears its cached filesystem fingerprint.
     *
     * Calling this method with no active reader is harmless. A subsequent lookup can
     * open a new reader. Errors from an active reader are allowed to propagate.
     *
     * @return void No value is returned.
     * @throws \Throwable When the underlying reader fails to close.
     */
    public function close(): void
    {
        if ($this->reader === null) {
            return;
        }

        $this->reader->close();
        $this->reader = null;
        $this->databaseFingerprint = null;
    }

    /**
     * Reuses the current reader while its database fingerprint remains unchanged.
     *
     * A replacement is opened before the previous reader is closed, so a failed open
     * leaves the previous reader available for a later retry.
     *
     * @return MaxMindReaderInterface Reader corresponding to the current database fingerprint.
     * @throws MaxMindProviderException When the database cannot be inspected or a replacement cannot be opened.
     * @throws \Throwable When closing the superseded reader fails.
     */
    private function reader(): MaxMindReaderInterface
    {
        $fingerprint = $this->databaseFingerprint();

        if ($this->reader !== null && $this->databaseFingerprint === $fingerprint) {
            return $this->reader;
        }

        try {
            $newReader = $this->readerFactory->open($this->config->databasePath);
        } catch (Throwable $e) {
            throw new MaxMindProviderException(
                sprintf('Unable to open MaxMind database %s: %s', $this->config->databasePath, $e->getMessage()),
                0,
                $e,
            );
        }

        $oldReader = $this->reader;
        $this->reader = $newReader;
        $this->databaseFingerprint = $fingerprint;

        if ($oldReader !== null) {
            $oldReader->close();
        }

        return $newReader;
    }

    /**
     * Builds a lightweight change detector from the database filesystem metadata.
     *
     * The fingerprint is not a content digest and cannot detect changes that preserve
     * all of the inspected metadata values.
     *
     * @return string Device, inode, size, modification time and change time joined with colons.
     * @throws MaxMindProviderException When the database is missing, unreadable or cannot be statted.
     */
    private function databaseFingerprint(): string
    {
        $path = $this->config->databasePath;
        clearstatcache(true, $path);

        if (!is_file($path)) {
            throw new MaxMindProviderException(sprintf('MaxMind database file not found: %s', $path));
        }

        if (!is_readable($path)) {
            throw new MaxMindProviderException(sprintf('MaxMind database file is not readable: %s', $path));
        }

        $stat = stat($path);
        if ($stat === false) {
            throw new MaxMindProviderException(sprintf('Unable to stat MaxMind database file: %s', $path));
        }

        return implode(':', [
            (string) $stat['dev'],
            (string) $stat['ino'],
            (string) $stat['size'],
            (string) $stat['mtime'],
            (string) $stat['ctime'],
        ]);
    }

    /**
     * Maps supported MaxMind fields to the package location value object.
     *
     * @param array<array-key, mixed> $record Decoded database record to normalize.
     * @return GeoLocation Location with null for unavailable or incompatible field values.
     */
    private function normalizeRecord(array $record): GeoLocation
    {
        $country = $this->arrayValue($record, 'country');
        $postal = $this->arrayValue($record, 'postal');
        $city = $this->arrayValue($record, 'city');
        $location = $this->arrayValue($record, 'location');
        $subdivision = $this->mostSpecificSubdivision($record);

        return new GeoLocation(
            countryCode: $this->stringValue($country, 'iso_code'),
            regionCode: $this->stringValue($subdivision, 'iso_code'),
            regionName: $this->localizedName($subdivision),
            city: $this->localizedName($city),
            postalCode: $this->stringValue($postal, 'code'),
            latitude: $this->floatValue($location, 'latitude'),
            longitude: $this->floatValue($location, 'longitude'),
            accuracyRadiusKm: $this->intValue($location, 'accuracy_radius'),
            timezone: $this->stringValue($location, 'time_zone'),
        );
    }

    /**
     * Selects the final subdivision entry supplied by the MaxMind record.
     *
     * @param array<array-key, mixed> $record Record containing an optional subdivisions array.
     * @return array<array-key, mixed> Last subdivision, or an empty array when absent or malformed.
     */
    private function mostSpecificSubdivision(array $record): array
    {
        $subdivisions = $record['subdivisions'] ?? null;
        if (!is_array($subdivisions) || $subdivisions === []) {
            return [];
        }

        $subdivision = end($subdivisions);

        return is_array($subdivision) ? $subdivision : [];
    }

    /**
     * Reads an array-valued field without accepting scalar substitutes.
     *
     * @param array<array-key, mixed> $record Source record or nested record.
     * @param string $key Field name to inspect.
     * @return array<array-key, mixed> Field contents, or an empty array when not an array.
     */
    private function arrayValue(array $record, string $key): array
    {
        $value = $record[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * Reads and trims a non-empty string field.
     *
     * @param array<array-key, mixed> $record Source record or nested record.
     * @param string $key Field name to inspect.
     * @return string|null Trimmed string, or null for absent, non-string or blank values.
     */
    private function stringValue(array $record, string $key): ?string
    {
        $value = $record[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Finds the first non-blank name in the configured preferred locale order.
     *
     * @param array<array-key, mixed> $record Record containing an optional names map.
     * @return string|null Trimmed localized name, or null if none of the configured locales is usable.
     */
    private function localizedName(array $record): ?string
    {
        $names = $record['names'] ?? null;
        if (!is_array($names)) {
            return null;
        }

        foreach ($this->config->preferredLocales as $locale) {
            $value = $names[$locale] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Reads a numeric field without coercing strings or booleans.
     *
     * @param array<array-key, mixed> $record Source record or nested record.
     * @param string $key Field name to inspect.
     * @return float|null Float value, an integer converted to float, or null for any other input.
     */
    private function floatValue(array $record, string $key): ?float
    {
        $value = $record[$key] ?? null;

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Reads a field only when its decoded value is already an integer.
     *
     * @param array<array-key, mixed> $record Source record or nested record.
     * @param string $key Field name to inspect.
     * @return int|null Integer value, or null for absent or incompatible values.
     */
    private function intValue(array $record, string $key): ?int
    {
        $value = $record[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
