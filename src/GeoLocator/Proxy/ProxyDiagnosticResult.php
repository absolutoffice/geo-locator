<?php

declare(strict_types=1);

namespace GeoLocator\Proxy;

/**
 * Immutable explanation of proxy trust and client-IP selection for one request.
 *
 * Header values are diagnostic input and are not evidence that a proxy is trusted.
 */
final class ProxyDiagnosticResult
{
    /**
     * Captures the trust assessment and the resolver's selected client address.
     *
     * @param string|null $remoteAddress Original immediate-peer text from the request context.
     * @param list<string> $configuredProviders Enabled provider identifiers, in configured order.
     * @param bool $remoteTrusted Whether a valid immediate peer matches an enabled provider.
     * @param array<string, string> $forwardingHeaders Present recognized forwarding headers, including untrusted ones.
     * @param string|null $candidateClientIp Normalized address selected by the client-IP resolver, or null.
     * @param list<string> $warnings Diagnostic messages about invalid peers or ignored forwarding headers.
     */
    public function __construct(
        /**
         * Original immediate-peer text, before address validation.
         */
        public readonly ?string $remoteAddress,
        /**
         * Enabled trust-provider identifiers in configured order.
         *
         * @var list<string>
         */
        public readonly array $configuredProviders,
        /**
         * Whether the parsed immediate peer is trusted by an enabled provider.
         */
        public readonly bool $remoteTrusted,
        /**
         * Recognized forwarding headers exactly as exposed by the normalized context.
         *
         * @var array<string, string>
         */
        public readonly array $forwardingHeaders,
        /**
         * Normalized client address selected by the resolver, or null if unresolved.
         */
        public readonly ?string $candidateClientIp,
        /**
         * Diagnostic observations intended for trusted application diagnostics.
         *
         * @var list<string>
         */
        public readonly array $warnings,
    ) {
    }
}
