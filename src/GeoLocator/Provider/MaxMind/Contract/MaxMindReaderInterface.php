<?php

declare(strict_types=1);

namespace GeoLocator\Provider\MaxMind\Contract;

/**
 * Reads raw MMDB records while keeping the provider independent of a concrete reader.
 *
 * Implementations retain their own resources until close() is called.
 */
interface MaxMindReaderInterface
{
    /**
     * Looks up one IPv4 or IPv6 address in the opened database.
     *
     * @param string $ipAddress Address in textual form; callers supply a validated address.
     * @return mixed Decoded database record, or null when no record matches the address.
     * @throws \Throwable When the reader cannot perform or decode the lookup.
     */
    public function get(string $ipAddress): mixed;

    /**
     * Releases resources owned by this reader.
     *
     * @return void No value is returned; callers must stop using the closed reader.
     * @throws \Throwable When the underlying reader cannot close its resources.
     */
    public function close(): void;
}
