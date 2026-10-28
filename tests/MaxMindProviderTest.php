<?php

/**
 * Exercise MMDB-provider normalization and reader lifetime using queued fake readers.
 *
 * Covers locale fallback, partial records, database replacement/reopening and recovery
 * from a failed replacement without contacting MaxMind.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use GeoLocator\Config\MaxMindConfig;
use GeoLocator\Exception\MaxMindProviderException;
use GeoLocator\Ip\IpAddress;
use GeoLocator\Provider\MaxMind\Contract\MaxMindReaderFactoryInterface;
use GeoLocator\Provider\MaxMind\Contract\MaxMindReaderInterface;
use GeoLocator\Provider\MaxMind\MaxMindProvider;
use GeoLocator\Result\ProviderStatus;

/**
 * Array-backed reader fixture exposing close calls for reader-reload assertions.
 */
final class FakeMaxMindReader implements MaxMindReaderInterface
{
    /** Number of close calls made by the provider. */
    public int $closeCount = 0;

    /**
     * Supply decoded records without opening an actual MMDB file.
     *
     * @param array<string, mixed> $records Raw fixture records keyed by textual IP address.
     */
    public function __construct(
        /** Decoded fixture records, including deliberately malformed values. */
        private readonly array $records,
    ) {
    }

    /**
     * Look up an exact textual address in the fixture map.
     *
     * @param string $ipAddress Address supplied by MaxMindProvider.
     * @return mixed The stored record, or null for an absent address.
     */
    public function get(string $ipAddress): mixed
    {
        return $this->records[$ipAddress] ?? null;
    }

    /**
     * Record closure without invalidating the in-memory record map.
     *
     * @return void
     */
    public function close(): void
    {
        $this->closeCount++;
    }
}


/**
 * Reader factory fixture simulating a corrupt replacement after one successful open.
 */
final class FailingSecondOpenFactory implements MaxMindReaderFactoryInterface
{
    /** Number of attempted opens, including those that fail. */
    public int $openCount = 0;

    /** First successful reader, retained so the test can inspect its close count. */
    public ?FakeMaxMindReader $firstReader = null;

    /**
     * Set the records available before the simulated replacement failure.
     *
     * @param array<string, mixed> $records Records for the first reader, keyed by address.
     */
    public function __construct(
        /** Record set used by the only successful reader. */
        private readonly array $records,
    ) {
    }

    /**
     * Open once, then simulate failure when the provider detects a replacement file.
     *
     * @param string $databasePath Requested path; the fixture does not read its contents.
     * @return MaxMindReaderInterface A fresh fake reader on the first call only.
     * @throws RuntimeException On every open after the first successful call.
     */
    public function open(string $databasePath): MaxMindReaderInterface
    {
        $this->openCount++;
        if ($this->openCount === 1) {
            $this->firstReader = new FakeMaxMindReader($this->records);

            return $this->firstReader;
        }

        throw new RuntimeException('Simulated corrupt replacement database.');
    }
}

/**
 * Reader factory fixture consuming successive record sets to model MMDB replacements.
 */
final class FakeMaxMindReaderFactory implements MaxMindReaderFactoryInterface
{
    /** Number of reader instances requested by the provider. */
    public int $openCount = 0;

    /**
     * Opened readers retained in creation order for lifecycle assertions.
     *
     * @var list<FakeMaxMindReader>
     */
    public array $readers = [];

    /**
     * Queue one decoded record map per simulated database generation.
     *
     * @param list<array<string, mixed>> $recordSets Record maps consumed in opening order.
     */
    public function __construct(
        /** Remaining maps; an exhausted queue produces empty readers. */
        private array $recordSets,
    ) {
    }

    /**
     * Create the next fixture reader and retain it for close-count assertions.
     *
     * @param string $databasePath Requested path; only its change detection is exercised by the caller.
     * @return MaxMindReaderInterface A reader for the next record set, or an empty reader after exhaustion.
     */
    public function open(string $databasePath): MaxMindReaderInterface
    {
        $this->openCount++;
        $records = array_shift($this->recordSets) ?? [];
        $reader = new FakeMaxMindReader($records);
        $this->readers[] = $reader;

        return $reader;
    }
}

$temp = tempnam(sys_get_temp_dir(), 'geolocator-mmdb-');
if ($temp === false) {
    throw new RuntimeException('Unable to create temporary test database file.');
}
file_put_contents($temp, 'db-v1');

$record = [
    'country' => ['iso_code' => 'ES'],
    'subdivisions' => [
        ['iso_code' => 'MD', 'names' => ['en' => 'Madrid']],
        ['iso_code' => 'M', 'names' => ['es' => 'Comunidad de Madrid', 'en' => 'Community of Madrid']],
    ],
    'city' => ['names' => ['es' => 'Madrid', 'en' => 'Madrid']],
    'postal' => ['code' => '28001'],
    'location' => [
        'latitude' => 40.4168,
        'longitude' => -3.7038,
        'accuracy_radius' => 20,
        'time_zone' => 'Europe/Madrid',
    ],
];

$factory = new FakeMaxMindReaderFactory([
    ['8.8.8.8' => $record],
    ['8.8.8.8' => $record],
]);

$provider = new MaxMindProvider(
    new MaxMindConfig($temp, ['es', 'en']),
    $factory,
);

$result = $provider->locate(IpAddress::from('8.8.8.8'));
expect($result->status === ProviderStatus::FOUND, 'MaxMind provider must return FOUND.');
expect($result->providerName === 'maxmind', 'MaxMind provider name mismatch.');
expect($result->location?->countryCode === 'ES', 'Country normalization failed.');
expect($result->location?->regionCode === 'M', 'Most-specific subdivision must be selected.');
expect($result->location?->regionName === 'Comunidad de Madrid', 'Preferred locale normalization failed.');
expect($result->location?->city === 'Madrid', 'City normalization failed.');
expect($result->location?->postalCode === '28001', 'Postal code normalization failed.');
expect($result->location?->latitude === 40.4168, 'Latitude normalization failed.');
expect($result->location?->longitude === -3.7038, 'Longitude normalization failed.');
expect($result->location?->accuracyRadiusKm === 20, 'Accuracy radius normalization failed.');
expect($result->location?->timezone === 'Europe/Madrid', 'Timezone normalization failed.');
expect($factory->openCount === 1, 'Reader must open lazily on first lookup.');

$notFound = $provider->locate(IpAddress::from('1.1.1.1'));
expect($notFound->status === ProviderStatus::NOT_FOUND, 'Missing MaxMind record must be NOT_FOUND.');
expect($factory->openCount === 1, 'Reader must be reused while database file is unchanged.');

// Simulate an atomic database replacement using a new inode/path entry.
$replacement = $temp . '.new';
file_put_contents($replacement, 'db-version-two-with-a-different-size');
rename($replacement, $temp);
clearstatcache(true, $temp);

$reloaded = $provider->locate(IpAddress::from('8.8.8.8'));
expect($reloaded->status === ProviderStatus::FOUND, 'Lookup after database replacement must still work.');
expect($factory->openCount === 2, 'Database replacement must reopen the Reader.');
expect($factory->readers[0]->closeCount === 1, 'Old Reader must close after successful replacement.');

$provider->close();
expect($factory->readers[1]->closeCount === 1, 'Explicit close must close active Reader.');

$failureDb = $temp . '.failure-test';
file_put_contents($failureDb, 'working-db');
$failureFactory = new FailingSecondOpenFactory(['8.8.8.8' => $record]);
$failureProvider = new MaxMindProvider(
    new MaxMindConfig($failureDb, ['es', 'en']),
    $failureFactory,
);
$failureProvider->locate(IpAddress::from('8.8.8.8'));
$replacement = $failureDb . '.new';
file_put_contents($replacement, 'replacement-that-will-fail-to-open');
rename($replacement, $failureDb);
clearstatcache(true, $failureDb);

$reloadFailed = false;
try {
    $failureProvider->locate(IpAddress::from('8.8.8.8'));
} catch (MaxMindProviderException) {
    $reloadFailed = true;
}
expect($reloadFailed, 'A failed replacement open must raise MaxMindProviderException.');
expect(
    $failureFactory->firstReader?->closeCount === 0,
    'Old Reader must remain open when replacement cannot be opened.',
);
$failureProvider->close();
expect($failureFactory->firstReader?->closeCount === 1, 'Old Reader must still be closable after failed replacement.');
@unlink($failureDb);

$missingPath = $temp . '.missing';
$missingProvider = new MaxMindProvider(
    new MaxMindConfig($missingPath),
    new FakeMaxMindReaderFactory([]),
);

$thrown = false;
try {
    $missingProvider->locate(IpAddress::from('8.8.8.8'));
} catch (MaxMindProviderException) {
    $thrown = true;
}
expect($thrown, 'Missing MMDB must raise MaxMindProviderException.');

@unlink($temp);

echo "MaxMindProviderTest OK\n";
