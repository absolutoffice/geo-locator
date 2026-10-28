<?php

declare(strict_types=1);

namespace GeoLocator\Config;

use GeoLocator\Exception\ConfigurationException;

/**
 * Holds a validated binary master key and derives separate cache security subkeys.
 *
 * The cookie authentication key and IP-token key use distinct subkey identifiers
 * within the fixed libsodium KDF context. Changing the master key invalidates
 * previously authenticated visitor-cache entries.
 */
final class SecurityConfig
{
    /**
     * Required master-key length in bytes; the input is binary, not encoded text.
     */
    public const MASTER_KEY_BYTES = 32;
    /**
     * Eight-byte libsodium KDF context separating GeoLocator keys from other applications.
     */
    private const KDF_CONTEXT = 'GLOCSEC1';
    /**
     * KDF subkey identifier reserved for authenticating cookie payloads.
     */
    private const COOKIE_AUTH_SUBKEY_ID = 1;
    /**
     * KDF subkey identifier reserved for binding cache entries to IP addresses.
     */
    private const IP_TOKEN_SUBKEY_ID = 2;

    /**
     * Require libsodium and exactly 32 bytes of master-key material.
     *
     * @param string $masterKey Raw binary master key; use fromBase64Url() for encoded configuration values.
     *
     * @throws ConfigurationException If ext-sodium is unavailable or the key length is not exactly 32 bytes.
     */
    public function __construct(
        /**
         * Validated 32-byte binary master secret; never expose this value in diagnostics.
         */
        public readonly string $masterKey,
    ) {
        if (!extension_loaded('sodium')) {
            throw new ConfigurationException('GeoLocator requires ext-sodium.');
        }

        if (strlen($masterKey) !== self::MASTER_KEY_BYTES) {
            throw new ConfigurationException('GeoLocator master key must be exactly 32 bytes.');
        }
    }

    /**
     * Decode a Base64URL secret and validate the resulting binary master key.
     *
     * @param string $encoded Base64URL text, optionally padded and surrounded by whitespace.
     * @return self Configuration containing the decoded 32-byte secret.
     *
     * @throws ConfigurationException If the text is empty/invalid, the decoded size is wrong or sodium is missing.
     */
    public static function fromBase64Url(string $encoded): self
    {
        $encoded = trim($encoded);
        if ($encoded === '') {
            throw new ConfigurationException('GEOLOCATION_SECRET cannot be empty.');
        }

        if (preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $encoded) !== 1) {
            throw new ConfigurationException('GEOLOCATION_SECRET must be valid Base64URL.');
        }

        $unpadded = rtrim($encoded, '=');
        $padding = (4 - strlen($unpadded) % 4) % 4;
        $base64 = strtr($unpadded, '-_', '+/') . str_repeat('=', $padding);
        $decoded = base64_decode($base64, true);

        if ($decoded === false) {
            throw new ConfigurationException('GEOLOCATION_SECRET must be valid Base64URL.');
        }

        return new self($decoded);
    }

    /**
     * Derive the binary key dedicated to cookie-payload authentication.
     *
     * @return string SODIUM_CRYPTO_AUTH_KEYBYTES bytes of deterministic subkey material.
     */
    public function cookieAuthKey(): string
    {
        return $this->derive(self::COOKIE_AUTH_SUBKEY_ID);
    }

    /**
     * Derive the independent binary key dedicated to IP-token generation.
     *
     * @return string SODIUM_CRYPTO_AUTH_KEYBYTES bytes of deterministic subkey material.
     */
    public function ipTokenKey(): string
    {
        return $this->derive(self::IP_TOKEN_SUBKEY_ID);
    }

    /**
     * Derive one purpose-specific key within the fixed GeoLocator KDF context.
     *
     * @param int $subkeyId Internal purpose identifier; callers use the declared positive subkey constants.
     * @return string Binary key of SODIUM_CRYPTO_AUTH_KEYBYTES bytes.
     *
     * @throws \SodiumException If libsodium rejects the derivation parameters.
     */
    private function derive(int $subkeyId): string
    {
        return sodium_crypto_kdf_derive_from_key(
            SODIUM_CRYPTO_AUTH_KEYBYTES,
            $subkeyId,
            self::KDF_CONTEXT,
            $this->masterKey,
        );
    }
}
