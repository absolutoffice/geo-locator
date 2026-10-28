<?php

declare(strict_types=1);

namespace GeoLocator\Http;

use GeoLocator\Ip\IpAddress;
use GeoLocator\Ip\IpType;

/**
 * Infers a development environment from localhost and local host/server addresses.
 *
 * This is a configuration heuristic, not an authentication or authorization check.
 * It uses the supplied context and does not establish that host headers are trusted.
 */
final class EnvironmentDetector
{
    /**
     * Detects localhost names or private, loopback and link-local addresses.
     *
     * The request host is checked first, followed by the server address. The remote
     * client address is not used to decide whether the environment is local.
     *
     * @param HttpContext $context Host and server values for the current request.
     * @return bool True when a localhost name or qualifying local address is found.
     */
    public function isDebug(HttpContext $context): bool
    {
        $host = strtolower($this->stripPort($context->host ?? ''));
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        $hostIp = $host !== '' ? IpAddress::tryFrom($host) : null;
        if ($hostIp !== null && $this->isLocalAddress($hostIp)) {
            return true;
        }

        $serverIp = $context->serverAddress !== null ? IpAddress::tryFrom($context->serverAddress) : null;

        return $serverIp !== null && $this->isLocalAddress($serverIp);
    }

    /**
     * Checks the address categories used by the development heuristic.
     *
     * @param IpAddress $ip Parsed host or server address.
     * @return bool Whether the address is private, loopback or link-local.
     */
    private function isLocalAddress(IpAddress $ip): bool
    {
        return in_array($ip->type(), [
            IpType::PRIVATE,
            IpType::LOOPBACK,
            IpType::LINK_LOCAL,
        ], true);
    }

    /**
     * Extracts the host portion from bracketed or single-colon host text.
     *
     * This helper removes a possible port for address detection; it does not validate
     * the port or the host. Unclosed brackets are returned unchanged.
     *
     * @param string $host Host value, potentially including brackets and a port.
     * @return string Extracted host text, or the original value when no supported suffix is found.
     */
    private function stripPort(string $host): string
    {
        if ($host === '') {
            return '';
        }

        if ($host[0] === '[') {
            $end = strpos($host, ']');
            return $end === false ? $host : substr($host, 1, $end - 1);
        }

        if (substr_count($host, ':') === 1) {
            return explode(':', $host, 2)[0];
        }

        return $host;
    }
}
