<?php

declare(strict_types=1);

namespace GeoLocator\Cache;

use GeoLocator\Exception\ConfigurationException;

/**
 * Authenticates cache payloads with a secret sodium key.
 *
 * The authentication tag detects changes to the payload; it does not encrypt it.
 */
final class CookieAuthenticator
{
    /**
     * Validates the sodium extension and authentication key length.
     *
     * @param string $key Raw secret key of exactly SODIUM_CRYPTO_AUTH_KEYBYTES bytes.
     * @throws ConfigurationException If sodium is unavailable or the key has the wrong length.
     */
    public function __construct(
        /**
         * Raw secret used for authentication; never included in the encoded cache value.
         */
        private readonly string $key,
    ) {
        if (!extension_loaded('sodium')) {
            throw new ConfigurationException('GeoLocator requires ext-sodium.');
        }

        if (strlen($key) !== SODIUM_CRYPTO_AUTH_KEYBYTES) {
            throw new ConfigurationException(sprintf(
                'Cookie authentication key must be exactly %d bytes.',
                SODIUM_CRYPTO_AUTH_KEYBYTES,
            ));
        }
    }

    /**
     * Creates an authentication tag for the exact supplied payload bytes.
     *
     * @param string $payload Payload to authenticate, without normalizing or encoding it.
     * @return string Unpadded Base64url-encoded sodium authentication tag.
     */
    public function authenticate(string $payload): string
    {
        return self::base64UrlEncode(sodium_crypto_auth($payload, $this->key));
    }

    /**
     * Checks that the tag authenticates the exact supplied payload.
     *
     * @param string $payload Payload whose integrity is being checked.
     * @param string $encodedTag Unpadded Base64url tag returned by authenticate().
     * @return bool True only when the tag has the expected size and verifies with this key.
     */
    public function verify(string $payload, string $encodedTag): bool
    {
        $tag = self::base64UrlDecode($encodedTag);
        if ($tag === null || strlen($tag) !== SODIUM_CRYPTO_AUTH_BYTES) {
            return false;
        }

        return sodium_crypto_auth_verify($tag, $payload, $this->key);
    }

    /**
     * Encodes binary data using the unpadded URL-safe Base64 alphabet.
     *
     * @param string $value Arbitrary bytes to encode.
     * @return string Encoded bytes without trailing padding characters.
     */
    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Decodes an unpadded URL-safe Base64 value after checking its alphabet.
     *
     * @param string $value Encoded bytes; empty values and padding characters are rejected.
     * @return string|null Decoded bytes, or null for invalid alphabet or failed decoding.
     */
    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(
            strtr($value, '-_', '+/') . str_repeat('=', $padding),
            true,
        );

        return $decoded === false ? null : $decoded;
    }
}
