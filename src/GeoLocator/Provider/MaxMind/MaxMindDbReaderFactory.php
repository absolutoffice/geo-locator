<?php

declare(strict_types=1);

namespace GeoLocator\Provider\MaxMind;

use GeoLocator\Provider\MaxMind\Contract\MaxMindReaderFactoryInterface;
use GeoLocator\Provider\MaxMind\Contract\MaxMindReaderInterface;
use MaxMind\Db\Reader;

/**
 * Creates adapters backed by the installed maxmind-db/reader package.
 */
final class MaxMindDbReaderFactory implements MaxMindReaderFactoryInterface
{
    /**
     * Opens the requested MMDB file and wraps the resulting reader.
     *
     * @param string $databasePath Filesystem path of a readable MaxMind database.
     * @return MaxMindReaderInterface Newly opened reader; ownership passes to the caller.
     * @throws \InvalidArgumentException When the file is missing or unreadable.
     * @throws \UnexpectedValueException When the reader cannot determine the file size.
     * @throws \MaxMind\Db\Reader\InvalidDatabaseException When the file is not a usable MMDB database.
     */
    public function open(string $databasePath): MaxMindReaderInterface
    {
        return new MaxMindDbReaderAdapter(new Reader($databasePath));
    }
}
