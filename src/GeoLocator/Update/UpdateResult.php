<?php

declare(strict_types=1);

namespace GeoLocator\Update;

use DateTimeImmutable;

/**
 * Immutable outcome of an updater invocation, including the observed database build times.
 */
final class UpdateResult
{
    /**
     * Builds an outcome through the named factories that keep status and timestamps consistent.
     *
     * @param UpdateStatus $status Whether the invocation was skipped, unchanged or installed a new database.
     * @param DateTimeImmutable $checkedAt Invocation time supplied by the updater, including skipped checks.
     * @param DateTimeImmutable|null $previousBuildAt Local database build time before this invocation.
     * @param DateTimeImmutable|null $currentBuildAt Database build time associated with the outcome.
     */
    private function __construct(
        /**
         * Outcome category for this updater invocation.
         */
        public readonly UpdateStatus $status,
        /**
         * Invocation time; NOT_DUE does not imply that a remote request took place.
         */
        public readonly DateTimeImmutable $checkedAt,
        /**
         * Build time before this invocation, or null if no usable database was available.
         */
        public readonly ?DateTimeImmutable $previousBuildAt,
        /**
         * Build time after this invocation, or null when no database build is known.
         */
        public readonly ?DateTimeImmutable $currentBuildAt,
    ) {
    }

    /**
     * Records an invocation skipped because its next scheduled check is not due.
     *
     * @param DateTimeImmutable $checkedAt Time the updater evaluated whether a check was due.
     * @param DateTimeImmutable|null $currentBuildAt Build time of the unchanged local database, if known.
     * @return self NOT_DUE result with identical previous and current build timestamps.
     */
    public static function notDue(DateTimeImmutable $checkedAt, ?DateTimeImmutable $currentBuildAt): self
    {
        return new self(UpdateStatus::NOT_DUE, $checkedAt, $currentBuildAt, $currentBuildAt);
    }

    /**
     * Records a completed check that leaves the existing database in place.
     *
     * @param DateTimeImmutable $checkedAt Time assigned to the completed check.
     * @param DateTimeImmutable|null $currentBuildAt Build time of the retained local database, if known.
     * @return self UP_TO_DATE result with identical previous and current build timestamps.
     */
    public static function upToDate(DateTimeImmutable $checkedAt, ?DateTimeImmutable $currentBuildAt): self
    {
        return new self(UpdateStatus::UP_TO_DATE, $checkedAt, $currentBuildAt, $currentBuildAt);
    }

    /**
     * Records successful installation of a newer database.
     *
     * @param DateTimeImmutable $checkedAt Time assigned to the successful update.
     * @param DateTimeImmutable|null $previousBuildAt Prior build time, or null for a missing or unusable local
     *     database.
     * @param DateTimeImmutable $currentBuildAt Build time read from the installed database.
     * @return self UPDATED result describing the build transition.
     */
    public static function updated(
        DateTimeImmutable $checkedAt,
        ?DateTimeImmutable $previousBuildAt,
        DateTimeImmutable $currentBuildAt,
    ): self {
        return new self(UpdateStatus::UPDATED, $checkedAt, $previousBuildAt, $currentBuildAt);
    }
}
