<?php

declare(strict_types=1);

namespace GeoLocator\Http;

use Closure;
use GeoLocator\Cache\GeoCacheCodec;
use GeoLocator\Cache\GeoCacheEntry;
use GeoLocator\Config\CookieConfig;
use GeoLocator\Contract\GeoCacheStoreInterface;
use GeoLocator\Exception\CacheException;
use GeoLocator\Exception\ConfigurationException;

/**
 * Persists authenticated geolocation entries in a configured HTTP cookie.
 *
 * Cookie reads and writes are injectable for non-global contexts and tests. A
 * successful write or deletion also updates the current request's $_COOKIE array.
 * The store verifies encoded contents; expiry and IP matching belong to the policy.
 */
final class CookieGeoCacheStore implements GeoCacheStoreInterface
{
    /**
     * Reads a raw cookie value by its configured name.
     *
     * @var Closure(string): ?string
     */
    private readonly Closure $reader;

    /**
     * Writes or expires a cookie and reports whether the operation succeeded.
     *
     * @var Closure(string, string, array<string, mixed>): bool
     */
    private readonly Closure $writer;

    /**
     * Configures cookie encoding, scope, security flags and optional I/O callbacks.
     *
     * @param GeoCacheCodec $codec Codec used to authenticate and serialize cache entries.
     * @param CookieConfig $config Cookie name, scope, SameSite and HttpOnly settings.
     * @param bool $secure Resolved Secure flag for this request.
     * @param null|callable(string): ?string $reader Cookie reader; null reads string values from $_COOKIE.
     * @param null|callable(string, string, array<string, mixed>): bool $writer Writer; null delegates to setcookie().
     * @throws ConfigurationException If SameSite=None is combined with an insecure cookie.
     */
    public function __construct(
        /**
         * Serializer and authenticator for cookie contents.
         */
        private readonly GeoCacheCodec $codec,
        /**
         * Cookie scope and attributes shared by writes and deletions.
         */
        private readonly CookieConfig $config,
        /**
         * Resolved Secure flag used in every cookie operation.
         */
        private readonly bool $secure,
        ?callable $reader = null,
        ?callable $writer = null,
    ) {
        if ($config->sameSite === 'None' && !$secure) {
            throw new ConfigurationException('SameSite=None cookies require Secure=true.');
        }

        $this->reader = $reader !== null
            ? Closure::fromCallable($reader)
            : static fn (string $name): ?string => isset($_COOKIE[$name]) && is_string($_COOKIE[$name])
                ? $_COOKIE[$name]
                : null;

        $this->writer = $writer !== null
            ? Closure::fromCallable($writer)
            : static function (string $name, string $value, array $options): bool {
                /**
                 * @var array{
                 *     expires: int,
                 *     path: string,
                 *     domain?: string,
                 *     secure: bool,
                 *     httponly: bool,
                 *     samesite: 'Lax'|'Strict'|'None'
                 * } $options
                 */
                return setcookie($name, $value, $options);
            };
    }

    /**
     * Reads and authenticates the cookie, deleting it if its nonempty value is invalid.
     *
     * @return GeoCacheEntry|null Parsed entry, or null when absent, empty or rejected by the codec.
     * @throws CacheException If deleting an invalid cookie fails.
     * @throws \Throwable If an injected reader or deletion writer fails; callback exceptions are propagated.
     */
    public function read(): ?GeoCacheEntry
    {
        $raw = ($this->reader)($this->config->name);
        if ($raw === null || $raw === '') {
            return null;
        }

        $entry = $this->codec->decode($raw);
        if ($entry === null) {
            $this->delete();
        }

        return $entry;
    }

    /**
     * Encodes an entry and writes a cookie expiring at the entry's expiration time.
     *
     * The in-memory $_COOKIE value is updated only after the writer reports success.
     *
     * @param GeoCacheEntry $entry Entry to encode and send to the client.
     * @return void
     * @throws CacheException If encoding fails or the writer reports failure.
     * @throws \GeoLocator\Exception\SecurityException If the codec cannot verify its generated value.
     * @throws \Throwable If an injected writer fails; its exception is propagated unchanged.
     */
    public function write(GeoCacheEntry $entry): void
    {
        $value = $this->codec->encode($entry);
        $options = $this->options($entry->expiresAt->getTimestamp());

        if (!(($this->writer)($this->config->name, $value, $options))) {
            throw new CacheException('Unable to write GeoLocator cache cookie.');
        }

        $_COOKIE[$this->config->name] = $value;
    }

    /**
     * Expires the configured cookie and removes its current in-memory value.
     *
     * The deletion uses the same path, domain and security flags as a normal write.
     *
     * @return void
     * @throws CacheException If the cookie writer reports failure.
     * @throws \Throwable If an injected writer fails; its exception is propagated unchanged.
     */
    public function delete(): void
    {
        $options = $this->options(time() - 3600);
        if (!(($this->writer)($this->config->name, '', $options))) {
            throw new CacheException('Unable to delete GeoLocator cache cookie.');
        }

        unset($_COOKIE[$this->config->name]);
    }

    /**
     * Builds setcookie-compatible options using the configured scope and flags.
     *
     * The domain option is omitted when no nonempty domain was configured.
     *
     * @param int $expires Absolute expiration time expressed as Unix seconds.
     * @return array{
     *     expires: int,
     *     path: string,
     *     domain?: string,
     *     secure: bool,
     *     httponly: bool,
     *     samesite: 'Lax'|'Strict'|'None'
     * } Options passed to the cookie writer.
     * @throws ConfigurationException If the configured SameSite value is unsupported.
     */
    private function options(int $expires): array
    {
        $sameSite = $this->config->sameSite;
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new ConfigurationException('Cookie SameSite must be Lax, Strict or None.');
        }

        $options = [
            'expires' => $expires,
            'path' => $this->config->path,
            'secure' => $this->secure,
            'httponly' => $this->config->httpOnly,
            'samesite' => $sameSite,
        ];

        if ($this->config->domain !== null && $this->config->domain !== '') {
            $options['domain'] = $this->config->domain;
        }

        return $options;
    }
}
