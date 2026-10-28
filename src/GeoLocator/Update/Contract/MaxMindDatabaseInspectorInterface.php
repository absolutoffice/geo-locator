<?php

declare(strict_types=1);

namespace GeoLocator\Update\Contract;

use DateTimeImmutable;

/**
 * Validates a local MMDB candidate and exposes its database build timestamp.
 */
interface MaxMindDatabaseInspectorInterface
{
    /**
     * Checks database metadata and retrieves its build time.
     *
     * @param string $databasePath Readable MMDB file to inspect.
     * @return DateTimeImmutable Build timestamp encoded in valid database metadata.
     * @throws \GeoLocator\Exception\UpdateException When the database or required metadata is invalid.
     */
    public function buildAt(string $databasePath): DateTimeImmutable;
}
