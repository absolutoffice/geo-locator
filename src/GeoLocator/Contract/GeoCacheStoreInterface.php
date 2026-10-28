<?php

declare(strict_types=1);

namespace GeoLocator\Contract;

use GeoLocator\Cache\GeoCacheEntry;

/**
 * Storage boundary for authenticated visitor-cache entries.
 *
 * The store reads or writes an entry; expiry and IP-binding policy belong to the
 * caller. Implementations may reject or remove malformed stored content.
 */
interface GeoCacheStoreInterface
{
    /**
     * Read the current visitor's stored geolocation entry if usable data is available.
     *
     * @return GeoCacheEntry|null Decoded entry, or null when no usable entry is present.
     *
     * @throws \GeoLocator\Exception\CacheException If storage access or removal of an invalid entry fails.
     */
    public function read(): ?GeoCacheEntry;

    /**
     * Persist or emit a visitor cache entry using the store's configured medium.
     *
     * @param GeoCacheEntry $entry Entry whose timestamps, location and IP token are already assembled.
     * @return void
     *
     * @throws \GeoLocator\Exception\CacheException If the entry cannot be encoded or written.
     * @throws \GeoLocator\Exception\SecurityException If an encoded cookie entry fails round-trip verification.
     */
    public function write(GeoCacheEntry $entry): void;

    /**
     * Remove or expire the current visitor's stored cache entry.
     *
     * @return void
     *
     * @throws \GeoLocator\Exception\CacheException If removal cannot be completed.
     */
    public function delete(): void;
}
