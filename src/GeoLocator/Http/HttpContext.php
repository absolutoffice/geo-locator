<?php

declare(strict_types=1);

namespace GeoLocator\Http;

/**
 * Immutable request snapshot with normalized, case-insensitive header names.
 *
 * Values are collected without assigning trust to forwarding headers. Proxy trust
 * and client-address resolution are separate responsibilities.
 */
final class HttpContext
{
    /**
     * Trimmed header values indexed by lowercase, trimmed names.
     *
     * @var array<string, string>
     */
    public readonly array $headers;

    /**
     * Captures transport context and trims header names and values.
     *
     * Header names are lowercased; if multiple input keys normalize to one name, the
     * last supplied value wins. Address strings are retained without validation.
     *
     * @param string|null $remoteAddress Immediate peer address, normally REMOTE_ADDR.
     * @param string|null $serverAddress Address serving the request, normally SERVER_ADDR.
     * @param string|null $host Host text, optionally including a port.
     * @param bool $https Whether the locally observed request transport is HTTPS.
     * @param array<string, string> $headers Incoming header names and values.
     */
    public function __construct(
        /**
         * Unvalidated immediate peer address, or null when unavailable.
         */
        public readonly ?string $remoteAddress,
        /**
         * Unvalidated server address used by environment detection.
         */
        public readonly ?string $serverAddress,
        /**
         * Request host value used by environment detection, possibly with a port.
         */
        public readonly ?string $host,
        /**
         * Locally observed HTTPS state, independent of forwarded scheme headers.
         */
        public readonly bool $https,
        array $headers = [],
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower(trim($name))] = trim($value);
        }
        $this->headers = $normalized;
    }

    /**
     * Builds a request snapshot from the current $_SERVER array.
     *
     * Only entries with string keys are forwarded to fromServerArray().
     *
     * @return self Context reconstructed from the current request's server variables.
     */
    public static function fromGlobals(): self
    {
        $server = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key)) {
                $server[$key] = $value;
            }
        }

        return self::fromServerArray($server);
    }

    /**
     * Builds a context from server-style variables and HTTP_ header entries.
     *
     * Scalar HTTP_ values, CONTENT_TYPE and CONTENT_LENGTH become headers. HTTPS is
     * true for an on/1/true HTTPS value or port 443. Forwarded scheme headers do not
     * alter that decision. Non-scalar values are ignored by the conversion helpers.
     *
     * @param array<string, mixed> $server Server variables, usually copied from $_SERVER.
     * @return self Context with normalized headers and nullable server fields.
     */
    public static function fromServerArray(array $server): self
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = (string) $value;
                continue;
            }

            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[str_replace('_', '-', $key)] = (string) $value;
            }
        }

        $httpsValue = strtolower(self::nullableScalar($server['HTTPS'] ?? null) ?? '');
        $https = in_array($httpsValue, ['on', '1', 'true'], true)
            || self::nullablePort($server['SERVER_PORT'] ?? null) === 443;

        return new self(
            remoteAddress: self::nullableScalar($server['REMOTE_ADDR'] ?? null),
            serverAddress: self::nullableScalar($server['SERVER_ADDR'] ?? null),
            host: self::nullableScalar($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? null),
            https: $https,
            headers: $headers,
        );
    }

    /**
     * Retrieves a header using a trimmed, case-insensitive name.
     *
     * @param string $name Header name to look up.
     * @return string|null Trimmed header value, or null when absent.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower(trim($name))] ?? null;
    }

    /**
     * Converts a scalar value to a trimmed, nonempty string.
     *
     * @param mixed $value Raw server variable.
     * @return string|null Normalized string, or null for empty or non-scalar input.
     */
    private static function nullableScalar(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Extracts an integer port value without enforcing a network-port range.
     *
     * @param mixed $value Raw SERVER_PORT value.
     * @return int|null Existing integer or trimmed decimal string converted to int; null otherwise.
     */
    private static function nullablePort(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || strspn($value, '0123456789') !== strlen($value)) {
            return null;
        }

        return (int) $value;
    }
}
