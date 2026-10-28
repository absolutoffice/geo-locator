<?php

declare(strict_types=1);

namespace GeoLocator\Http;

use Closure;
use GeoLocator\Contract\DebugModeResolverInterface;
use GeoLocator\Exception\ConfigurationException;

/**
 * Adapts an application callback to the optional debug-mode decision contract.
 */
final class CallableDebugModeResolver implements DebugModeResolverInterface
{
    /**
     * Application callback evaluated each time a debug decision is requested.
     *
     * @var Closure(): mixed
     */
    private readonly Closure $resolver;

    /**
     * Stores the callback as a closure for deferred evaluation.
     *
     * @param callable(): mixed $resolver Callback expected to return bool or null when evaluated.
     */
    public function __construct(callable $resolver)
    {
        $this->resolver = Closure::fromCallable($resolver);
    }

    /**
     * Evaluates and validates the application's debug-mode decision.
     *
     * @return bool|null Explicit debug decision, or null to request environment-based detection.
     * @throws ConfigurationException If the callback returns anything other than bool or null.
     * @throws \Throwable If the application callback itself fails; its exception is propagated unchanged.
     */
    public function resolve(): ?bool
    {
        $value = ($this->resolver)();
        if ($value === null || is_bool($value)) {
            return $value;
        }

        throw new ConfigurationException('Debug mode resolver must return bool or null.');
    }
}
