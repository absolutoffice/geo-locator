<?php

/**
 * Verify GeoLocator-to-xcurl factory wiring against the current PHP capabilities.
 *
 * Inspects the selected transport, timeout and total-attempts-to-retries mapping
 * without sending requests or creating updater state directories.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use AbsolutOffice\XCurl\Client\HttpClient;
use AbsolutOffice\XCurl\Client\RetryPolicy;
use AbsolutOffice\XCurl\Contract\HttpTransportInterface;
use AbsolutOffice\XCurl\HttpTransportFactory;
use GeoLocator\Config\GeoLocatorConfig;
use GeoLocator\Config\MaxMindConfig;
use GeoLocator\Config\SecurityConfig;
use GeoLocator\Config\UpdateConfig;
use GeoLocator\Exception\ConfigurationException;
use GeoLocator\GeoLocatorFactory;
use GeoLocator\Update\Contract\MaxMindDownloadClientInterface;
use GeoLocator\Update\MaxMindHttpDownloadClient;

requireXcurl();

// Exercise GeoLocator's dependency wiring without making requests or retesting xCurl internals.
$diagnostics = HttpTransportFactory::diagnostics();
expect(array_key_exists('selected', $diagnostics), 'xCurl diagnostics must expose the selected transport.');
expect(
    $diagnostics['selected'] === null || in_array($diagnostics['selected'], ['curl', 'streams', 'socket'], true),
    'The browser diagnostic must understand the selected xCurl backend.',
);

$runtimeRoot = sys_get_temp_dir() . '/geolocator-xcurl-wiring-' . bin2hex(random_bytes(5));
foreach ([1, 2, 3] as $maxAttempts) {
    $config = new GeoLocatorConfig(
        security: new SecurityConfig(str_repeat('T', 32)),
        maxMind: new MaxMindConfig(
            $runtimeRoot . '/db/GeoLite2-City.mmdb',
            accountId: 'test-account',
            licenseKey: 'test-key',
        ),
        update: new UpdateConfig(
            stateDirectory: $runtimeRoot . '/state',
            httpTimeoutSeconds: 17,
            httpMaxAttempts: $maxAttempts,
        ),
    );

    if ($diagnostics['selected'] === null) {
        try {
            GeoLocatorFactory::createUpdater($config);
            throw new RuntimeException('Missing HTTPS capability must fail during updater creation.');
        } catch (ConfigurationException) {
            // The GeoLocator boundary must translate xCurl's factory exception.
        }
        continue;
    }

    $updater = GeoLocatorFactory::createUpdater($config);
    $downloadClient = (new ReflectionProperty($updater, 'downloadClient'))->getValue($updater);
    expect($downloadClient instanceof MaxMindDownloadClientInterface, 'Updater must retain its domain contract.');
    expect($downloadClient instanceof MaxMindHttpDownloadClient, 'Factory must install the MaxMind HTTP adapter.');

    // Read-only reflection checks the off-by-one boundary without contacting MaxMind.
    $client = (new ReflectionProperty($downloadClient, 'client'))->getValue($downloadClient);
    expect($client instanceof HttpClient, 'Factory must inject the xCurl policy client.');
    $policy = (new ReflectionProperty($client, 'retryPolicy'))->getValue($client);
    expect($policy instanceof RetryPolicy, 'Retry policy must be owned by xCurl.');
    expect(
        $policy->maxRetries === $maxAttempts - 1,
        'Total HTTP attempts must map to xCurl retries minus the first try.',
    );

    $transport = (new ReflectionProperty($client, 'transport'))->getValue($client);
    expect($transport instanceof HttpTransportInterface, 'Factory must use an xCurl transport.');
    expect(
        str_starts_with($transport::class, 'AbsolutOffice\\XCurl\\'),
        'The updater must not retain a GeoLocator transport implementation.',
    );
    expect(
        (new ReflectionProperty($downloadClient, 'timeoutSeconds'))->getValue($downloadClient) === 17,
        'Configured transport timeout must reach the MaxMind adapter.',
    );
}
expect(!file_exists($runtimeRoot), 'Creating the updater must not create files or directories.');

echo "HttpTransportRuntimeTest OK\n";
