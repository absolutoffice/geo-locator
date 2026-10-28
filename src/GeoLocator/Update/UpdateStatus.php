<?php

declare(strict_types=1);

namespace GeoLocator\Update;

/**
 * Distinguishes skipped checks, unchanged databases and successful replacements.
 */
enum UpdateStatus: string
{
    /**
     * A usable local database exists and the configured check interval has not elapsed.
     */
    case NOT_DUE = 'not_due';
    /**
     * A check found no remote release or validated candidate newer than the local database.
     */
    case UP_TO_DATE = 'up_to_date';
    /**
     * A newer validated database was installed and its successful update state was saved.
     */
    case UPDATED = 'updated';
}
