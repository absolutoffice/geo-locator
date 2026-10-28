<?php

declare(strict_types=1);

namespace GeoLocator\Update;

use DateTimeImmutable;
use DateTimeZone;
use GeoLocator\Exception\UpdateException;
use GeoLocator\Update\Contract\MaxMindDatabaseInspectorInterface;
use MaxMind\Db\Reader;
use Throwable;

/**
 * Checks the MMDB metadata type and build epoch before the updater accepts a database.
 */
final class MaxMindDatabaseInspector implements MaxMindDatabaseInspectorInterface
{
    /**
     * Selects the database type expected in MMDB metadata.
     *
     * @param string $expectedDatabaseType Exact metadata databaseType required for acceptance.
     */
    public function __construct(
        /**
         * Expected metadata databaseType, normally the configured MaxMind edition.
         */
        private readonly string $expectedDatabaseType = 'GeoLite2-City',
    ) {
    }

    /**
     * Opens the MMDB, checks its type and returns a positive build epoch as a UTC timestamp.
     *
     * Metadata inspection does not perform an exhaustive read of all database records.
     * The temporary reader is closed before this method exits.
     *
     * @param string $databasePath Readable MMDB candidate or installed database to inspect.
     * @return DateTimeImmutable Validated database build timestamp in UTC.
     * @throws UpdateException When the file, database type or build epoch cannot be validated.
     */
    public function buildAt(string $databasePath): DateTimeImmutable
    {
        if (!is_file($databasePath) || !is_readable($databasePath)) {
            throw new UpdateException(sprintf('MaxMind database is not readable: %s', $databasePath));
        }

        $reader = null;
        try {
            $reader = new Reader($databasePath);
            $metadata = $reader->metadata();
            $databaseType = (string) $metadata->databaseType;
            $buildEpoch = (int) $metadata->buildEpoch;

            if ($databaseType !== $this->expectedDatabaseType) {
                throw new UpdateException(sprintf(
                    'Unexpected MaxMind database type %s; expected %s.',
                    $databaseType,
                    $this->expectedDatabaseType,
                ));
            }

            if ($buildEpoch <= 0) {
                throw new UpdateException('MaxMind database metadata contains an invalid build epoch.');
            }

            return (new DateTimeImmutable('@' . $buildEpoch))->setTimezone(new DateTimeZone('UTC'));
        } catch (UpdateException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new UpdateException(sprintf('Unable to validate MaxMind database %s.', $databasePath), 0, $e);
        } finally {
            if ($reader !== null) {
                $reader->close();
            }
        }
    }
}
