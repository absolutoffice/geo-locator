<?php

declare(strict_types=1);

namespace GeoLocator\Ip;

use InvalidArgumentException;

/**
 * Immutable validated IPv4/IPv6 address with normalized text and scope classification.
 *
 * Parsing trims the input and round-trips its binary form through inet_pton() and
 * inet_ntop(). Scope classification combines explicit local ranges with PHP's
 * private/reserved range filters; it is not a guarantee of network reachability.
 */
final class IpAddress
{
    /**
     * Store the normalized components produced by tryFrom().
     *
     * @param string $value Normalized textual IPv4 or IPv6 address.
     * @param IpType $type Scope category selected by the internal classifier.
     * @param int $version Address family number, either 4 or 6.
     */
    private function __construct(
        /**
         * Normalized textual address produced by inet_ntop().
         */
        private readonly string $value,
        /**
         * Scope classification used to decide whether a provider lookup is appropriate.
         */
        private readonly IpType $type,
        /**
         * IP family number: 4 for IPv4 or 6 for IPv6.
         */
        private readonly int $version,
    ) {
    }

    /**
     * Parse and classify an address while treating invalid text as an ordinary miss.
     *
     * @param string $value IPv4 or IPv6 text; surrounding whitespace is ignored.
     * @return self|null Normalized address, or null when validation or binary conversion fails.
     */
    public static function tryFrom(string $value): ?self
    {
        $value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($value);
        if ($packed === false) {
            return null;
        }

        $version = strlen($packed) === 4 ? 4 : 6;
        $normalized = inet_ntop($packed);
        if ($normalized === false) {
            return null;
        }

        return new self($normalized, self::classify($normalized), $version);
    }

    /**
     * Parse and classify an address, requiring a successful parse.
     *
     * @param string $value IPv4 or IPv6 text; surrounding whitespace is ignored.
     * @return self Validated and normalized address.
     *
     * @throws InvalidArgumentException If the input cannot be parsed as an IP address.
     */
    public static function from(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException(sprintf('Invalid IP address: %s', $value));
    }

    /**
     * Read the canonical textual representation used for lookup and IP-token generation.
     *
     * @return string Normalized IPv4 or IPv6 address without surrounding whitespace.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Read the address scope classification computed during parsing.
     *
     * @return IpType Public, private, loopback, link-local, reserved or unspecified category.
     */
    public function type(): IpType
    {
        return $this->type;
    }

    /**
     * Check whether the classifier assigned the public-IP category.
     *
     * @return bool True only for IpType::PUBLIC; this does not test connectivity or reachability.
     */
    public function isPublic(): bool
    {
        return $this->type === IpType::PUBLIC;
    }

    /**
     * Read the IP family inferred from the binary address length.
     *
     * @return int IP version number, either 4 or 6.
     */
    public function version(): int
    {
        return $this->version;
    }

    /**
     * Assign local special cases before applying PHP's public-address filters.
     *
     * @param string $ip Valid normalized IP address produced by tryFrom().
     * @return IpType First matching local category, otherwise PUBLIC or RESERVED according to PHP filtering.
     */
    private static function classify(string $ip): IpType
    {
        if ($ip === '0.0.0.0' || $ip === '::') {
            return IpType::UNSPECIFIED;
        }

        if (self::inCidr($ip, '127.0.0.0/8') || $ip === '::1') {
            return IpType::LOOPBACK;
        }

        if (
            self::inCidr($ip, '10.0.0.0/8')
            || self::inCidr($ip, '172.16.0.0/12')
            || self::inCidr($ip, '192.168.0.0/16')
            || self::inCidr($ip, 'fc00::/7')
        ) {
            return IpType::PRIVATE;
        }

        if (self::inCidr($ip, '169.254.0.0/16') || self::inCidr($ip, 'fe80::/10')) {
            return IpType::LINK_LOCAL;
        }

        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        return $public !== false ? IpType::PUBLIC : IpType::RESERVED;
    }

    /**
     * Compare an address with one of the classifier's fixed CIDR network prefixes.
     *
     * This helper expects an internal network/prefix literal and does not parse
     * arbitrary untrusted network syntax.
     *
     * @param string $ip Valid textual IP address to classify.
     * @param string $cidr Internal IPv4/IPv6 network literal including a slash and prefix length.
     * @return bool Whether the address family and significant network-prefix bits match.
     */
    private static function inCidr(string $ip, string $cidr): bool
    {
        [$network, $prefixText] = explode('/', $cidr, 2);
        $ipPacked = inet_pton($ip);
        $networkPacked = inet_pton($network);

        if ($ipPacked === false || $networkPacked === false || strlen($ipPacked) !== strlen($networkPacked)) {
            return false;
        }

        $prefix = (int) $prefixText;
        $maxBits = strlen($ipPacked) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipPacked, 0, $fullBytes) !== substr($networkPacked, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipPacked[$fullBytes]) & $mask) === (ord($networkPacked[$fullBytes]) & $mask);
    }
}
