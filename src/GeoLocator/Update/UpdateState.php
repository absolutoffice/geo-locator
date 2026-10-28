<?php

declare(strict_types=1);

namespace GeoLocator\Update;

use DateTimeImmutable;

/**
 * Immutable persisted record of completed checks and successful database installations.
 *
 * Null fields represent information that has not yet been recorded. The build time
 * is database metadata, whereas check and successful-update times describe local work.
 */
final class UpdateState
{
    /**
     * Creates update history without inferring values for unknown timestamps.
     *
     * @param DateTimeImmutable|null $lastCheckAt Time of the most recent successfully completed release check.
     * @param DateTimeImmutable|null $lastSuccessfulUpdateAt Time a newer database was last installed successfully.
     * @param DateTimeImmutable|null $databaseBuildAt Build time of the database recorded by that check or installation.
     */
    public function __construct(
        /**
         * Time of the last completed check, or null before a check has been recorded.
         */
        public readonly ?DateTimeImmutable $lastCheckAt = null,
        /**
         * Time of the last successful installation, or null when none is recorded.
         */
        public readonly ?DateTimeImmutable $lastSuccessfulUpdateAt = null,
        /**
         * Recorded MMDB build time, or null when the local build is unknown.
         */
        public readonly ?DateTimeImmutable $databaseBuildAt = null,
    ) {
    }
}
