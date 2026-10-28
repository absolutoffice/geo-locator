<?php

/**
 * Exercise scheduled database checks and installation using disposable local fixtures.
 *
 * Covers due intervals, remote metadata, candidate validation, state persistence and
 * locking, with offline collaborators and separate real archive-extraction assertions.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use GeoLocator\Config\MaxMindConfig;
use GeoLocator\Config\UpdateConfig;
use GeoLocator\Exception\UpdateException;
use GeoLocator\Update\Contract\MaxMindArchiveExtractorInterface;
use GeoLocator\Update\Contract\MaxMindDatabaseInspectorInterface;
use GeoLocator\Update\Contract\MaxMindDownloadClientInterface;
use GeoLocator\Update\FileUpdateStateStore;
use GeoLocator\Update\MaxMindArchiveExtractor;
use GeoLocator\Update\MaxMindUpdater;
use GeoLocator\Update\UpdateState;
use GeoLocator\Update\UpdateStatus;

/**
 * Offline download fixture with configurable release metadata and archive bytes.
 */
final class FakeMaxMindDownloadClient implements MaxMindDownloadClientInterface
{
    /** Number of release-date probes performed by the updater. */
    public int $headCalls = 0;
    /** Number of archive download attempts performed by the updater. */
    public int $downloadCalls = 0;

    /**
     * Configure the upstream version and the textual archive fixture.
     *
     * @param DateTimeImmutable|null $releaseAt Remote release time, or null to simulate absent metadata.
     * @param string $archiveContent Bytes copied to the temporary archive path.
     */
    public function __construct(
        /** Simulated remote release timestamp; mutable between updater calls. */
        public ?DateTimeImmutable $releaseAt,
        /** Text used by the fake extractor and inspector to identify the candidate build. */
        public string $archiveContent = 'new',
    ) {
    }

    /**
     * Record a metadata probe and return the configured release timestamp.
     *
     * @return DateTimeImmutable|null Simulated upstream Last-Modified value.
     */
    public function latestReleaseAt(): ?DateTimeImmutable
    {
        $this->headCalls++;
        return $this->releaseAt;
    }

    /**
     * Record a download and write fixture bytes to the updater's temporary path.
     *
     * @param string $targetPath Writable path in the isolated test directory.
     * @return void
     */
    public function downloadTo(string $targetPath): void
    {
        $this->downloadCalls++;
        file_put_contents($targetPath, $this->archiveContent);
    }
}

/**
 * Database inspector fixture mapping the tokens "old" and "new" to build times.
 */
final class FakeMaxMindDatabaseInspector implements MaxMindDatabaseInspectorInterface
{
    /**
     * Define the two recognized build timestamps.
     *
     * @param DateTimeImmutable $oldBuild Timestamp represented by a file containing "old".
     * @param DateTimeImmutable $newBuild Timestamp represented by a file containing "new".
     */
    public function __construct(
        /** Build timestamp of the existing fixture database. */
        private readonly DateTimeImmutable $oldBuild,
        /** Build timestamp of the candidate fixture database. */
        private readonly DateTimeImmutable $newBuild,
    ) {
    }

    /**
     * Inspect fixture bytes instead of parsing an MMDB metadata section.
     *
     * @param string $databasePath Path to the test database containing one recognized token.
     * @return DateTimeImmutable Timestamp selected by the file's exact contents.
     * @throws UpdateException When the contents are unreadable or do not match a recognized token.
     */
    public function buildAt(string $databasePath): DateTimeImmutable
    {
        $content = file_get_contents($databasePath);
        return match ($content) {
            'old' => $this->oldBuild,
            'new' => $this->newBuild,
            default => throw new UpdateException('Invalid fake MMDB.'),
        };
    }
}

/**
 * Archive extractor fixture that copies text without requiring a tar/gzip container.
 */
final class FakeMaxMindArchiveExtractor implements MaxMindArchiveExtractorInterface
{
    /**
     * Copy the fake archive into the candidate database consumed by the inspector.
     *
     * @param string $archivePath Readable fixture archive created by the fake download client.
     * @param string $targetPath Writable candidate path in the isolated test directory.
     * @return void
     * @throws UpdateException When the archive cannot be read.
     */
    public function extractDatabase(string $archivePath, string $targetPath): void
    {
        $content = file_get_contents($archivePath);
        if ($content === false) {
            throw new UpdateException('Unable to read fake archive.');
        }
        file_put_contents($targetPath, $content);
    }
}

/**
 * Wire an updater with offline collaborators and create its isolated directory tree.
 *
 * @param string $root New temporary root owned by this test scenario.
 * @param FakeMaxMindDownloadClient $client Download fixture with controllable metadata and content.
 * @param FakeMaxMindDatabaseInspector $inspector Inspector recognizing the scenario's database tokens.
 * @return array{0: MaxMindUpdater, 1: FileUpdateStateStore, 2: string} Updater, state store and database path.
 */
function makeUpdater(
    string $root,
    FakeMaxMindDownloadClient $client,
    FakeMaxMindDatabaseInspector $inspector,
): array {
    $databasePath = $root . '/db/GeoLite2-City.mmdb';
    $stateDirectory = $root . '/state';
    mkdir(dirname($databasePath), 0777, true);
    mkdir($stateDirectory, 0777, true);

    $store = new FileUpdateStateStore($stateDirectory . '/maxmind-update.json');
    $updater = new MaxMindUpdater(
        maxMindConfig: new MaxMindConfig($databasePath, ['es', 'en'], '123', 'secret'),
        updateConfig: new UpdateConfig(7, $stateDirectory),
        stateStore: $store,
        downloadClient: $client,
        databaseInspector: $inspector,
        archiveExtractor: new FakeMaxMindArchiveExtractor(),
        lockFile: $stateDirectory . '/maxmind-update.lock',
    );

    return [$updater, $store, $databasePath];
}

/**
 * Attempt to remove the temporary tree created by these updater scenarios.
 *
 * The helper assumes a fixture tree without symbolic links; unreadable directories
 * are left in place and unlink/rmdir failures are ignored during cleanup.
 *
 * @param string $path Root of the disposable fixture tree.
 * @return void
 */
function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $path . '/' . $item;
        if (is_dir($full)) {
            removeTree($full);
        } else {
            @unlink($full);
        }
    }
    @rmdir($path);
}

$base = sys_get_temp_dir() . '/geolocator-updater-' . bin2hex(random_bytes(5));
$oldBuild = new DateTimeImmutable('2026-09-01T00:00:00+00:00');
$newBuild = new DateTimeImmutable('2026-09-10T00:00:00+00:00');
$now = new DateTimeImmutable('2026-09-15T12:00:00+00:00');
$inspector = new FakeMaxMindDatabaseInspector($oldBuild, $newBuild);

// Not due: no network request should occur.
$root = $base . '/not-due';
$client = new FakeMaxMindDownloadClient($newBuild);
[$updater, $store, $database] = makeUpdater($root, $client, $inspector);
file_put_contents($database, 'old');
$store->save(new UpdateState(
    lastCheckAt: new DateTimeImmutable('2026-09-14T12:00:00+00:00'),
    lastSuccessfulUpdateAt: new DateTimeImmutable('2026-09-01T12:00:00+00:00'),
    databaseBuildAt: $oldBuild,
));
$result = $updater->updateIfDue($now);
expect($result->status === UpdateStatus::NOT_DUE, 'Recent successful check must return NOT_DUE.');
expect($client->headCalls === 0 && $client->downloadCalls === 0, 'NOT_DUE must not contact MaxMind.');

// Due but remote is not newer: HEAD only, state lastCheckAt advances.
$root = $base . '/up-to-date';
$client = new FakeMaxMindDownloadClient($oldBuild);
[$updater, $store, $database] = makeUpdater($root, $client, $inspector);
file_put_contents($database, 'old');
$store->save(new UpdateState(
    lastCheckAt: new DateTimeImmutable('2026-09-01T12:00:00+00:00'),
    lastSuccessfulUpdateAt: new DateTimeImmutable('2026-09-01T12:00:00+00:00'),
    databaseBuildAt: $oldBuild,
));
$result = $updater->updateIfDue($now);
expect($result->status === UpdateStatus::UP_TO_DATE, 'Older/equal remote release must return UP_TO_DATE.');
expect($client->headCalls === 1 && $client->downloadCalls === 0, 'UP_TO_DATE should avoid database download.');
expect(
    $store->load()->lastCheckAt?->getTimestamp() === $now->getTimestamp(),
    'Successful HEAD check must advance lastCheckAt.',
);

// New release: download, validate, atomically replace and persist state.
$root = $base . '/updated';
$client = new FakeMaxMindDownloadClient($newBuild, 'new');
[$updater, $store, $database] = makeUpdater($root, $client, $inspector);
file_put_contents($database, 'old');
$store->save(new UpdateState(
    lastCheckAt: new DateTimeImmutable('2026-09-01T12:00:00+00:00'),
    lastSuccessfulUpdateAt: new DateTimeImmutable('2026-09-01T12:00:00+00:00'),
    databaseBuildAt: $oldBuild,
));
$result = $updater->updateIfDue($now);
expect($result->status === UpdateStatus::UPDATED, 'New remote database must return UPDATED.');
expect(file_get_contents($database) === 'new', 'UPDATED must replace the local MMDB.');
expect($client->headCalls === 1 && $client->downloadCalls === 1, 'UPDATED must perform HEAD and one download.');
$state = $store->load();
expect(
    $state->databaseBuildAt?->getTimestamp() === $newBuild->getTimestamp(),
    'State must record installed build date.',
);
expect(
    $state->lastSuccessfulUpdateAt?->getTimestamp() === $now->getTimestamp(),
    'State must record successful update date.',
);

// Corrupt candidate: keep the previously working database and do not advance successful check state.
$root = $base . '/bad-candidate';
$client = new FakeMaxMindDownloadClient($newBuild, 'bad');
[$updater, $store, $database] = makeUpdater($root, $client, $inspector);
file_put_contents($database, 'old');
$oldCheck = new DateTimeImmutable('2026-09-01T12:00:00+00:00');
$store->save(new UpdateState($oldCheck, $oldCheck, $oldBuild));
try {
    $updater->updateIfDue($now);
    throw new RuntimeException('Invalid candidate should fail update.');
} catch (UpdateException) {
}
expect(file_get_contents($database) === 'old', 'Invalid candidate must preserve existing MMDB.');
expect(
    $store->load()->lastCheckAt?->getTimestamp() === $oldCheck->getTimestamp(),
    'Failed update must not mark check as successful.',
);

// Missing local database forces a repair even if the previous check is recent.
$root = $base . '/missing-local';
$client = new FakeMaxMindDownloadClient($newBuild, 'new');
[$updater, $store, $database] = makeUpdater($root, $client, $inspector);
$store->save(new UpdateState($now, $oldCheck, $oldBuild));
$result = $updater->updateIfDue($now);
expect($result->status === UpdateStatus::UPDATED, 'Missing local MMDB must force repair/update.');
expect(file_get_contents($database) === 'new', 'Repair must install the downloaded MMDB.');

// File state store round-trip and malformed-state protection.
$stateFile = $base . '/state-roundtrip/update.json';
$roundTripStore = new FileUpdateStateStore($stateFile);
$roundTripStore->save(new UpdateState($now, $oldCheck, $newBuild));
$roundTrip = $roundTripStore->load();
expect($roundTrip->lastCheckAt?->getTimestamp() === $now->getTimestamp(), 'State round-trip lastCheckAt mismatch.');
file_put_contents($stateFile, '{ definitely invalid json');
try {
    $roundTripStore->load();
    throw new RuntimeException('Malformed update state should fail.');
} catch (UpdateException) {
}

// Real tar.gz extraction: only the expected MMDB is copied out.
$extractRoot = $base . '/archive-extractor';
$archiveSource = $extractRoot . '/source/GeoLite2-City_20260910';
mkdir($archiveSource, 0777, true);
file_put_contents($archiveSource . '/GeoLite2-City.mmdb', 'synthetic-mmdb');
$tarPath = $extractRoot . '/GeoLite2-City.tar';
$archive = new \PharData($tarPath);
$archive->addFile(
    $archiveSource . '/GeoLite2-City.mmdb',
    'GeoLite2-City_20260910/GeoLite2-City.mmdb',
);
$archive->compress(\Phar::GZ);
unset($archive);
$extractedPath = $extractRoot . '/extracted.mmdb';
(new MaxMindArchiveExtractor())->extractDatabase($tarPath . '.gz', $extractedPath);
expect(file_get_contents($extractedPath) === 'synthetic-mmdb', 'Real tar.gz extractor must copy the MMDB payload.');

removeTree($base);

echo "MaxMindUpdaterTest OK\n";
