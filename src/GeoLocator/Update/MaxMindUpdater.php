<?php

declare(strict_types=1);

namespace GeoLocator\Update;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use GeoLocator\Config\MaxMindConfig;
use GeoLocator\Config\UpdateConfig;
use GeoLocator\Contract\UpdateStateStoreInterface;
use GeoLocator\Exception\UpdateException;
use GeoLocator\Update\Contract\MaxMindArchiveExtractorInterface;
use GeoLocator\Update\Contract\MaxMindDatabaseInspectorInterface;
use GeoLocator\Update\Contract\MaxMindDownloadClientInterface;
use Throwable;

/**
 * Coordinates scheduled checks, validated downloads and replacement of a local MMDB.
 *
 * A filesystem lock serializes cooperating updater instances. The candidate is built
 * beside the destination for rename-based replacement. State is saved after the
 * installation; a later inspection or state-write failure does not roll back a
 * replacement that already succeeded.
 */
final class MaxMindUpdater
{
    /**
     * Assembles update collaborators and validates the lock path.
     *
     * @param MaxMindConfig $maxMindConfig Destination database path and provider settings.
     * @param UpdateConfig $updateConfig Check interval and private staging directory.
     * @param UpdateStateStoreInterface $stateStore Persistence for completed check and update timestamps.
     * @param MaxMindDownloadClientInterface $downloadClient Remote metadata and archive source.
     * @param MaxMindDatabaseInspectorInterface $databaseInspector Validator for local, candidate and installed MMDB
     *     files.
     * @param MaxMindArchiveExtractorInterface $archiveExtractor Extractor for the downloaded database candidate.
     * @param string $lockFile Non-blank lock file path shared by cooperating updater instances.
     * @throws UpdateException When the lock file path is blank.
     */
    public function __construct(
        /**
         * Destination database configuration used for inspection and replacement.
         */
        private readonly MaxMindConfig $maxMindConfig,
        /**
         * Check interval and staging directory used by this updater.
         */
        private readonly UpdateConfig $updateConfig,
        /**
         * Persistence for successful checks and installations.
         */
        private readonly UpdateStateStoreInterface $stateStore,
        /**
         * Source of remote release metadata and downloaded archives.
         */
        private readonly MaxMindDownloadClientInterface $downloadClient,
        /**
         * MMDB validator and build-time reader for each installation stage.
         */
        private readonly MaxMindDatabaseInspectorInterface $databaseInspector,
        /**
         * Extractor that produces the staged MMDB candidate.
         */
        private readonly MaxMindArchiveExtractorInterface $archiveExtractor,
        /**
         * File used to serialize cooperating update attempts with an exclusive lock.
         */
        private readonly string $lockFile,
    ) {
        if (trim($lockFile) === '') {
            throw new UpdateException('MaxMind update lock file path cannot be empty.');
        }
    }

    /**
     * Checks for a newer database when the configured interval has elapsed or repair is needed.
     *
     * A missing or invalid local database forces a check. Due status is evaluated again
     * under the lock to account for another updater completing first. If remote release
     * metadata is absent, the downloaded candidate build time decides whether to install.
     *
     * @param DateTimeImmutable|null $now Evaluation time; null uses the current time and all values are normalized to
     *     UTC.
     * @return UpdateResult NOT_DUE, UP_TO_DATE or UPDATED with the relevant local build timestamps.
     * @throws UpdateException When state, locking, download, extraction, inspection or installation fails.
     * @throws \Random\RandomException When staging filenames cannot be generated.
     * @throws \Throwable When an injected collaborator propagates an error outside the installation wrapper.
     */
    public function updateIfDue(?DateTimeImmutable $now = null): UpdateResult
    {
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));

        $state = $this->stateStore->load();
        $localBuildAt = $this->localBuildAt();
        if (!$this->isDue($state, $now, $localBuildAt)) {
            return UpdateResult::notDue($now, $localBuildAt);
        }

        return $this->withLock(function () use ($now): UpdateResult {
            $state = $this->stateStore->load();
            $localBuildAt = $this->localBuildAt();
            if (!$this->isDue($state, $now, $localBuildAt)) {
                return UpdateResult::notDue($now, $localBuildAt);
            }

            $remoteReleaseAt = $this->downloadClient->latestReleaseAt();
            if ($localBuildAt !== null && $remoteReleaseAt !== null && $remoteReleaseAt <= $localBuildAt) {
                $this->stateStore->save(new UpdateState(
                    lastCheckAt: $now,
                    lastSuccessfulUpdateAt: $state->lastSuccessfulUpdateAt,
                    databaseBuildAt: $localBuildAt,
                ));

                return UpdateResult::upToDate($now, $localBuildAt);
            }

            return $this->downloadAndInstall($state, $localBuildAt, $now);
        });
    }

    /**
     * Downloads, extracts and inspects a candidate, installing it only when its build is newer.
     *
     * A successful rename replaces the live database before final inspection and state
     * persistence. Cleanup attempts to remove the archive and remaining candidate path;
     * unlink failures are suppressed. It does not roll back an already installed database.
     *
     * @param UpdateState $state History loaded while holding the update lock.
     * @param DateTimeImmutable|null $localBuildAt Build time of the usable current database, or null if repair is
     *     needed.
     * @param DateTimeImmutable $now Time assigned to this completed check or installation.
     * @return UpdateResult UP_TO_DATE for an older or equal candidate, or UPDATED after successful installation.
     * @throws UpdateException When staging, download, validation, installation or state persistence fails.
     * @throws \Random\RandomException When random bytes for staging filenames cannot be generated.
     */
    private function downloadAndInstall(
        UpdateState $state,
        ?DateTimeImmutable $localBuildAt,
        DateTimeImmutable $now,
    ): UpdateResult {
        $stateDirectory = $this->requiredStateDirectory();
        $databaseDirectory = dirname($this->maxMindConfig->databasePath);
        $this->ensureDirectory($stateDirectory, 0700);
        $this->ensureDirectory($databaseDirectory, 0755);

        $token = bin2hex(random_bytes(8));
        $archivePath = $stateDirectory . DIRECTORY_SEPARATOR . '.maxmind-' . $token . '.tar.gz';
        $candidatePath = $databaseDirectory . DIRECTORY_SEPARATOR
            . '.' . basename($this->maxMindConfig->databasePath) . '.' . $token . '.tmp';

        try {
            $this->downloadClient->downloadTo($archivePath);
            $this->archiveExtractor->extractDatabase($archivePath, $candidatePath);
            $candidateBuildAt = $this->databaseInspector->buildAt($candidatePath);

            if ($localBuildAt !== null && $candidateBuildAt <= $localBuildAt) {
                $this->stateStore->save(new UpdateState(
                    lastCheckAt: $now,
                    lastSuccessfulUpdateAt: $state->lastSuccessfulUpdateAt,
                    databaseBuildAt: $localBuildAt,
                ));

                return UpdateResult::upToDate($now, $localBuildAt);
            }

            $this->applyDatabasePermissions($candidatePath);
            if (!rename($candidatePath, $this->maxMindConfig->databasePath)) {
                throw new UpdateException(sprintf(
                    'Unable to atomically replace MaxMind database: %s',
                    $this->maxMindConfig->databasePath,
                ));
            }

            clearstatcache(true, $this->maxMindConfig->databasePath);
            $installedBuildAt = $this->databaseInspector->buildAt($this->maxMindConfig->databasePath);
            if ($installedBuildAt->getTimestamp() !== $candidateBuildAt->getTimestamp()) {
                throw new UpdateException(
                    'Installed MaxMind database metadata changed unexpectedly after replacement.',
                );
            }

            $this->stateStore->save(new UpdateState(
                lastCheckAt: $now,
                lastSuccessfulUpdateAt: $now,
                databaseBuildAt: $installedBuildAt,
            ));

            return UpdateResult::updated($now, $localBuildAt, $installedBuildAt);
        } catch (UpdateException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new UpdateException('MaxMind database update failed.', 0, $e);
        } finally {
            @unlink($archivePath);
            @unlink($candidatePath);
        }
    }

    /**
     * Reads the installed database build time and treats validation failure as a repair requirement.
     *
     * @return DateTimeImmutable|null Local build time, or null for a missing file or an UpdateException during
     *     inspection.
     * @throws \Throwable When an injected inspector throws an exception other than UpdateException.
     */
    private function localBuildAt(): ?DateTimeImmutable
    {
        $path = $this->maxMindConfig->databasePath;
        if (!is_file($path)) {
            return null;
        }

        try {
            return $this->databaseInspector->buildAt($path);
        } catch (UpdateException) {
            // A missing/corrupt local DB must force a repair attempt even when the
            // previous successful check would otherwise make the update not due.
            return null;
        }
    }

    /**
     * Evaluates the check interval, forcing a check when local database or history is missing.
     *
     * @param UpdateState $state Persisted history used to determine the next scheduled check.
     * @param DateTimeImmutable $now Current evaluation time.
     * @param DateTimeImmutable|null $localBuildAt Usable local database build time, or null to force repair.
     * @return bool True if repair is needed, no previous check exists, or the configured interval has elapsed.
     */
    private function isDue(UpdateState $state, DateTimeImmutable $now, ?DateTimeImmutable $localBuildAt): bool
    {
        if ($localBuildAt === null || $state->lastCheckAt === null) {
            return true;
        }

        $nextCheckAt = $state->lastCheckAt->add(new DateInterval('P' . $this->updateConfig->intervalDays . 'D'));

        return $now >= $nextCheckAt;
    }

    /**
     * Runs an operation while holding a blocking exclusive filesystem lock.
     *
     * The lock is released and its handle closed even when the callback throws. Only
     * processes that use the same locking convention are serialized by this mechanism.
     *
     * @template T
     * @param callable():T $callback Operation to run after the exclusive lock is acquired.
     * @return T Value returned by the callback.
     * @throws UpdateException When the lock directory, file or exclusive lock cannot be acquired.
     * @throws \Throwable When the callback fails; its exception is propagated.
     */
    private function withLock(callable $callback): mixed
    {
        $directory = dirname($this->lockFile);
        $this->ensureDirectory($directory, 0700);

        $handle = @fopen($this->lockFile, 'c+');
        if ($handle === false) {
            throw new UpdateException(sprintf('Unable to open MaxMind update lock file: %s', $this->lockFile));
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new UpdateException('Unable to acquire MaxMind update lock.');
            }

            return $callback();
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Retrieves the configured staging directory and removes trailing directory separators.
     *
     * @return string Configured state directory used to build archive staging paths.
     * @throws UpdateException When the configured state directory is null or blank.
     */
    private function requiredStateDirectory(): string
    {
        $stateDirectory = $this->updateConfig->stateDirectory;
        if ($stateDirectory === null || trim($stateDirectory) === '') {
            throw new UpdateException('MaxMind update state directory is required.');
        }

        return rtrim($stateDirectory, DIRECTORY_SEPARATOR);
    }

    /**
     * Checks an existing directory or creates the required parent tree.
     *
     * @param string $directory Directory required for state, locking or candidate database files.
     * @param int $mode Permission bits requested for creation; the process umask still applies.
     * @return void No value is returned when the directory can be used.
     * @throws UpdateException When an existing directory is not writable or creation fails.
     */
    private function ensureDirectory(string $directory, int $mode): void
    {
        if (is_dir($directory)) {
            if (!is_writable($directory)) {
                throw new UpdateException(sprintf('Directory is not writable: %s', $directory));
            }
            return;
        }

        if (!mkdir($directory, $mode, true) && !is_dir($directory)) {
            throw new UpdateException(sprintf('Unable to create directory: %s', $directory));
        }
    }

    /**
     * Attempts to give the candidate the existing database permissions, or mode 0644 for a new database.
     *
     * This is a best-effort permission adjustment: chmod failures are suppressed.
     *
     * @param string $candidatePath Extracted database file that will be renamed into place.
     * @return void No value is returned and no chmod success guarantee is made.
     */
    private function applyDatabasePermissions(string $candidatePath): void
    {
        $mode = 0644;
        if (is_file($this->maxMindConfig->databasePath)) {
            $permissions = fileperms($this->maxMindConfig->databasePath);
            if ($permissions !== false) {
                $mode = $permissions & 0777;
            }
        }

        @chmod($candidatePath, $mode);
    }
}
