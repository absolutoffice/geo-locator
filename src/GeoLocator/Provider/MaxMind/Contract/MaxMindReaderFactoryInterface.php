<?php

declare(strict_types=1);

namespace GeoLocator\Provider\MaxMind\Contract;

/**
 * Opens MMDB readers for the provider and permits injected reader implementations.
 */
interface MaxMindReaderFactoryInterface
{
    /**
     * Opens a database for subsequent address lookups.
     *
     * @param string $databasePath Filesystem path of the MMDB file to read.
     * @return MaxMindReaderInterface An open reader whose resources the caller must eventually close.
     * @throws \Throwable When the file is unavailable, invalid or cannot be opened.
     */
    public function open(string $databasePath): MaxMindReaderInterface;
}
