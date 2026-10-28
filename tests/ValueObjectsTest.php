<?php

/**
 * Exercise result/cache object invariants and Base64URL security-key configuration.
 *
 * Uses fixture locations and generated key bytes; no environment secrets are required.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use GeoLocator\Cache\GeoCacheEntry;
use GeoLocator\Config\SecurityConfig;
use GeoLocator\Exception\ConfigurationException;
use GeoLocator\Result\GeoLocation;
use GeoLocator\Result\GeoStatus;
use GeoLocator\Result\ProviderResult;

$location = new GeoLocation(countryCode: 'ES', regionName: 'Madrid');
$found = ProviderResult::found($location, 'maxmind');
expect($found->location === $location, 'FOUND result must retain location.');

try {
    ProviderResult::found($location, '');
    throw new RuntimeException('Empty provider name should fail.');
} catch (LogicException) {
}

$issued = new DateTimeImmutable('2026-09-15T10:00:00+00:00');
$expires = new DateTimeImmutable('2026-10-15T10:00:00+00:00');
$entry = new GeoCacheEntry(1, $issued, $expires, 'token', GeoStatus::OK, $location, 'maxmind');
expect($entry->location === $location, 'Cache entry mismatch.');

$key = random_bytes(32);
$encoded = rtrim(strtr(base64_encode($key), '+/', '-_'), '=');
$config = SecurityConfig::fromBase64Url($encoded);
expect(hash_equals($key, $config->masterKey), 'Base64URL secret roundtrip failed.');

try {
    SecurityConfig::fromBase64Url('bad');
    throw new RuntimeException('Short secret should fail.');
} catch (ConfigurationException) {
}

echo "ValueObjectsTest OK\n";
