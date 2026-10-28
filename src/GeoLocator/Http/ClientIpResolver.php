<?php

declare(strict_types=1);

namespace GeoLocator\Http;

use GeoLocator\Ip\IpAddress;
use GeoLocator\Proxy\TrustedProxyResolver;

/**
 * Resolves a visitor IP using forwarding headers only from trusted peers.
 *
 * The immediate peer must be a valid IP. Cloudflare's dedicated header is considered
 * only for a peer trusted by the provider named cloudflare; otherwise forwarded
 * chains are evaluated from the peer backwards. Returned addresses may be non-public.
 */
final class ClientIpResolver
{
    /**
     * Selects the trust rules applied to the peer and each forwarded hop.
     *
     * @param TrustedProxyResolver $trustedProxies Configured proxy providers and their trusted networks.
     */
    public function __construct(
        /**
         * Proxy trust rules used before accepting any forwarded client address.
         */
        private readonly TrustedProxyResolver $trustedProxies,
    ) {
    }

    /**
     * Resolves the request's client address according to the configured proxy trust.
     *
     * An untrusted peer's headers are ignored. For a trusted peer, a usable Cloudflare
     * header takes precedence, followed by X-Forwarded-For. Forwarded is considered
     * only when X-Forwarded-For is absent, not when that header contains an invalid
     * chain. The rightmost untrusted hop is selected; if every hop is trusted, the
     * first is used. Missing or rejected chains fall back to the immediate peer.
     *
     * @param HttpContext $context Request peer and untrusted incoming header values.
     * @return IpAddress|null Resolved address, or null when the immediate peer is absent or invalid.
     */
    public function resolve(HttpContext $context): ?IpAddress
    {
        $remote = $context->remoteAddress !== null ? IpAddress::tryFrom($context->remoteAddress) : null;
        if ($remote === null) {
            return null;
        }

        if (!$this->trustedProxies->isTrusted($remote)) {
            return $remote;
        }

        if ($this->trustedProxies->isTrustedBy('cloudflare', $remote)) {
            $cloudflareIp = $this->singleIpHeader($context->header('CF-Connecting-IP'));
            if ($cloudflareIp !== null) {
                return $cloudflareIp;
            }
        }

        $forwardedChain = $this->forwardedForChain($context);
        if ($forwardedChain === []) {
            return $remote;
        }

        $chain = [...$forwardedChain, $remote];
        for ($index = count($chain) - 1; $index >= 0; $index--) {
            $hop = $chain[$index];
            if (!$this->trustedProxies->isTrusted($hop)) {
                return $hop;
            }
        }

        return $chain[0];
    }

    /**
     * Parses a forwarding chain in its original client-to-proxy order.
     *
     * X-Forwarded-For takes precedence whenever present; an invalid value does not fall
     * back to Forwarded. A malformed parsed hop rejects the entire selected chain.
     * Forwarded elements without a for parameter contribute no hop.
     *
     * @param HttpContext $context Request headers to inspect after the peer has been trusted.
     * @return list<IpAddress> Parsed hop list, or an empty list for missing or rejected data.
     */
    private function forwardedForChain(HttpContext $context): array
    {
        $xff = $context->header('X-Forwarded-For');
        if ($xff !== null) {
            $result = [];
            foreach (explode(',', $xff) as $part) {
                $ip = $this->parseForwardedIp($part);
                if ($ip === null) {
                    return [];
                }
                $result[] = $ip;
            }
            return $result;
        }

        $forwarded = $context->header('Forwarded');
        if ($forwarded === null) {
            return [];
        }

        $result = [];
        foreach (explode(',', $forwarded) as $element) {
            foreach (explode(';', $element) as $parameter) {
                $pair = explode('=', trim($parameter), 2);
                if (count($pair) !== 2) {
                    continue;
                }
                [$name, $value] = $pair;
                if (strtolower(trim($name)) !== 'for') {
                    continue;
                }
                $ip = $this->parseForwardedIp(trim($value, " \t\n\r\0\x0B\""));
                if ($ip === null) {
                    return [];
                }
                $result[] = $ip;
                break;
            }
        }

        return $result;
    }

    /**
     * Parses a dedicated single-address header and rejects comma-separated lists.
     *
     * @param string|null $value Header value, or null when the header is absent.
     * @return IpAddress|null Parsed address, or null for absent, multiple or invalid addresses.
     */
    private function singleIpHeader(?string $value): ?IpAddress
    {
        if ($value === null || str_contains($value, ',')) {
            return null;
        }

        return $this->parseForwardedIp($value);
    }

    /**
     * Parses a literal IP, bracketed address or address followed by a numeric port.
     *
     * Leading and trailing whitespace is removed. Obfuscated identifiers beginning with
     * an underscore are rejected. For bracketed values only text inside the first
     * closing bracket is parsed; trailing text is not interpreted as part of the IP.
     *
     * @param string $value One address value extracted from a forwarding header.
     * @return IpAddress|null Normalized address, or null when the parsed address is invalid.
     */
    private function parseForwardedIp(string $value): ?IpAddress
    {
        $value = trim($value);
        if ($value === '' || $value[0] === '_') {
            return null;
        }

        if ($value[0] === '[') {
            $end = strpos($value, ']');
            if ($end === false) {
                return null;
            }
            return IpAddress::tryFrom(substr($value, 1, $end - 1));
        }

        $direct = IpAddress::tryFrom($value);
        if ($direct !== null) {
            return $direct;
        }

        if (substr_count($value, ':') === 1) {
            [$candidate, $port] = explode(':', $value, 2);
            if ($port !== '' && ctype_digit($port)) {
                return IpAddress::tryFrom($candidate);
            }
        }

        return null;
    }
}
