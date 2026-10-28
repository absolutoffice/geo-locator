<?php

/**
 * Exercise authenticated cookie serialization and the cache validity policy.
 *
 * Covers tamper rejection, key separation, format/field validation, expiry and IP
 * binding using generated test keys and deterministic timestamps. No network is used.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use GeoLocator\Cache\CookieAuthenticator;
use GeoLocator\Cache\GeoCacheCodec;
use GeoLocator\Cache\GeoCacheEntry;
use GeoLocator\Cache\GeoCachePolicy;
use GeoLocator\Cache\IpTokenGenerator;
use GeoLocator\Config\SecurityConfig;
use GeoLocator\Ip\IpAddress;
use GeoLocator\Result\GeoLocation;
use GeoLocator\Result\GeoStatus;

$security = new SecurityConfig(random_bytes(SecurityConfig::MASTER_KEY_BYTES));
$authenticator = new CookieAuthenticator($security->cookieAuthKey());
$tokens = new IpTokenGenerator($security->ipTokenKey());
$policy = new GeoCachePolicy($tokens);
$codec = new GeoCacheCodec($authenticator);

$payload = 'signed payload';
$tag = $authenticator->authenticate($payload);
expect($authenticator->verify($payload, $tag), 'Valid Sodium auth tag must verify.');
expect(!$authenticator->verify($payload . 'x', $tag), 'Modified payload must not verify.');
expect(!$authenticator->verify($payload, 'invalid-tag'), 'Malformed auth tag must not verify.');

$ip = IpAddress::from('8.8.8.8');
$otherIp = IpAddress::from('1.1.1.1');
$ipToken = $tokens->generate($ip);
expect($tokens->matches($ip, $ipToken), 'IP token must match its source IP.');
expect(!$tokens->matches($otherIp, $ipToken), 'IP token must not match a different IP.');
expect(hash_equals($ipToken, $tokens->generate($ip)), 'IP token must be deterministic.');
expect(!str_contains($ipToken, $ip->value()), 'IP token must not expose the IP in clear text.');

$issuedAt = new DateTimeImmutable('2026-09-15T20:00:00+00:00');
$expiresAt = new DateTimeImmutable('2026-10-15T20:00:00+00:00');
$location = new GeoLocation(
    countryCode: 'ES',
    regionCode: 'M',
    regionName: 'Madrid',
    city: 'Madrid',
    postalCode: '28001',
    latitude: 40.4168,
    longitude: -3.7038,
    accuracyRadiusKm: 20,
    timezone: 'Europe/Madrid',
);

$entry = new GeoCacheEntry(
    formatVersion: GeoCacheEntry::CURRENT_FORMAT_VERSION,
    issuedAt: $issuedAt,
    expiresAt: $expiresAt,
    ipToken: $ipToken,
    status: GeoStatus::OK,
    location: $location,
    providerName: 'maxmind',
);

$encoded = $codec->encode($entry);
expect(!str_contains($encoded, '8.8.8.8'), 'Encoded cache must not contain the IP in clear text.');
$decoded = $codec->decode($encoded);
if ($decoded === null) {
    throw new RuntimeException('Encoded cache entry must decode.');
}
expect($decoded->status === GeoStatus::OK, 'Decoded status mismatch.');
expect($decoded->location?->countryCode === 'ES', 'Decoded country mismatch.');
expect($decoded->location?->latitude === 40.4168, 'Decoded latitude mismatch.');
expect($decoded->providerName === 'maxmind', 'Decoded provider mismatch.');
expect($decoded->issuedAt->getTimestamp() === $issuedAt->getTimestamp(), 'Decoded issue time mismatch.');

expect(
    $policy->isValid($decoded, $ip, new DateTimeImmutable('2026-09-20T00:00:00+00:00')),
    'Fresh cache with same IP must be valid.',
);
expect(
    !$policy->isValid($decoded, $otherIp, new DateTimeImmutable('2026-09-20T00:00:00+00:00')),
    'Cache must be invalidated when IP changes.',
);
expect(
    !$policy->isValid($decoded, $ip, $expiresAt),
    'Cache must expire exactly at expiresAt.',
);
expect(
    !$policy->isValid($decoded, $ip, new DateTimeImmutable('2026-09-15T19:59:59+00:00')),
    'Cache issued in the future must be invalid.',
);

$notFound = new GeoCacheEntry(
    formatVersion: GeoCacheEntry::CURRENT_FORMAT_VERSION,
    issuedAt: $issuedAt,
    expiresAt: $expiresAt,
    ipToken: $ipToken,
    status: GeoStatus::NOT_FOUND,
    location: null,
    providerName: 'maxmind',
);
$decodedNotFound = $codec->decode($codec->encode($notFound));
if ($decodedNotFound === null) {
    throw new RuntimeException('NOT_FOUND cache entry must round-trip.');
}
expect($decodedNotFound->status === GeoStatus::NOT_FOUND, 'NOT_FOUND status mismatch.');
expect($decodedNotFound->location === null, 'NOT_FOUND cache entry must not contain location.');

$nonPublic = new GeoCacheEntry(
    formatVersion: GeoCacheEntry::CURRENT_FORMAT_VERSION,
    issuedAt: $issuedAt,
    expiresAt: $expiresAt,
    ipToken: $tokens->generate(IpAddress::from('192.168.1.10')),
    status: GeoStatus::NON_PUBLIC_IP,
    location: null,
    providerName: null,
);
expect(
    $codec->decode($codec->encode($nonPublic))?->status === GeoStatus::NON_PUBLIC_IP,
    'NON_PUBLIC_IP must round-trip.',
);

$tampered = $encoded;
$position = intdiv(strlen($tampered), 3);
$tampered[$position] = $tampered[$position] === 'A' ? 'B' : 'A';
expect($codec->decode($tampered) === null, 'Manipulated cache value must be rejected without exception.');
expect($codec->decode('not-a-valid-cookie') === null, 'Malformed cache value must be rejected without exception.');

[$payloadPart, $tagPart] = explode('.', $encoded, 2);
$unknownVersionData = [
    'v' => 99,
    'iat' => $issuedAt->getTimestamp(),
    'exp' => $expiresAt->getTimestamp(),
    'ip' => $ipToken,
    'status' => 'ok',
    'provider' => 'maxmind',
    'geo' => ['countryCode' => 'ES'],
];
$unknownJson = json_encode($unknownVersionData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
$unknownPayload = rtrim(strtr(base64_encode($unknownJson), '+/', '-_'), '=');
$unknownSigned = $unknownPayload . '.' . $authenticator->authenticate($unknownPayload);
expect($codec->decode($unknownSigned) === null, 'Unknown cache format version must be treated as cache miss.');
expect($payloadPart !== '' && $tagPart !== '', 'Cookie encoding must contain payload and auth tag.');

try {
    new GeoCacheEntry(
        formatVersion: 1,
        issuedAt: $issuedAt,
        expiresAt: $expiresAt,
        ipToken: $ipToken,
        status: GeoStatus::INVALID_IP,
        location: null,
        providerName: null,
    );
    throw new RuntimeException('INVALID_IP cache entry should fail.');
} catch (InvalidArgumentException) {
}

echo "CacheSecurityTest OK\n";
