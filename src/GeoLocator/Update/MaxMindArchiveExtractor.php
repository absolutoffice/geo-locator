<?php

declare(strict_types=1);

namespace GeoLocator\Update;

use GeoLocator\Exception\UpdateException;
use GeoLocator\Update\Contract\MaxMindArchiveExtractorInterface;
use PharData;
use PharFileInfo;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Copies a single matching MMDB entry from a Phar-readable MaxMind archive.
 *
 * Entries are selected by basename and their archive paths are not used as local
 * extraction destinations. The supplied candidate path must be unused and controlled
 * by the updater; cleanup attempts to remove that path when extraction fails.
 */
final class MaxMindArchiveExtractor implements MaxMindArchiveExtractorInterface
{
    /**
     * Chooses the database basename that must occur exactly once in the archive.
     *
     * @param string $databaseFilename Expected MMDB basename, such as GeoLite2-City.mmdb.
     */
    public function __construct(
        /**
         * Exact basename used to select the archive's database entry.
         */
        private readonly string $databaseFilename = 'GeoLite2-City.mmdb',
    ) {
    }

    /**
     * Streams the unique matching database entry into an exclusively created candidate file.
     *
     * The archive must contain exactly one non-directory entry with the configured
     * basename. Candidate validity and metadata are checked separately by the inspector.
     *
     * @param string $archivePath Readable local archive, normally the downloaded tar.gz file.
     * @param string $targetPath Unused candidate path in an updater-controlled directory; subject to failure cleanup.
     * @return void A successful return leaves the copied candidate ready for database inspection.
     * @throws UpdateException When the archive is unreadable, ambiguous, missing the entry or cannot be copied.
     */
    public function extractDatabase(string $archivePath, string $targetPath): void
    {
        if (!is_file($archivePath) || !is_readable($archivePath)) {
            throw new UpdateException(sprintf('MaxMind archive is not readable: %s', $archivePath));
        }

        $source = null;
        $destination = null;
        try {
            $archive = new PharData($archivePath);
            $iterator = new RecursiveIteratorIterator($archive);

            foreach ($iterator as $entry) {
                if (!$entry instanceof PharFileInfo) {
                    continue;
                }

                if ($entry->isDir() || basename($entry->getPathname()) !== $this->databaseFilename) {
                    continue;
                }

                if ($source !== null) {
                    throw new UpdateException(sprintf(
                        'MaxMind archive contains multiple %s files.',
                        $this->databaseFilename,
                    ));
                }

                $source = fopen($entry->getPathname(), 'rb');
                if ($source === false) {
                    throw new UpdateException('Unable to open MaxMind database inside archive.');
                }
            }

            if ($source === null) {
                throw new UpdateException(sprintf('MaxMind archive does not contain %s.', $this->databaseFilename));
            }

            $destination = fopen($targetPath, 'xb');
            if ($destination === false) {
                throw new UpdateException(sprintf('Unable to create candidate MaxMind database: %s', $targetPath));
            }

            if (stream_copy_to_stream($source, $destination) === false) {
                throw new RuntimeException('Unable to copy MaxMind database from archive.');
            }
        } catch (UpdateException $e) {
            @unlink($targetPath);
            throw $e;
        } catch (Throwable $e) {
            @unlink($targetPath);
            throw new UpdateException('Unable to extract MaxMind database archive.', 0, $e);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }
        }
    }
}
