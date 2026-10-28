<?php

declare(strict_types=1);

namespace GeoLocator\Update\Contract;

use DateTimeImmutable;

/**
 * Supplies remote release metadata and database archives to the updater.
 */
interface MaxMindDownloadClientInterface
{
    /**
     * Checks the release timestamp advertised by the download service.
     *
     * @return DateTimeImmutable|null Remote release time, or null when the service supplies no timestamp.
     * @throws \GeoLocator\Exception\UpdateException When the metadata request or its interpretation fails.
     */
    public function latestReleaseAt(): ?DateTimeImmutable;

    /**
     * Downloads a complete archive to a caller-owned staging location.
     *
     * @param string $targetPath Unused file path inside a directory controlled by the updater.
     * @return void A successful return leaves the archive available at the requested path.
     * @throws \GeoLocator\Exception\UpdateException When the download or required integrity checks fail.
     */
    public function downloadTo(string $targetPath): void;
}
