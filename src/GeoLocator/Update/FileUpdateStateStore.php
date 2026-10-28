<?php

declare(strict_types=1);

namespace GeoLocator\Update;

use DateTimeImmutable;
use GeoLocator\Contract\UpdateStateStoreInterface;
use GeoLocator\Exception\UpdateException;
use JsonException;
use Throwable;

/**
 * Persists updater history as versioned JSON using a temporary file and rename.
 *
 * A missing state file starts with empty history. Invalid existing state is reported
 * as an error instead of silently resetting the check schedule.
 */
final class FileUpdateStateStore implements UpdateStateStoreInterface
{
    /**
     * JSON schema version accepted by load() and emitted by save().
     */
    private const FORMAT_VERSION = 1;

    /**
     * Selects the JSON state file without creating it yet.
     *
     * @param string $stateFile Non-blank filesystem path for persistent update history.
     * @throws UpdateException When the supplied path is blank.
     */
    public function __construct(
        /**
         * Destination used for reading and atomically replacing the state JSON.
         */
        private readonly string $stateFile,
    ) {
        if (trim($stateFile) === '') {
            throw new UpdateException('Update state file path cannot be empty.');
        }
    }

    /**
     * Reads versioned update history, or returns empty history if no regular file exists.
     *
     * Absent date fields are treated as null. Non-null values must be non-blank strings
     * that PHP can parse as dates; save() writes them in DATE_ATOM format.
     *
     * @return UpdateState Loaded timestamps, or an all-null state before the first saved update.
     * @throws UpdateException When existing state is unreadable, malformed or uses an unsupported version.
     */
    public function load(): UpdateState
    {
        if (!is_file($this->stateFile)) {
            return new UpdateState();
        }

        if (!is_readable($this->stateFile)) {
            throw new UpdateException(sprintf('Update state file is not readable: %s', $this->stateFile));
        }

        $json = file_get_contents($this->stateFile);
        if ($json === false) {
            throw new UpdateException(sprintf('Unable to read update state file: %s', $this->stateFile));
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UpdateException('Update state file contains invalid JSON.', 0, $e);
        }

        if (!is_array($data) || ($data['version'] ?? null) !== self::FORMAT_VERSION) {
            throw new UpdateException('Update state file has an unsupported format.');
        }

        return new UpdateState(
            lastCheckAt: $this->dateValue($data, 'lastCheckAt'),
            lastSuccessfulUpdateAt: $this->dateValue($data, 'lastSuccessfulUpdateAt'),
            databaseBuildAt: $this->dateValue($data, 'databaseBuildAt'),
        );
    }

    /**
     * Writes formatted history beside the target and replaces the target with rename().
     *
     * The parent directory is created with mode 0700 when needed. Mode 0600 is attempted
     * on the temporary file. Failed writes remove their temporary file when possible.
     *
     * @param UpdateState $state History timestamps to serialize using DATE_ATOM.
     * @return void A successful return means the state file has been replaced.
     * @throws UpdateException When the directory, JSON encoding, write or replacement fails.
     * @throws \Random\RandomException When a unique temporary filename cannot be generated.
     */
    public function save(UpdateState $state): void
    {
        $directory = dirname($this->stateFile);
        $this->ensureDirectory($directory);

        try {
            $json = json_encode([
                'version' => self::FORMAT_VERSION,
                'lastCheckAt' => $state->lastCheckAt?->format(DATE_ATOM),
                'lastSuccessfulUpdateAt' => $state->lastSuccessfulUpdateAt?->format(DATE_ATOM),
                'databaseBuildAt' => $state->databaseBuildAt?->format(DATE_ATOM),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UpdateException('Unable to encode update state.', 0, $e);
        }

        $temporary = $this->stateFile . '.tmp-' . bin2hex(random_bytes(6));
        try {
            if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
                throw new UpdateException(sprintf('Unable to write temporary update state file: %s', $temporary));
            }

            @chmod($temporary, 0600);
            if (!rename($temporary, $this->stateFile)) {
                throw new UpdateException(sprintf('Unable to replace update state file: %s', $this->stateFile));
            }
        } catch (Throwable $e) {
            @unlink($temporary);
            if ($e instanceof UpdateException) {
                throw $e;
            }

            throw new UpdateException('Unable to save update state.', 0, $e);
        }
    }

    /**
     * Parses one optional timestamp from the decoded state object.
     *
     * @param array<array-key, mixed> $data Decoded versioned state fields.
     * @param string $key Name of the timestamp field to read.
     * @return DateTimeImmutable|null Parsed time, or null when the field is absent or null.
     * @throws UpdateException When a non-null field is not a usable date string.
     */
    private function dateValue(array $data, string $key): ?DateTimeImmutable
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || trim($value) === '') {
            throw new UpdateException(sprintf('Update state field %s must be an ISO-8601 string or null.', $key));
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $e) {
            throw new UpdateException(sprintf('Update state field %s contains an invalid date.', $key), 0, $e);
        }
    }

    /**
     * Ensures that the state directory exists and is writable.
     *
     * @param string $directory Parent directory to use or create recursively with mode 0700.
     * @return void No value is returned when the directory can be used.
     * @throws UpdateException When the existing directory is not writable or creation fails.
     */
    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            if (!is_writable($directory)) {
                throw new UpdateException(sprintf('Update state directory is not writable: %s', $directory));
            }

            return;
        }

        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new UpdateException(sprintf('Unable to create update state directory: %s', $directory));
        }
    }
}
