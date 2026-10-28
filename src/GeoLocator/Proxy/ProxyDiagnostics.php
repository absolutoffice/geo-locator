<?php

declare(strict_types=1);

namespace GeoLocator\Proxy;

use GeoLocator\Http\ClientIpResolver;
use GeoLocator\Http\HttpContext;
use GeoLocator\Ip\IpAddress;

/**
 * Explains proxy trust and forwarding-header handling without changing the request.
 */
final class ProxyDiagnostics
{
    /**
     * Selects the trust configuration and client-IP algorithm to inspect.
     *
     * @param TrustedProxyResolver $trustedProxies Providers used to determine immediate-peer trust.
     * @param ClientIpResolver $clientIpResolver Resolver whose selected client address will be reported.
     */
    public function __construct(
        /**
         * Configured providers used to evaluate and label immediate-peer trust.
         */
        private readonly TrustedProxyResolver $trustedProxies,
        /**
         * Resolver used to report the same candidate IP as the visitor workflow.
         */
        private readonly ClientIpResolver $clientIpResolver,
    ) {
    }

    /**
     * Collects forwarding headers, peer trust, selected IP and diagnostic warnings.
     *
     * Raw forwarding values are reported even when the peer is untrusted. The selected
     * client address comes from ClientIpResolver and therefore applies its trust rules.
     * This method does not detect every malformed forwarding header.
     *
     * @param HttpContext $context Request snapshot to inspect.
     * @return ProxyDiagnosticResult Trust decision and observations for the supplied context.
     */
    public function inspect(HttpContext $context): ProxyDiagnosticResult
    {
        $remote = $context->remoteAddress !== null ? IpAddress::tryFrom($context->remoteAddress) : null;
        $remoteTrusted = $remote !== null && $this->trustedProxies->isTrusted($remote);

        $headers = [];
        foreach (['CF-Connecting-IP', 'X-Forwarded-For', 'Forwarded'] as $name) {
            $value = $context->header($name);
            if ($value !== null) {
                $headers[$name] = $value;
            }
        }

        $warnings = [];
        if ($headers !== [] && !$remoteTrusted) {
            $warnings[] = 'Forwarding headers are present but REMOTE_ADDR is not a trusted proxy; '
                . 'headers will be ignored.';
        }
        if ($context->remoteAddress !== null && $remote === null) {
            $warnings[] = 'REMOTE_ADDR is not a valid IP address.';
        }

        return new ProxyDiagnosticResult(
            remoteAddress: $context->remoteAddress,
            configuredProviders: $this->trustedProxies->providerNames(),
            remoteTrusted: $remoteTrusted,
            forwardingHeaders: $headers,
            candidateClientIp: $this->clientIpResolver->resolve($context)?->value(),
            warnings: $warnings,
        );
    }
}
