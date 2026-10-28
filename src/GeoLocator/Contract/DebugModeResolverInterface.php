<?php

declare(strict_types=1);

namespace GeoLocator\Contract;

/**
 * Supplies an optional application-level debug decision for cookie Secure inference.
 */
interface DebugModeResolverInterface
{
    /**
     * Resolve debug mode, allowing the caller to fall back to its environment detector.
     *
     * @return bool|null True for debug mode, false for production mode, or null when undecided.
     *
     * @throws \GeoLocator\Exception\ConfigurationException If an implementation detects an invalid resolver value.
     * @throws \Throwable If an application callback fails; its exception may propagate unchanged.
     */
    public function resolve(): ?bool;
}
