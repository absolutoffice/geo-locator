<?php

declare(strict_types=1);

namespace GeoLocator\Contract;

use GeoLocator\Update\UpdateState;

/**
 * Persistence boundary for database-update check, installation and build timestamps.
 */
interface UpdateStateStoreInterface
{
    /**
     * Read persisted update state, returning empty state when no state has been saved.
     *
     * @return UpdateState Last known update metadata, with null fields for an initial state.
     *
     * @throws \GeoLocator\Exception\UpdateException If stored state cannot be read or decoded.
     */
    public function load(): UpdateState;

    /**
     * Persist the latest update timestamps according to the storage implementation.
     *
     * @param UpdateState $state Complete state snapshot to make available to subsequent loads.
     * @return void
     *
     * @throws \GeoLocator\Exception\UpdateException If state cannot be encoded or written.
     */
    public function save(UpdateState $state): void;
}
