<?php

declare(strict_types=1);

namespace GeoLocator\Http;

use GeoLocator\Config\CookieConfig;
use GeoLocator\Contract\DebugModeResolverInterface;

/**
 * Resolves the cookie Secure flag from explicit configuration or environment.
 *
 * In automatic mode HTTPS always enables Secure; plain HTTP permits an insecure
 * cookie only when the explicit debug resolver or environment detector selects debug.
 */
final class CookieSecureResolver
{
    /**
     * Selects the fallback environment heuristic for automatic Secure decisions.
     *
     * @param EnvironmentDetector $environmentDetector Detector used when no explicit debug decision is available.
     */
    public function __construct(
        /**
         * Fallback detector consulted only when no explicit debug decision is available.
         */
        private readonly EnvironmentDetector $environmentDetector = new EnvironmentDetector(),
    ) {
    }

    /**
     * Applies explicit Secure, then explicit debug, then environment-based detection.
     *
     * A configured Secure value is returned unchanged, even on HTTPS. In automatic
     * mode the context's HTTPS flag is used directly; forwarded scheme headers are
     * not consulted by this resolver.
     *
     * @param CookieConfig $config Explicit Secure override and other cookie settings.
     * @param HttpContext $context Request transport, host and server information.
     * @param DebugModeResolverInterface|null $debugModeResolver Optional application debug decision provider.
     * @return bool Secure flag to pass to the cookie store.
     * @throws \Throwable If the supplied debug resolver fails; its exception is propagated unchanged.
     */
    public function resolve(
        CookieConfig $config,
        HttpContext $context,
        ?DebugModeResolverInterface $debugModeResolver = null,
    ): bool {
        if ($config->secure !== null) {
            return $config->secure;
        }

        $debug = $debugModeResolver?->resolve();
        if ($debug === null) {
            $debug = $this->environmentDetector->isDebug($context);
        }

        return $context->https || !$debug;
    }
}
