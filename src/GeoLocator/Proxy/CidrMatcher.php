<?php

declare(strict_types=1);

namespace GeoLocator\Proxy;

use GeoLocator\Ip\IpAddress;

/**
 * Matches literal IPv4/IPv6 addresses and CIDR networks without DNS lookups.
 */
final class CidrMatcher
{
    /**
     * Checks membership in a CIDR network or equality with a literal address.
     *
     * Invalid inputs, out-of-range prefixes and different address families return
     * false. A CIDR base need not have zero host bits; only its prefix bits are compared.
     *
     * @param IpAddress|string $ip Parsed address or textual literal to test.
     * @param string $cidr Textual address or address/prefix network, with optional outer whitespace.
     * @return bool Whether the address matches the literal or belongs to the supplied network.
     */
    public static function contains(IpAddress|string $ip, string $cidr): bool
    {
        $ipValue = $ip instanceof IpAddress ? $ip->value() : trim($ip);
        $cidr = trim($cidr);

        if (!str_contains($cidr, '/')) {
            $candidate = IpAddress::tryFrom($cidr);
            $actual = IpAddress::tryFrom($ipValue);

            return $candidate !== null && $actual !== null && $candidate->value() === $actual->value();
        }

        [$network, $prefixText] = explode('/', $cidr, 2);
        if ($prefixText === '' || filter_var($prefixText, FILTER_VALIDATE_INT) === false) {
            return false;
        }

        $ipPacked = @inet_pton($ipValue);
        $networkPacked = @inet_pton(trim($network));
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

    /**
     * Checks whether text describes a literal address or an admissible CIDR prefix.
     *
     * Host bits need not be zero. Valid IPv4 prefixes range from 0 to 32 and valid IPv6
     * prefixes from 0 to 128.
     *
     * @param string $value Address or CIDR text to validate.
     * @return bool Whether the address parses and any supplied prefix fits its address family.
     */
    public static function isValidNetwork(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (!str_contains($value, '/')) {
            return IpAddress::tryFrom($value) !== null;
        }

        [$network, $prefixText] = explode('/', $value, 2);
        $ip = IpAddress::tryFrom(trim($network));
        if ($ip === null || filter_var($prefixText, FILTER_VALIDATE_INT) === false) {
            return false;
        }

        $prefix = (int) $prefixText;
        $maxBits = $ip->version() === 4 ? 32 : 128;

        return $prefix >= 0 && $prefix <= $maxBits;
    }
}
