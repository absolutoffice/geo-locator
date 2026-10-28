<?php

declare(strict_types=1);

namespace GeoLocator\Config;

use GeoLocator\Exception\ConfigurationException;

/**
 * Immutable scheduling and HTTP limits for MaxMind database updates.
 *
 * HTTP attempts include the initial request. The factory converts that count into
 * xcurl's retry count; the timeout applies to a transport operation, not an entire
 * update containing redirects, retries, extraction and installation.
 */
final class UpdateConfig
{
    /**
     * Validate the update interval, state location and bounded HTTP attempt count.
     *
     * @param int $intervalDays Minimum interval between scheduled update checks, in whole days (at least one).
     * @param string|null $stateDirectory State/lock directory; null is allowed here but rejected by createUpdater().
     * @param int $httpTimeoutSeconds Positive timeout in seconds passed to each xcurl transport operation.
     * @param int $httpMaxAttempts Total HTTP attempts (one to three), including the initial request.
     *
     * @throws ConfigurationException If a numeric limit is out of range or an explicitly supplied directory is blank.
     */
    public function __construct(
        /**
         * Minimum interval between non-forced update checks, measured in days.
         */
        public readonly int $intervalDays = 7,
        /**
         * Optional writable state and lock directory, required when creating an updater.
         */
        public readonly ?string $stateDirectory = null,
        /**
         * Per-transport HTTP timeout in seconds; not a deadline for the full update.
         */
        public readonly int $httpTimeoutSeconds = 30,
        /**
         * Total HTTP attempt count (1..3), including the first attempt before any retry.
         */
        public readonly int $httpMaxAttempts = 3,
    ) {
        if ($intervalDays < 1) {
            throw new ConfigurationException('Update interval must be at least 1 day.');
        }

        if ($stateDirectory !== null && trim($stateDirectory) === '') {
            throw new ConfigurationException('Update state directory cannot be empty when provided.');
        }

        if ($httpTimeoutSeconds < 1) {
            throw new ConfigurationException('MaxMind HTTP timeout must be at least 1 second.');
        }

        if ($httpMaxAttempts < 1 || $httpMaxAttempts > 3) {
            throw new ConfigurationException('MaxMind HTTP attempts must be between 1 and 3.');
        }
    }
}
