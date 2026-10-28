<?php

declare(strict_types=1);

namespace GeoLocator\Update\Contract;

/**
 * Extracts the database candidate from a downloaded MaxMind archive.
 */
interface MaxMindArchiveExtractorInterface
{
    /**
     * Copies the selected database entry to a staging file for subsequent inspection.
     *
     * @param string $archivePath Readable local archive containing the expected MMDB file.
     * @param string $targetPath Unused candidate path in a directory controlled by the updater.
     * @return void A successful return leaves the extracted database at the candidate path.
     * @throws \GeoLocator\Exception\UpdateException When the archive or database entry cannot be extracted.
     */
    public function extractDatabase(string $archivePath, string $targetPath): void;
}
