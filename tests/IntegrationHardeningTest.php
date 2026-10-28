<?php

/**
 * Exercise integration choices made by the context-aware visitor factory.
 *
 * Checks explicit debug callbacks, local-environment fallback, cookie Secure rules,
 * SameSite validation and proxy diagnostics with synthetic request contexts.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use GeoLocator\Config\CookieConfig;
use GeoLocator\Config\GeoLocatorConfig;
use GeoLocator\Config\MaxMindConfig;
use GeoLocator\Config\ProxyConfig;
use GeoLocator\Config\SecurityConfig;
use GeoLocator\Exception\ConfigurationException;
use GeoLocator\GeoLocatorFactory;
use GeoLocator\Http\CallableDebugModeResolver;
use GeoLocator\Http\CookieSecureResolver;
use GeoLocator\Http\HttpContext;
use GeoLocator\Http\VisitorGeoLocator;
use GeoLocator\Proxy\TrustedProxyProvider;

$secureResolver = new CookieSecureResolver();
$autoCookie = new CookieConfig();
$debugTrue = new CallableDebugModeResolver(static fn (): bool => true);
$production = new CallableDebugModeResolver(static fn (): bool => false);
$unknown = new CallableDebugModeResolver(static fn (): ?bool => null);

$httpLocal = new HttpContext(
    remoteAddress: '192.168.1.30',
    serverAddress: '192.168.1.20',
    host: 'dev.localhost',
    https: false,
);
$httpsLocal = new HttpContext(
    remoteAddress: '192.168.1.30',
    serverAddress: '192.168.1.20',
    host: 'dev.localhost',
    https: true,
);
$httpProduction = new HttpContext(
    remoteAddress: '8.8.8.8',
    serverAddress: '203.0.113.10',
    host: 'example.test',
    https: false,
);

$httpLoopbackHostWithoutServerAddress = new HttpContext(
    remoteAddress: '127.0.0.1',
    serverAddress: null,
    host: '127.0.0.1:8080',
    https: false,
);
$httpPrivateHostWithoutServerAddress = new HttpContext(
    remoteAddress: '192.168.1.30',
    serverAddress: null,
    host: '192.168.1.20:8080',
    https: false,
);

expect(!$secureResolver->resolve($autoCookie, $httpLocal, $debugTrue), 'Debug HTTP cookie should not be Secure.');
expect(
    $secureResolver->resolve($autoCookie, $httpsLocal, $debugTrue),
    'HTTPS cookie must be Secure even in debug mode.',
);
expect(
    $secureResolver->resolve($autoCookie, $httpLocal, $production),
    'Production auto mode must resolve Secure=true.',
);
expect(
    !$secureResolver->resolve($autoCookie, $httpLocal, $unknown),
    'Unknown mode must use local environment fallback.',
);
expect(
    !$secureResolver->resolve($autoCookie, $httpLoopbackHostWithoutServerAddress, $unknown),
    'Loopback host must be detected as debug when SERVER_ADDR is missing.',
);
expect(
    !$secureResolver->resolve($autoCookie, $httpPrivateHostWithoutServerAddress, $unknown),
    'Private host must be detected as debug when SERVER_ADDR is missing.',
);
expect(
    $secureResolver->resolve($autoCookie, $httpProduction, $unknown),
    'Unknown public environment must fail closed to Secure=true.',
);
expect(
    !$secureResolver->resolve(new CookieConfig(secure: false), $httpsLocal, $production),
    'Explicit Secure=false must override auto resolution.',
);
expect(
    $secureResolver->resolve(new CookieConfig(secure: true), $httpLocal, $debugTrue),
    'Explicit Secure=true must override auto resolution.',
);

$config = new GeoLocatorConfig(
    security: new SecurityConfig(str_repeat('I', 32)),
    maxMind: new MaxMindConfig('/tmp/nonexistent-geolocator-test.mmdb'),
    cookie: new CookieConfig(sameSite: 'None'),
    proxy: new ProxyConfig(
        provider: TrustedProxyProvider::CLOUDFLARE,
        customTrustedProxies: ['10.0.0.0/24'],
    ),
);

try {
    GeoLocatorFactory::createVisitorForContext($config, $httpLocal, static fn (): bool => true);
    throw new RuntimeException('SameSite=None over debug HTTP should fail because Secure resolves false.');
} catch (ConfigurationException) {
}

$httpsVisitor = GeoLocatorFactory::createVisitorForContext(
    $config,
    $httpsLocal,
    static fn (): bool => true,
);

$diagnostics = GeoLocatorFactory::createProxyDiagnostics($config);
$diagnosticContext = new HttpContext(
    remoteAddress: '10.0.0.10',
    serverAddress: '192.168.1.20',
    host: 'example.test',
    https: true,
    headers: ['X-Forwarded-For' => '8.8.8.8, 173.245.48.10'],
);
$diagnosticResult = $diagnostics->inspect($diagnosticContext);
expect($diagnosticResult->remoteTrusted, 'Factory proxy diagnostics must use configured custom trusted proxies.');
expect(
    $diagnosticResult->candidateClientIp === '8.8.8.8',
    'Factory proxy diagnostics must walk the configured trusted chain.',
);
expect(
    in_array('cloudflare', $diagnosticResult->configuredProviders, true),
    'Diagnostics must expose Cloudflare provider.',
);
expect(in_array('custom', $diagnosticResult->configuredProviders, true), 'Diagnostics must expose custom provider.');

echo "IntegrationHardeningTest OK\n";
