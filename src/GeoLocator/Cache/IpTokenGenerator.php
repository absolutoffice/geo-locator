<?php

declare(strict_types=1);

namespace GeoLocator\Cache;

use GeoLocator\Exception\ConfigurationException;
use GeoLocator\Ip\IpAddress;

/**
 * Creates keyed tokens that bind a cache entry to a normalized IP address.
 *
 * Tokens avoid storing the clear-text address in the entry. They are deterministic
 * for a given address and key, and are authentication tags rather than encryption.
 */
final class IpTokenGenerator
{
    /**
     * Validates the sodium extension and secret used to bind IP addresses.
     *
     * @param string $key Raw secret key of exactly SODIUM_CRYPTO_AUTH_KEYBYTES bytes.
     * @throws ConfigurationException If sodium is unavailable or the key has the wrong length.
     */
    public function __construct(
        /**
         * Raw secret used to authenticate normalized IP address strings.
         */
        private readonly string $key,
    ) {
        if (!extension_loaded('sodium')) {
            throw new ConfigurationException('GeoLocator requires ext-sodium.');
        }

        if (strlen($key) !== SODIUM_CRYPTO_AUTH_KEYBYTES) {
            throw new ConfigurationException(sprintf(
                'IP token key must be exactly %d bytes.',
                SODIUM_CRYPTO_AUTH_KEYBYTES,
            ));
        }
    }

    /**
     * Authenticates the normalized textual representation of an IP address.
     *
     * @param IpAddress $ip Address to bind to a cache entry.
     * @return string Unpadded Base64url-encoded authentication token.
     */
    public function generate(IpAddress $ip): string
    {
        return self::base64UrlEncode(sodium_crypto_auth($ip->value(), $this->key));
    }

    /**
     * Verifies that a token was generated for the supplied normalized address.
     *
     * @param IpAddress $ip Current client address to compare with the cached binding.
     * @param string $encodedToken Previously encoded IP authentication token.
     * @return bool False for malformed tokens, different addresses or a different signing key.
     */
    public function matches(IpAddress $ip, string $encodedToken): bool
    {
        $token = self::base64UrlDecode($encodedToken);
        if ($token === null || strlen($token) !== SODIUM_CRYPTO_AUTH_BYTES) {
            return false;
        }

        return sodium_crypto_auth_verify($token, $ip->value(), $this->key);
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
