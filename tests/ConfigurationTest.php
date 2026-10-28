<?php

/**
 * Exercise environment parsing, explicit configuration precedence and factory wiring.
 *
 * Builds disposable package/private-directory fixtures and checks invalid values,
 * path resolution, read-only configuration loading and missing optional dependencies.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use GeoLocator\Config\EnvLoader;
use GeoLocator\Config\PackagePathResolver;
use GeoLocator\Config\SecurityConfig;
use GeoLocator\Exception\ConfigurationException;
use GeoLocator\GeoLocator;
use GeoLocator\GeoLocatorFactory;
use GeoLocator\Http\VisitorGeoLocator;
use GeoLocator\Result\GeoStatus;
use GeoLocator\Update\MaxMindUpdater;
use GeoLocator\Proxy\TrustedProxyProvider;

$tempRoot = sys_get_temp_dir() . '/geolocator-config-' . bin2hex(random_bytes(6));
$packageRoot = $tempRoot . '/myProject/lib/GeoLocator';
$alternatePackageRoot = $tempRoot . '/myProject/GeoLocator';
$privateDir = $tempRoot . '/.private/GeoLocator';
mkdir($packageRoot, 0777, true);
mkdir($alternatePackageRoot, 0777, true);
mkdir($privateDir, 0777, true);

$packagePathResolver = new PackagePathResolver($packageRoot);
expect(
    $packagePathResolver->resolve('db/MaxMind/GeoLite2-City.mmdb') === $packageRoot . '/db/MaxMind/GeoLite2-City.mmdb',
    'Relative package path resolution mismatch for ./lib/GeoLocator.',
);
expect(
    (new PackagePathResolver($alternatePackageRoot))->resolve('db/MaxMind/GeoLite2-City.mmdb')
        === $alternatePackageRoot . '/db/MaxMind/GeoLite2-City.mmdb',
    'Relative package path resolution mismatch for ./GeoLocator.',
);
expect(
    $packagePathResolver->resolve('/srv/geo/GeoLite2-City.mmdb') === '/srv/geo/GeoLite2-City.mmdb',
    'Absolute package path must remain unchanged.',
);

$envFile = $privateDir . '/.env';

$secretFile = str_repeat('A', 32);
$secretFileEncoded = rtrim(strtr(base64_encode($secretFile), '+/', '-_'), '=');
$envContents = <<<ENV
# GeoLocator config
GEOLOCATION_SECRET={$secretFileEncoded}
GEOLOCATION_COOKIE_NAME="myproject_geo"
GEOLOCATION_CACHE_TTL_DAYS=45
GEOLOCATION_COOKIE_SECURE=false
GEOLOCATION_COOKIE_HTTPONLY=true
GEOLOCATION_COOKIE_SAMESITE=Strict
GEOLOCATION_COOKIE_PATH=/app
GEOLOCATION_COOKIE_DOMAIN=example.test
GEOLOCATION_TRUSTED_PROXY_PROVIDER=cloudflare
GEOLOCATION_TRUSTED_PROXIES=10.0.0.1, 192.168.1.0/24
MAXMIND_DATABASE_PATH=db/MaxMind/GeoLite2-City.mmdb
MAXMIND_PREFERRED_LOCALES=es,en
MAXMIND_ACCOUNT_ID='12345'
MAXMIND_LICENSE_KEY=test-key # comment
MAXMIND_UPDATE_INTERVAL_DAYS=3
MAXMIND_HTTP_TIMEOUT_SECONDS=20
MAXMIND_HTTP_MAX_ATTEMPTS=2
ENV;
file_put_contents($envFile, $envContents);

try {
    GeoLocatorFactory::configFromEnvironment();
    throw new RuntimeException('Factory must not auto-discover a project .env file.');
} catch (ConfigurationException $exception) {
    expect(
        str_contains($exception->getMessage(), 'GEOLOCATION_SECRET is required'),
        'Missing explicit .env must fall back only to process environment.',
    );
}

$loaded = EnvLoader::load($envFile);
expect($loaded['GEOLOCATION_COOKIE_NAME'] === 'myproject_geo', 'Quoted .env value mismatch.');
expect($loaded['MAXMIND_ACCOUNT_ID'] === '12345', 'Single quoted .env value mismatch.');
expect($loaded['MAXMIND_LICENSE_KEY'] === 'test-key', 'Inline comment parsing mismatch.');

$config = GeoLocatorFactory::configFromEnvironment($envFile);
expect($config->cookie->name === 'myproject_geo', 'Factory cookie name mismatch.');
expect($config->cache->ttlDays === 45, 'Factory cache TTL mismatch.');
expect($config->cookie->secure === false, 'Factory cookie Secure override mismatch.');
expect($config->cookie->httpOnly === true, 'Factory cookie HttpOnly override mismatch.');
expect($config->cookie->sameSite === 'Strict', 'Factory cookie SameSite override mismatch.');
expect($config->cookie->path === '/app', 'Factory cookie path override mismatch.');
expect($config->cookie->domain === 'example.test', 'Factory cookie domain override mismatch.');
expect($config->proxy->provider === TrustedProxyProvider::CLOUDFLARE, 'Factory proxy provider mismatch.');
expect($config->proxy->customTrustedProxies === ['10.0.0.1', '192.168.1.0/24'], 'Factory proxy list mismatch.');
expect(
    $config->maxMind->databasePath === dirname(__DIR__) . '/db/MaxMind/GeoLite2-City.mmdb',
    'Relative MaxMind database path must resolve from the GeoLocator project root.',
);
expect($config->maxMind->preferredLocales === ['es', 'en'], 'Factory locales mismatch.');
expect($config->update->intervalDays === 3, 'Factory update interval mismatch.');
expect($config->update->httpTimeoutSeconds === 20, 'Factory HTTP timeout mismatch.');
expect($config->update->httpMaxAttempts === 2, 'Factory HTTP attempts mismatch.');
expect(
    $config->update->stateDirectory === dirname(__DIR__) . '/db/MaxMind/.state',
    'Factory state directory must default beside the writable MMDB, not beside the private .env.',
);
if (class_exists(\AbsolutOffice\XCurl\HttpTransportFactory::class)) {
    GeoLocatorFactory::createUpdater($config);
} else {
    try {
        GeoLocatorFactory::createUpdater($config);
        throw new RuntimeException('Missing xCurl dependency must fail with a configuration error.');
    } catch (ConfigurationException $exception) {
        expect(
            str_contains($exception->getMessage(), 'composer install'),
            'Missing xCurl must explain how to install the dependency.',
        );
    }
}

expect(
    !is_dir($privateDir . '/state'),
    'Creating configuration/updater must never create runtime state beside the private .env.',
);

putenv('MAXMIND_STATE_DIR=runtime/maxmind-state');
$configWithStateOverride = GeoLocatorFactory::configFromEnvironment($envFile);
expect(
    $configWithStateOverride->update->stateDirectory === dirname(__DIR__) . '/runtime/maxmind-state',
    'Relative MAXMIND_STATE_DIR must resolve from the GeoLocator package root.',
);
putenv('MAXMIND_STATE_DIR');

putenv('MAXMIND_STATE_DIRECTORY=legacy-state');
$configWithLegacyStateOverride = GeoLocatorFactory::configFromEnvironment($envFile);
expect(
    $configWithLegacyStateOverride->update->stateDirectory === dirname(__DIR__) . '/legacy-state',
    'Legacy MAXMIND_STATE_DIRECTORY alias must remain supported during pre-1.0 migration.',
);
putenv('MAXMIND_STATE_DIRECTORY');

$_SERVER['REMOTE_ADDR'] = '192.168.1.30';
$_SERVER['SERVER_ADDR'] = '192.168.1.20';
$_SERVER['HTTP_HOST'] = '192.168.1.20';
$visitorFromFactory = GeoLocatorFactory::visitorFromEnvironment(
    $envFile,
    static fn (): bool => true,
);
expect(
    $visitorFromFactory->locateFromGlobals()->status === GeoStatus::NON_PUBLIC_IP,
    'Factory visitor must be usable with callable debug resolver.',
);
unset($_SERVER['REMOTE_ADDR'], $_SERVER['SERVER_ADDR'], $_SERVER['HTTP_HOST']);

$secretProcess = str_repeat('B', 32);
$secretProcessEncoded = rtrim(strtr(base64_encode($secretProcess), '+/', '-_'), '=');
putenv('GEOLOCATION_SECRET=' . $secretProcessEncoded);
$_ENV['GEOLOCATION_COOKIE_NAME'] = 'process_cookie';
$configWithProcessValues = GeoLocatorFactory::configFromEnvironment($envFile);
expect(
    hash_equals($secretProcess, $configWithProcessValues->security->masterKey),
    'Process env must override file secret.',
);
expect($configWithProcessValues->cookie->name === 'process_cookie', '$_ENV must override file cookie name.');
putenv('GEOLOCATION_SECRET');
unset($_ENV['GEOLOCATION_COOKIE_NAME']);

$security = SecurityConfig::fromBase64Url($secretFileEncoded);
expect(strlen($security->cookieAuthKey()) === SODIUM_CRYPTO_AUTH_KEYBYTES, 'Cookie subkey length mismatch.');
expect(strlen($security->ipTokenKey()) === SODIUM_CRYPTO_AUTH_KEYBYTES, 'IP token subkey length mismatch.');
expect(!hash_equals($security->cookieAuthKey(), $security->ipTokenKey()), 'Derived subkeys must differ.');
expect(hash_equals($security->cookieAuthKey(), $security->cookieAuthKey()), 'Subkey derivation must be deterministic.');

try {
    GeoLocatorFactory::configFromEnvironment($tempRoot . '/missing.env');
    throw new RuntimeException('Explicit missing .env should fail.');
} catch (ConfigurationException) {
}

$badEnv = $privateDir . '/bad.env';
file_put_contents($badEnv, "INVALID LINE\n");
try {
    EnvLoader::load($badEnv);
    throw new RuntimeException('Invalid .env should fail.');
} catch (ConfigurationException) {
}

@unlink($badEnv);
@unlink($envFile);
@rmdir($privateDir);
@rmdir(dirname($privateDir));
@rmdir($packageRoot);
@rmdir(dirname($packageRoot));
@rmdir($alternatePackageRoot);
@rmdir(dirname($alternatePackageRoot));
@rmdir(dirname(dirname($alternatePackageRoot)));
@rmdir($tempRoot);

echo "ConfigurationTest OK\n";
