<?php

/**
 * Exercise visitor resolution, trusted proxies and authenticated cookie-cache behavior.
 *
 * Uses explicit HTTP contexts and offline provider/cache fixtures to verify forwarded
 * header precedence, cache hits, IP changes and browser-cookie lifecycle behavior.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use GeoLocator\Cache\CookieAuthenticator;
use GeoLocator\Cache\GeoCacheCodec;
use GeoLocator\Cache\GeoCacheEntry;
use GeoLocator\Cache\GeoCachePolicy;
use GeoLocator\Cache\IpTokenGenerator;
use GeoLocator\Config\CacheConfig;
use GeoLocator\Config\CookieConfig;
use GeoLocator\Contract\GeoCacheStoreInterface;
use GeoLocator\Contract\GeoProviderInterface;
use GeoLocator\GeoLocator;
use GeoLocator\Http\CallableDebugModeResolver;
use GeoLocator\Http\ClientIpResolver;
use GeoLocator\Http\CookieGeoCacheStore;
use GeoLocator\Http\EnvironmentDetector;
use GeoLocator\Http\HttpContext;
use GeoLocator\Http\VisitorGeoLocator;
use GeoLocator\Ip\IpAddress;
use GeoLocator\Proxy\CloudflareProxyProvider;
use GeoLocator\Proxy\CustomProxyProvider;
use GeoLocator\Proxy\ProxyDiagnostics;
use GeoLocator\Proxy\TrustedProxyResolver;
use GeoLocator\Result\GeoLocation;
use GeoLocator\Result\ProviderResult;
use GeoLocator\Result\GeoStatus;

/**
 * Provider fixture whose call count reveals visitor-cache hits and misses.
 */
final class CountingProvider implements GeoProviderInterface
{
    /** Number of lookups delegated to this fixture. */
    public int $calls = 0;

    /**
     * Count the lookup and return the same Madrid location for every address.
     *
     * @param IpAddress $ip Validated address; its value does not affect the fixture result.
     * @return ProviderResult A successful result attributed to the test provider.
     */
    public function locate(IpAddress $ip): ProviderResult
    {
        $this->calls++;

        return ProviderResult::found(
            new GeoLocation(countryCode: 'ES', city: 'Madrid'),
            'test',
        );
    }
}

/**
 * In-memory cache fixture exposing writes and deletions for visitor assertions.
 */
final class MemoryGeoCacheStore implements GeoCacheStoreInterface
{
    /** Current cached entry, or null after deletion and before the first write. */
    public ?GeoCacheEntry $entry = null;
    /** Number of attempted writes, including replacement of an existing entry. */
    public int $writes = 0;
    /** Number of delete calls, including calls when the cache is already empty. */
    public int $deletes = 0;

    /**
     * Read the fixture entry without applying authentication or expiry policy.
     *
     * @return GeoCacheEntry|null Stored entry, or null when the fixture is empty.
     */
    public function read(): ?GeoCacheEntry
    {
        return $this->entry;
    }

    /**
     * Replace the fixture entry and record the write.
     *
     * @param GeoCacheEntry $entry Entry retained by reference for subsequent reads.
     * @return void
     */
    public function write(GeoCacheEntry $entry): void
    {
        $this->entry = $entry;
        $this->writes++;
    }

    /**
     * Empty the fixture and record the deletion attempt.
     *
     * @return void
     */
    public function delete(): void
    {
        $this->entry = null;
        $this->deletes++;
    }
}

$noTrust = new ClientIpResolver(new TrustedProxyResolver());
$spoofed = new HttpContext(
    remoteAddress: '8.8.8.8',
    serverAddress: '192.168.1.20',
    host: 'example.test',
    https: true,
    headers: ['X-Forwarded-For' => '1.1.1.1', 'CF-Connecting-IP' => '9.9.9.9'],
);
expect($noTrust->resolve($spoofed)?->value() === '8.8.8.8', 'Untrusted forwarding headers must be ignored.');

$cloudflareResolver = new ClientIpResolver(new TrustedProxyResolver([new CloudflareProxyProvider()]));
$cloudflareContext = new HttpContext(
    remoteAddress: '173.245.48.10',
    serverAddress: '192.168.1.20',
    host: 'example.test',
    https: true,
    headers: ['CF-Connecting-IP' => '8.8.4.4'],
);
expect(
    $cloudflareResolver->resolve($cloudflareContext)?->value() === '8.8.4.4',
    'Trusted Cloudflare peer must allow CF-Connecting-IP.',
);

$combinedTrusted = new TrustedProxyResolver([
    new CloudflareProxyProvider(),
    new CustomProxyProvider(['10.0.0.0/24']),
]);
$combinedResolver = new ClientIpResolver($combinedTrusted);
$customPeer = new HttpContext(
    remoteAddress: '10.0.0.10',
    serverAddress: '192.168.1.20',
    host: 'example.test',
    https: false,
    headers: [
        'CF-Connecting-IP' => '9.9.9.9',
        'X-Forwarded-For' => '8.8.8.8, 173.245.48.10',
    ],
);
expect(
    $combinedResolver->resolve($customPeer)?->value() === '8.8.8.8',
    'Custom peer must not directly trust CF-Connecting-IP; trusted chain must be walked.',
);

$forwarded = new HttpContext(
    remoteAddress: '10.0.0.10',
    serverAddress: '192.168.1.20',
    host: 'example.test',
    https: false,
    headers: ['Forwarded' => 'for=8.8.8.8;proto=https, for=173.245.48.10'],
);
expect(
    $combinedResolver->resolve($forwarded)?->value() === '8.8.8.8',
    'RFC Forwarded chain must resolve through trusted proxies.',
);

$malformedForwarding = new HttpContext(
    remoteAddress: '10.0.0.10',
    serverAddress: '192.168.1.20',
    host: 'example.test',
    https: false,
    headers: ['X-Forwarded-For' => '8.8.8.8, definitely-not-an-ip'],
);
expect(
    $combinedResolver->resolve($malformedForwarding)?->value() === '10.0.0.10',
    'Malformed forwarding chain must be ignored conservatively.',
);

$diagnostics = new ProxyDiagnostics($combinedTrusted, $combinedResolver);
$diag = $diagnostics->inspect($customPeer);
expect($diag->remoteTrusted, 'Diagnostics must report trusted immediate peer.');
expect($diag->candidateClientIp === '8.8.8.8', 'Diagnostics candidate IP mismatch.');

$untrustedDiag = (new ProxyDiagnostics(new TrustedProxyResolver(), $noTrust))->inspect($spoofed);
expect(count($untrustedDiag->warnings) === 1, 'Diagnostics must warn about ignored forwarding headers.');

$localContext = new HttpContext('192.168.1.30', '192.168.1.20', '192.168.1.20:8080', false, []);
expect(
    (new EnvironmentDetector())->isDebug($localContext),
    'Private server address should be detected as local fallback.',
);
expect(
    (new CallableDebugModeResolver(static fn (): bool => true))->resolve() === true,
    'Callable debug resolver mismatch.',
);

$provider = new CountingProvider();
$core = new GeoLocator($provider);
$secret = str_repeat('S', 32);
$ipTokenGenerator = new IpTokenGenerator($secret);
$memory = new MemoryGeoCacheStore();
$visitor = new VisitorGeoLocator(
    geoLocator: $core,
    clientIpResolver: $noTrust,
    cacheStore: $memory,
    cachePolicy: new GeoCachePolicy($ipTokenGenerator),
    ipTokenGenerator: $ipTokenGenerator,
    cacheConfig: new CacheConfig(30),
);

$visitorContext = new HttpContext('8.8.8.8', '192.168.1.20', 'example.test', true, []);
$first = $visitor->locate($visitorContext);
expect($first->status === GeoStatus::OK && !$first->cacheHit, 'First visitor lookup must come from provider.');
expect($provider->calls === 1 && $memory->writes === 1, 'First visitor lookup must write cache.');

$second = $visitor->locate($visitorContext);
expect($second->status === GeoStatus::OK && $second->cacheHit, 'Second visitor lookup must use cache.');
expect($provider->calls === 1, 'Cache hit must avoid provider lookup.');

$changedIp = new HttpContext('1.1.1.1', '192.168.1.20', 'example.test', true, []);
$third = $visitor->locate($changedIp);
expect(!$third->cacheHit && $provider->calls === 2, 'IP change must invalidate visitor cache.');
expect($memory->deletes >= 1, 'IP change must delete stale cache entry before replacement.');

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$memory->entry = new GeoCacheEntry(
    GeoCacheEntry::CURRENT_FORMAT_VERSION,
    $now->sub(new DateInterval('P3D')),
    $now->sub(new DateInterval('P1D')),
    $ipTokenGenerator->generate(IpAddress::from('1.1.1.1')),
    GeoStatus::OK,
    new GeoLocation(countryCode: 'ES'),
    'test',
);
$visitor->locate($changedIp);
expect($provider->calls === 3, 'Expired visitor cache must trigger provider lookup.');

$cookieKey = str_repeat('C', SODIUM_CRYPTO_AUTH_KEYBYTES);
$codec = new GeoCacheCodec(new CookieAuthenticator($cookieKey));
$cookieEntry = $memory->entry ?? throw new RuntimeException('Expected visitor cache entry.');
$cookieValue = $codec->encode($cookieEntry);
$cookieJar = ['myproject_geo' => $cookieValue];
$writes = [];
$cookieStore = new CookieGeoCacheStore(
    $codec,
    new CookieConfig(name: 'myproject_geo'),
    secure: false,
    reader: static fn (string $name): ?string => $cookieJar[$name] ?? null,
    writer: static function (string $name, string $value, array $options) use (&$writes): bool {
        $writes[] = [$name, $value, $options];
        return true;
    },
);
expect($cookieStore->read() !== null, 'Valid cookie cache must decode.');

[$tamperPayload, $tamperTag] = explode('.', $cookieValue, 2);
$tamperTag[0] = $tamperTag[0] === 'A' ? 'B' : 'A';
$cookieJar['myproject_geo'] = $tamperPayload . '.' . $tamperTag;
$invalidStore = new CookieGeoCacheStore(
    $codec,
    new CookieConfig(name: 'myproject_geo'),
    secure: false,
    reader: static fn (string $name): ?string => $cookieJar[$name] ?? null,
    writer: static function (string $name, string $value, array $options) use (&$writes): bool {
        $writes[] = [$name, $value, $options];
        return true;
    },
);
expect($invalidStore->read() === null, 'Manipulated cookie must become cache miss.');
expect($writes !== [] && $writes[array_key_last($writes)][1] === '', 'Manipulated cookie must be deleted.');

echo "HttpVisitorTest OK\n";
