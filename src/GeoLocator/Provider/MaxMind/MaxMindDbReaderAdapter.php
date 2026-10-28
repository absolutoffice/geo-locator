<?php

declare(strict_types=1);

namespace GeoLocator\Provider\MaxMind;

use GeoLocator\Provider\MaxMind\Contract\MaxMindReaderInterface;
use MaxMind\Db\Reader;

/**
 * Adapts the Composer MaxMind DB reader without changing its records or exceptions.
 */
final class MaxMindDbReaderAdapter implements MaxMindReaderInterface
{
    /**
     * Wraps an already-open MaxMind reader.
     *
     * @param Reader $reader Reader whose lifetime is managed through this adapter.
     */
    public function __construct(
        /**
         * Open MMDB reader used for all lookups and resource cleanup.
         */
        private readonly Reader $reader,
    ) {
    }

    /**
     * Returns the raw record produced by the MaxMind reader.
     *
     * @param string $ipAddress IPv4 or IPv6 address to look up.
     * @return mixed Decoded MMDB record, or null if the database has no matching record.
     * @throws \InvalidArgumentException When the address is invalid or incompatible with the database.
     * @throws \BadMethodCallException When the reader is closed or another lookup is in progress.
     * @throws \MaxMind\Db\Reader\InvalidDatabaseException When database data cannot be read or decoded.
     */
    public function get(string $ipAddress): mixed
    {
        return $this->reader->get($ipAddress);
    }

    /**
     * Closes the wrapped reader and releases its file handle.
     *
     * @return void No value is returned; the wrapped reader cannot be reused.
     * @throws \BadMethodCallException When the wrapped reader has already been closed.
     */
    public function close(): void
    {
        $this->reader->close();
    }
}
