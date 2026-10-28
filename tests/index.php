<?php

/**
 * Standalone CLI test runner and browser integration console for GeoLocator.
 *
 * CLI discovers each *Test.php file and executes it in a separate PHP process,
 * preserving the parent's transport restrictions and returning a failure exit
 * status if any file fails. Browser mode reports the current request, configuration,
 * cache and MMDB status; an explicit POST action runs the real MaxMind updater.
 *
 * Browser:
 *   /GeoLocator/tests/
 *
 * CLI:
 *   php tests/index.php
 */

declare(strict_types=1);

if (PHP_SAPI === 'cli') {
    try {
        require_once __DIR__ . '/bootstrap.php';
        requireXcurl();
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }

    $testFiles = glob(__DIR__ . '/*Test.php') ?: [];
    sort($testFiles, SORT_NATURAL | SORT_FLAG_CASE);

    $passed = 0;
    $failed = 0;

    foreach ($testFiles as $testFile) {
        $name = basename($testFile);
        $command = escapeshellarg(PHP_BINARY);
        // CLI -d values are not inherited automatically by the test subprocesses.
        foreach (['allow_url_fopen', 'disable_functions'] as $setting) {
            $value = ini_get($setting);
            if (is_string($value)) {
                $command .= ' -d ' . escapeshellarg($setting . '=' . $value);
            }
        }
        $command .= ' ' . escapeshellarg($testFile);

        ob_start();
        passthru($command, $exitCode);
        $output = trim((string) ob_get_clean());

        if ($exitCode === 0) {
            ++$passed;
            echo "[OK] {$name}\n";
        } else {
            ++$failed;
            echo "[FAIL] {$name}\n";
        }

        if ($output !== '') {
            foreach (preg_split('/\\R/', $output) ?: [] as $line) {
                echo "       {$line}\n";
            }
        }
    }

    $total = $passed + $failed;

    echo str_repeat('-', 72) . "\n";
    printf(
        "%d/%d test files passed, %d failed.\n",
        $passed,
        $total,
        $failed,
    );

    exit($failed === 0 ? 0 : 1);
}

use GeoLocator\GeoLocator;
use GeoLocator\GeoLocatorFactory;
use GeoLocator\Http\HttpContext;
use GeoLocator\Proxy\ProxyDiagnosticResult;
use GeoLocator\Result\GeoResult;
use GeoLocator\Result\GeoStatus;
use GeoLocator\Update\FileUpdateStateStore;
use AbsolutOffice\XCurl\HttpTransportFactory;
use GeoLocator\Update\MaxMindDatabaseInspector;
use GeoLocator\Update\UpdateResult;
use GeoLocator\Update\UpdateState;
use GeoLocator\Update\UpdateStatus;

/**
 * Read a string POST value without coercing arrays or other input types.
 *
 * @param string $key Field name in the current POST data.
 * @param string $default Value used when the field is missing or is not a string.
 * @return string Original string value, including an empty string, or the supplied default.
 */
function postString(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? null;

    return is_string($value)
        ? $value
        : $default;
}

/**
 * Read a string query parameter without coercing arrays or other input types.
 *
 * @param string $key Parameter name in the current query data.
 * @param string $default Value used when the parameter is missing or is not a string.
 * @return string Original string value, including an empty string, or the supplied default.
 */
function queryString(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? null;

    return is_string($value)
        ? $value
        : $default;
}

/**
 * Build a context-specific server array for local browser simulation.
 *
 * Forwarding headers are deliberately removed so the simulated REMOTE_ADDR
 * is the only source used by ClientIpResolver in this test mode.
 *
 * @param array<string, mixed> $server Snapshot of server variables; the caller's array is not modified.
 * @param string $ip Validated simulation address to use as the connection peer.
 * @return array<string, mixed> Copy with REMOTE_ADDR replaced and supported forwarding headers removed.
 */
function simulatedServerArray(array $server, string $ip): array
{
    $server['REMOTE_ADDR'] = $ip;

    foreach (
        [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'HTTP_X_REAL_IP',
        ] as $header
    ) {
        unset($server[$header]);
    }

    return $server;
}

/**
 * Escape HTML output.
 *
 * @param string $value Unescaped text to place in HTML text or a quoted attribute.
 * @return string UTF-8 HTML text with both quote styles escaped and invalid sequences substituted.
 */
function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * Describe each geolocation outcome in the browser console's Spanish interface.
 *
 * @param GeoResult $result Completed lookup whose status determines the label.
 * @return string Human-readable explanation of the lookup status.
 */
function geoLabel(GeoResult $result): string
{
    return match ($result->status) {
        GeoStatus::OK => 'Geolocalización encontrada.',
        GeoStatus::INVALID_IP => 'La entrada no es una dirección IP válida.',
        GeoStatus::NON_PUBLIC_IP => 'La IP es válida, pero no es pública.',
        GeoStatus::NOT_FOUND => 'La IP es pública, pero el provider no encontró datos.',
    };
}

/**
 * Select the browser console's CSS state for a geolocation result.
 *
 * @param GeoResult|null $result Completed lookup, or null before a lookup is available.
 * @return ''|'success'|'error'|'warning' CSS class, or an empty string for no result.
 */
function geoClass(?GeoResult $result): string
{
    if ($result === null) {
        return '';
    }

    return match ($result->status) {
        GeoStatus::OK => 'success',
        GeoStatus::INVALID_IP => 'error',
        GeoStatus::NON_PUBLIC_IP => 'warning',
        GeoStatus::NOT_FOUND => 'warning',
    };
}

/**
 * Describe each successful updater outcome in the Spanish browser interface.
 *
 * @param UpdateResult $result Completed updater operation; failures are handled as exceptions elsewhere.
 * @return string Human-readable explanation of the update status.
 */
function updateLabel(UpdateResult $result): string
{
    return match ($result->status) {
        UpdateStatus::NOT_DUE => 'La comprobación todavía no corresponde.',
        UpdateStatus::UP_TO_DATE => 'La base MaxMind ya está actualizada.',
        UpdateStatus::UPDATED => 'La base MaxMind se ha actualizado correctamente.',
    };
}

/**
 * Select the browser console's CSS state for a completed update check.
 *
 * @param UpdateResult|null $result Completed operation, or null before an updater result is available.
 * @return ''|'success'|'warning' CSS class, with NOT_DUE shown as a warning.
 */
function updateClass(?UpdateResult $result): string
{
    if ($result === null) {
        return '';
    }

    return match ($result->status) {
        UpdateStatus::NOT_DUE => 'warning',
        UpdateStatus::UP_TO_DATE => 'success',
        UpdateStatus::UPDATED => 'success',
    };
}

/**
 * Render a diagnostic timestamp using its own timezone and numeric UTC offset.
 *
 * @param DateTimeInterface|null $value Timestamp to display, or null when unavailable.
 * @return string Date in Y-m-d H:i:s P format, or an em dash for an absent timestamp.
 */
function dateLabel(?DateTimeInterface $value): string
{
    return $value?->format('Y-m-d H:i:s P') ?? '—';
}

/**
 * Resolve the consuming application's optional debug callback.
 *
 * The test panel may be included after an application bootstrap that defines
 * is_debug(). Discover it dynamically so this standalone file does not depend
 * on that function being declared at analysis time.
 *
 * @return (callable(): mixed)|null Application is_debug callback, or null when it is not defined.
 */
function applicationDebugResolver(): ?callable
{
    $definedFunctions = get_defined_functions();

    foreach ($definedFunctions['user'] as $functionName) {
        if (strcasecmp($functionName, 'is_debug') !== 0) {
            continue;
        }

        return Closure::fromCallable($functionName);
    }

    return null;
}

/**
 * Render the IP used by VisitorGeoLocator without assuming lookup success.
 *
 * @param GeoResult|null $result Lookup result whose original input takes precedence when present.
 * @param string|null $fallback Address to display before a result is available.
 * @return string Original requested IP, fallback value, or an em dash when both are absent.
 */
function visitorIpLabel(?GeoResult $result, ?string $fallback): string
{
    if ($result !== null) {
        return $result->requestedIp;
    }

    return $fallback ?? '—';
}

$error = null;
$config = null;
$configSummary = null;
$context = null;
$diagnostics = null;
$core = null;

$realRemoteAddress = isset($_SERVER['REMOTE_ADDR']) && is_scalar($_SERVER['REMOTE_ADDR'])
    ? trim((string) $_SERVER['REMOTE_ADDR'])
    : null;
$simulatedIpInput = trim(queryString('simulate_ip'));
$simulatedIp = null;
$simulationError = null;
$simulationActive = false;
$effectiveRemoteAddress = $realRemoteAddress;

if ($simulatedIpInput !== '') {
    if (filter_var($simulatedIpInput, FILTER_VALIDATE_IP) === false) {
        $simulationError = 'La IP simulada no es válida. Se utiliza REMOTE_ADDR real.';
    } else {
        $simulatedIp = $simulatedIpInput;
        $simulationActive = true;
        $effectiveRemoteAddress = $simulatedIp;
    }
}

$databaseExists = false;
$databaseReadable = false;
$databaseBuildAt = null;
$databaseError = null;

$cookiePresentBeforeLookup = false;
$visitorResult = null;
$visitorError = null;

$invalidResult = null;
$nonPublicResult = null;
$fixedPublicResult = null;
$fixedPublicError = null;

$manualIp = '8.8.8.8';
$manualLookup = null;
$manualLookupError = null;

$updateState = null;
$updateStateError = null;
$updaterConfigured = false;
$manualUpdate = null;
$manualUpdateError = null;
$composerAutoloadAvailable = false;
$xcurlAvailable = false;
$httpDiagnosticError = null;
$maxMindReaderAvailable = false;
$expectedEnvPath = null;
$expectedEnvSource = 'ninguno; solo variables del proceso';
$expectedEnvExists = false;
$expectedEnvReadable = false;
$openBasedirRaw = ini_get('open_basedir');
$openBasedir = is_string($openBasedirRaw) && trim($openBasedirRaw) !== ''
    ? trim($openBasedirRaw)
    : '(sin restricción configurada)';
$databaseDirectory = null;
$databaseDirectoryExists = false;
$databaseDirectoryWritable = false;
$stateDirectoryPath = null;
$stateDirectoryExists = false;
$stateDirectoryWritable = false;
$stateDirectoryCreatable = false;
$curlExtensionLoaded = extension_loaded('curl');
$curlTransportAvailable = null;
$streamAllowUrlFopen = false;
$httpsWrapperAvailable = false;
$streamTransportAvailable = null;
$opensslExtensionLoaded = extension_loaded('openssl');
$tlsSocketTransportRegistered = false;
$socketTransportAvailable = null;
$selectedHttpTransport = null;

try {
    if (PHP_VERSION_ID < 80512 || PHP_VERSION_ID >= 90000) {
        throw new RuntimeException('GeoLocator requiere PHP ^8.5.12 (desde 8.5.12 y anterior a 9.0).');
    }

    $environmentFile = __DIR__ . '/../config/environment.php';

    if (is_file($environmentFile)) {
        require_once $environmentFile;
    } else {
        $composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';

        if (is_file($composerAutoload)) {
            require_once $composerAutoload;
        } else {
            spl_autoload_register(static function (string $class): void {
                $prefix = 'GeoLocator\\';

                if (!str_starts_with($class, $prefix)) {
                    return;
                }

                $relativeClass = substr($class, strlen($prefix));
                $file = dirname(__DIR__)
                    . '/src/GeoLocator/'
                    . str_replace('\\', '/', $relativeClass)
                    . '.php';

                if (is_file($file)) {
                    require_once $file;
                }
            });
        }
    }

    $composerAutoloadAvailable = is_file(
        dirname(__DIR__) . '/vendor/autoload.php'
    );
    $maxMindReaderAvailable = class_exists('MaxMind\\Db\\Reader');


    $xcurlAvailable = class_exists(HttpTransportFactory::class);
    $streamAllowUrlFopen = (bool) ini_get('allow_url_fopen');
    $httpsWrapperAvailable = function_exists('stream_get_wrappers')
        && in_array('https', stream_get_wrappers(), true);
    $tlsSocketTransportRegistered = function_exists('stream_get_transports')
        && (in_array('tls', stream_get_transports(), true)
            || in_array('ssl', stream_get_transports(), true));

    if ($xcurlAvailable) {
        $httpDiagnostics = HttpTransportFactory::diagnostics();
        $curlTransportAvailable = $httpDiagnostics['curl'];
        $streamTransportAvailable = $httpDiagnostics['streams'];
        $socketTransportAvailable = $httpDiagnostics['socket'];
        $selectedHttpTransport = $httpDiagnostics['selected'];
    } else {
        $httpDiagnosticError = 'absolutoffice/xcurl no está instalado. '
            . 'Ejecuta composer install para usar el actualizador y el diagnóstico HTTPS.';
    }

    // The browser test console is a consumer of GeoLocator. Like TaxId, it
    // supplies the .env path explicitly instead of asking the library to
    // infer the consuming project's directory structure.
    if (defined('GEOLOCATION_ENV_FILE')) {
        $value = constant('GEOLOCATION_ENV_FILE');
        if (is_string($value) && trim($value) !== '') {
            $expectedEnvPath = trim($value);
            $expectedEnvSource = 'constante GEOLOCATION_ENV_FILE';
        }
    }

    if ($expectedEnvPath === null) {
        $value = getenv('GEOLOCATION_ENV_FILE');
        if ($value !== false && trim($value) !== '') {
            $expectedEnvPath = trim($value);
            $expectedEnvSource = 'variable de proceso GEOLOCATION_ENV_FILE';
        }
    }

    $localTestConfig = __DIR__ . '/config.local.php';
    if ($expectedEnvPath === null && is_file($localTestConfig)) {
        $localValues = require $localTestConfig;
        if (is_array($localValues)) {
            $value = $localValues['envFile'] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $expectedEnvPath = trim($value);
                $expectedEnvSource = 'tests/config.local.php';
            }
        }
    }

    if ($expectedEnvPath !== null) {
        $expectedEnvExists = is_file($expectedEnvPath);
        $expectedEnvReadable = $expectedEnvExists && is_readable($expectedEnvPath);
    }

    $config = GeoLocatorFactory::configFromEnvironment($expectedEnvPath);

    $context = $simulationActive && $simulatedIp !== null
        ? HttpContext::fromServerArray(
            simulatedServerArray($_SERVER, $simulatedIp)
        )
        : HttpContext::fromGlobals();

    $core = GeoLocatorFactory::create($config);

    $debugResolver = applicationDebugResolver();

    $diagnostics = GeoLocatorFactory::createProxyDiagnostics($config)
        ->inspect($context);

    $configSummary = [
        'secret_configured' => true,
        'cookie_name' => $config->cookie->name,
        'cache_ttl_days' => $config->cache->ttlDays,
        'cookie_secure_override' => $config->cookie->secure,
        'cookie_http_only' => $config->cookie->httpOnly,
        'cookie_same_site' => $config->cookie->sameSite,
        'cookie_path' => $config->cookie->path,
        'cookie_domain' => $config->cookie->domain,
        'trusted_proxy_provider' => $config->proxy->provider->value,
        'custom_trusted_proxies' => $config->proxy->customTrustedProxies,
        'maxmind_database_path' => $config->maxMind->databasePath,
        'maxmind_preferred_locales' => $config->maxMind->preferredLocales,
        'maxmind_account_id_configured' => $config->maxMind->accountId !== null,
        'maxmind_license_key_configured' => $config->maxMind->licenseKey !== null,
        'maxmind_update_interval_days' => $config->update->intervalDays,
        'maxmind_http_timeout_seconds' => $config->update->httpTimeoutSeconds,
        'maxmind_http_max_attempts' => $config->update->httpMaxAttempts,
        'maxmind_state_dir' => $config->update->stateDirectory,
        'debug_resolver' => $debugResolver !== null
            ? 'is_debug()'
            : 'EnvironmentDetector fallback',
    ];

    $databaseExists = is_file($config->maxMind->databasePath);
    $databaseReadable = $databaseExists && is_readable($config->maxMind->databasePath);
    $databaseDirectory = dirname($config->maxMind->databasePath);
    $databaseDirectoryExists = is_dir($databaseDirectory);
    $databaseDirectoryWritable = $databaseDirectoryExists && is_writable($databaseDirectory);

    $stateDirectoryPath = $config->update->stateDirectory;
    if ($stateDirectoryPath !== null && trim($stateDirectoryPath) !== '') {
        $stateDirectoryExists = is_dir($stateDirectoryPath);
        $stateDirectoryWritable = $stateDirectoryExists && is_writable($stateDirectoryPath);
        if (!$stateDirectoryExists) {
            $stateParent = dirname($stateDirectoryPath);
            $stateDirectoryCreatable = is_dir($stateParent) && is_writable($stateParent);
        }
    }

    if ($databaseReadable) {
        try {
            $databaseBuildAt = (new MaxMindDatabaseInspector())
                ->buildAt($config->maxMind->databasePath);
        } catch (Throwable $throwable) {
            $databaseError = $throwable->getMessage();
        }
    }

    $cookieValue = $_COOKIE[$config->cookie->name] ?? null;
    $cookiePresentBeforeLookup = is_string($cookieValue) && $cookieValue !== '';

    try {
        if (
            !$maxMindReaderAvailable
            && $simulationActive
            && $simulatedIp !== null
            && filter_var(
                $simulatedIp,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false
        ) {
            throw new RuntimeException(
                'maxmind-db/reader is not installed. Run composer install in GeoLocator to test public IP lookups.'
            );
        }

        $visitor = GeoLocatorFactory::createVisitorForContext(
            $config,
            $context,
            $debugResolver,
        );
        $visitorResult = $visitor->locate($context);
    } catch (Throwable $throwable) {
        $visitorError = $throwable->getMessage();
    }

    $invalidResult = $core->locate('not-an-ip');
    $nonPublicResult = $core->locate('127.0.0.1');

    if ($databaseReadable && $databaseError === null) {
        if (!$maxMindReaderAvailable) {
            $fixedPublicError = 'maxmind-db/reader is not installed. Run composer install in GeoLocator.';
        } else {
            try {
                $fixedPublicResult = $core->locate('8.8.8.8');
            } catch (Throwable $throwable) {
                $fixedPublicError = $throwable->getMessage();
            }
        }
    }

    $stateDirectory = $config->update->stateDirectory;
    if ($stateDirectory !== null && trim($stateDirectory) !== '') {
        try {
            $updateState = (new FileUpdateStateStore(
                rtrim($stateDirectory, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . 'maxmind-update.json'
            ))->load();
        } catch (Throwable $throwable) {
            $updateStateError = $throwable->getMessage();
        }
    }

    $updaterConfigured = $config->maxMind->accountId !== null
        && $config->maxMind->licenseKey !== null;

    $action = postString('action');

    if ($action === 'lookup') {
        $manualIp = trim(postString('ip_address', '8.8.8.8'));

        try {
            $manualIpIsPublic = filter_var(
                $manualIp,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;

            if ($manualIpIsPublic && !$maxMindReaderAvailable) {
                throw new RuntimeException(
                    'maxmind-db/reader is not installed. Run composer install in GeoLocator to test public IP lookups.'
                );
            }

            $manualLookup = $core->locate($manualIp);
        } catch (Throwable $throwable) {
            $manualLookupError = $throwable->getMessage();
        }
    }

    if ($action === 'update') {
        if (!$updaterConfigured) {
            $manualUpdateError = 'MAXMIND_ACCOUNT_ID and MAXMIND_LICENSE_KEY are not configured.';
        } elseif (!$xcurlAvailable) {
            $manualUpdateError = $httpDiagnosticError;
        } else {
            try {
                $manualUpdate = GeoLocatorFactory::createUpdater($config)
                    ->updateIfDue();

                $stateDirectory = $config->update->stateDirectory;
                if ($stateDirectory !== null && trim($stateDirectory) !== '') {
                    $updateState = (new FileUpdateStateStore(
                        rtrim($stateDirectory, DIRECTORY_SEPARATOR)
                        . DIRECTORY_SEPARATOR
                        . 'maxmind-update.json'
                    ))->load();
                    $updateStateError = null;
                }
            } catch (Throwable $throwable) {
                $manualUpdateError = $throwable->getMessage();
            }
        }
    }
} catch (Throwable $throwable) {
    $error = $throwable->getMessage();
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>GeoLocator - Pruebas</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 30px;
            line-height: 1.5;
            color: #222;
            background: #fff;
        }

        h1,
        h2 {
            color: #1f3b5b;
        }

        h1 {
            margin-bottom: 10px;
        }

        .box {
            border: 1px solid #ccc;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 6px;
            background: #fafafa;
            scroll-margin-top: 20px;
        }

        pre {
            background: #f4f4f4;
            padding: 12px;
            overflow-x: auto;
            border-radius: 4px;
        }

        code {
            background: #f1f1f1;
            padding: 2px 5px;
            border-radius: 3px;
        }

        .error {
            color: #a40000;
            font-weight: bold;
        }

        .success {
            color: #146b2d;
            font-weight: bold;
        }

        .warning {
            color: #8a5a00;
            font-weight: bold;
        }

        .muted {
            color: #666;
        }

        .test-grid {
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 10px 16px;
            max-width: 900px;
            align-items: center;
        }

        .inline-fields {
            display: flex;
            gap: 10px;
        }

        .inline-fields input {
            flex: 1;
        }

        input,
        button {
            box-sizing: border-box;
            padding: 8px 10px;
            border: 1px solid #bbb;
            border-radius: 4px;
            font: inherit;
        }

        input {
            background: #fff;
        }

        button {
            cursor: pointer;
            background: #1f3b5b;
            color: #fff;
            border-color: #1f3b5b;
        }

        button:hover {
            background: #294d74;
        }

        .test-index {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            grid-template-columns:
                repeat(
                    auto-fit,
                    minmax(320px, 1fr)
                );
            gap: 10px;
        }

        .test-index li {
            margin: 0;
        }

        .test-index a {
            display: block;
            padding: 10px 14px;
            border: 1px solid #d6d6d6;
            border-radius: 5px;
            background: #fff;
            color: #1f3b5b;
            text-decoration: none;
            transition:
                background-color 0.15s ease,
                border-color 0.15s ease,
                transform 0.15s ease;
        }

        .test-index a:hover {
            background: #f4f4f4;
            border-color: #aaa;
            transform: translateY(-1px);
        }

        .test-index-number {
            display: inline-block;
            min-width: 82px;
            font-weight: bold;
        }

        .notice {
            border-left: 4px solid #1f3b5b;
            padding-left: 12px;
        }

        .simulation {
            border: 2px solid #8a5a00;
            background: #fff8e8;
        }

        .quick-ip {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .quick-ip a {
            display: inline-block;
            padding: 6px 9px;
            border: 1px solid #bbb;
            border-radius: 4px;
            background: #fff;
            color: #1f3b5b;
            text-decoration: none;
        }

        .quick-ip a:hover {
            background: #f4f4f4;
        }

        .kv {
            display: grid;
            grid-template-columns: minmax(220px, 320px) 1fr;
            gap: 6px 16px;
            margin: 0;
        }

        .kv dt {
            font-weight: bold;
        }

        .kv dd {
            margin: 0;
            overflow-wrap: anywhere;
        }

        @media (max-width: 700px) {
            body {
                margin: 15px;
            }

            .test-grid,
            .kv {
                grid-template-columns: 1fr;
            }

            .test-index {
                grid-template-columns: 1fr;
            }

            .inline-fields {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<h1>GeoLocator</h1>

<p>
    Entorno simple de prueba para validar el autoload de Composer,
    la configuración privada, MaxMind, la resolución de IP del visitante,
    la cache autenticada, los proxies de confianza y el updater.
</p>

<div class="box">
    <h2>Entorno de carga</h2>

    <dl class="kv">
        <dt>Composer autoload</dt>
        <dd>
            <?= $composerAutoloadAvailable
                ? '<span class="success">disponible</span>'
                : '<span class="warning">no disponible; usando fallback PSR-4 interno</span>' ?>
        </dd>

        <dt>absolutoffice/xcurl</dt>
        <dd>
            <?= $xcurlAvailable
                ? '<span class="success">disponible</span>'
                : '<span class="warning">no disponible; el actualizador necesita Composer</span>' ?>
        </dd>

        <dt>maxmind-db/reader</dt>
        <dd>
            <?= $maxMindReaderAvailable
                ? '<span class="success">disponible</span>'
                : '<span class="warning">no disponible</span>' ?>
        </dd>
    </dl>

    <?php if ($httpDiagnosticError !== null) : ?>
        <p class="notice"><?= e($httpDiagnosticError) ?></p>
    <?php endif; ?>

    <?php if (!$maxMindReaderAvailable) : ?>
        <p class="notice">
            La página puede probar configuración, IPs inválidas/no públicas,
            contexto HTTP y otras piezas internas sin Composer. Para consultar
            una IP pública mediante MaxMind debes ejecutar
            <code>composer install</code> en la raíz de GeoLocator.
        </p>
    <?php endif; ?>
</div>

<div class="box">
    <h2>Qué hace este proyecto</h2>

    <p>
        GeoLocator separa la geolocalización, el transporte HTTP,
        la cache del visitante y la actualización de la base MaxMind.
    </p>

    <ul>
        <li>
            <strong>GeoLocator</strong>:
            API pública para localizar una IP concreta.
        </li>

        <li>
            <strong>MaxMindProvider</strong>:
            consulta localmente <code>GeoLite2-City.mmdb</code>.
        </li>

        <li>
            <strong>VisitorGeoLocator</strong>:
            resuelve la IP real del visitante y reutiliza una cache
            autenticada cuando sigue siendo válida.
        </li>

        <li>
            <strong>Trusted proxies</strong>:
            solo interpreta headers de forwarding cuando el peer inmediato
            es confiable.
        </li>

        <li>
            <strong>MaxMindUpdater</strong>:
            comprueba, descarga, valida y sustituye la MMDB de forma segura.
        </li>
    </ul>

    <p class="notice">
        Este directorio es de pruebas. No conviene dejar esta página accesible
        públicamente en producción, especialmente porque permite iniciar una
        comprobación real del updater.
    </p>
</div>

<div class="box <?= $simulationActive ? 'simulation' : '' ?>">
    <h2>IP de prueba</h2>

    <dl class="kv">
        <dt>REMOTE_ADDR real</dt>
        <dd><code><?= e($realRemoteAddress ?? '—') ?></code></dd>

        <dt>IP simulada</dt>
        <dd>
            <?php if ($simulationActive && $simulatedIp !== null) : ?>
                <strong class="warning"><code><?= e($simulatedIp) ?></code></strong>
            <?php else : ?>
                —
            <?php endif; ?>
        </dd>

        <dt>REMOTE_ADDR usado en el contexto</dt>
        <dd><code><?= e($effectiveRemoteAddress ?? '—') ?></code></dd>
    </dl>

    <?php if ($simulationError !== null) : ?>
        <p class="error"><?= e($simulationError) ?></p>
    <?php elseif ($simulationActive) : ?>
        <p class="warning">
            SIMULACIÓN ACTIVA. Los headers <code>CF-Connecting-IP</code>,
            <code>X-Forwarded-For</code>, <code>Forwarded</code> y
            <code>X-Real-IP</code> se eliminan del contexto de prueba.
        </p>
    <?php else : ?>
        <p class="muted">
            En una red local, <code>REMOTE_ADDR</code> suele ser
            <code>192.168.x.x</code>, <code>10.x.x.x</code> o similar y el
            resultado correcto será <code>NON_PUBLIC_IP</code>. Puedes simular
            una IP pública para probar VisitorGeoLocator y la cookie.
        </p>
    <?php endif; ?>

    <form method="get" action="#prueba-5" class="test-grid">
        <label for="simulate_ip">
            <strong>Simular IP</strong>
        </label>

        <div class="inline-fields">
            <input
                type="text"
                id="simulate_ip"
                name="simulate_ip"
                value="<?= e($simulatedIpInput) ?>"
                placeholder="8.8.8.8"
                autocomplete="off"
            >

            <button type="submit">Aplicar</button>
        </div>
    </form>

    <div class="quick-ip">
        <a href="?simulate_ip=8.8.8.8#prueba-5">8.8.8.8 · Google DNS</a>
        <a href="?simulate_ip=1.1.1.1#prueba-5">1.1.1.1 · Cloudflare DNS</a>
        <a href="?simulate_ip=192.168.1.10#prueba-5">192.168.1.10 · privada</a>
        <a href="?simulate_ip=127.0.0.1#prueba-5">127.0.0.1 · loopback</a>
        <a href="?simulate_ip=::1#prueba-5">::1 · loopback IPv6</a>
        <a href="?#prueba-5">Usar IP real</a>
    </div>

    <p class="muted">
        Para verificar el cambio de IP/cache: usa <code>8.8.8.8</code>,
        recarga hasta ver <code>cacheHit=true</code> y después cambia a
        <code>1.1.1.1</code>. La cookie anterior debe quedar invalidada por
        <code>ipToken</code> y el primer lookup de la nueva IP debe ser
        <code>cacheHit=false</code>.
    </p>
</div>

<?php if ($error !== null) : ?>
    <div class="box">
        <h2>Error</h2>

        <p class="error">
            <?= e($error) ?>
        </p>

        <h3>Diagnóstico del .env</h3>
            <dl class="kv">
                <dt>Ruta suministrada</dt>
                <dd><code><?= e($expectedEnvPath ?? '(ninguna)') ?></code></dd>

                <dt>Origen</dt>
                <dd><?= e($expectedEnvSource) ?></dd>

                <dt>Existe</dt>
                <dd class="<?= $expectedEnvExists ? 'success' : 'error' ?>">
                    <?= $expectedEnvExists ? 'Sí' : 'No' ?>
                </dd>

                <dt>Legible por PHP</dt>
                <dd class="<?= $expectedEnvReadable ? 'success' : 'error' ?>">
                    <?= $expectedEnvReadable ? 'Sí' : 'No' ?>
                </dd>

                <dt>open_basedir</dt>
                <dd><code><?= e($openBasedir) ?></code></dd>
            </dl>

            <p class="muted">
                GeoLocator no deduce la raíz de myProject ni busca un
                <code>.env</code> automáticamente. La aplicación consumidora
                suministra la ruta, igual que en TaxId. Para esta consola puedes
                usar <code>GEOLOCATION_ENV_FILE</code> o copiar
                <code>tests/config.local.php.example</code> a
                <code>tests/config.local.php</code>.
            </p>
    </div>

<?php else : ?>
    <!-- ========================================================= -->
    <!-- ÍNDICE DE PRUEBAS                                         -->
    <!-- ========================================================= -->

    <div class="box">
        <h2>Índice de pruebas</h2>

        <p>
            Selecciona una prueba para desplazarte directamente
            a su apartado.
        </p>

        <ul class="test-index">
            <li>
                <a href="#prueba-1">
                    <span class="test-index-number">Prueba 1</span>
                    Configuración cargada
                </a>
            </li>

            <li>
                <a href="#prueba-2">
                    <span class="test-index-number">Prueba 2</span>
                    Base MaxMind local
                </a>
            </li>

            <li>
                <a href="#prueba-3">
                    <span class="test-index-number">Prueba 3</span>
                    HttpContext actual
                </a>
            </li>

            <li>
                <a href="#prueba-4">
                    <span class="test-index-number">Prueba 4</span>
                    Diagnóstico de proxies
                </a>
            </li>

            <li>
                <a href="#prueba-5">
                    <span class="test-index-number">Prueba 5</span>
                    Visitante y cache
                </a>
            </li>

            <li>
                <a href="#prueba-6">
                    <span class="test-index-number">Prueba 6</span>
                    IP inválida
                </a>
            </li>

            <li>
                <a href="#prueba-7">
                    <span class="test-index-number">Prueba 7</span>
                    IP no pública
                </a>
            </li>

            <li>
                <a href="#prueba-8">
                    <span class="test-index-number">Prueba 8</span>
                    Lookup público fijo
                </a>
            </li>

            <li>
                <a href="#prueba-9">
                    <span class="test-index-number">Prueba 9</span>
                    Lookup manual
                </a>
            </li>

            <li>
                <a href="#prueba-10">
                    <span class="test-index-number">Prueba 10</span>
                    Estado del updater
                </a>
            </li>

            <li>
                <a href="#prueba-11">
                    <span class="test-index-number">Prueba 11</span>
                    Update manual
                </a>
            </li>
        </ul>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 1                                                  -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-1">
        <h2>
            Prueba 1:
            GeoLocatorFactory::configFromEnvironment()
        </h2>

        <p class="success">
            Configuración cargada correctamente.
        </p>

        <p class="muted">
            Los secretos no se muestran. Solo se indica si están configurados.
        </p>

        <pre><?php var_dump($configSummary); ?></pre>

        <h3>Diagnóstico de permisos/runtime</h3>

        <dl class="kv">
            <dt>open_basedir</dt>
            <dd><code><?= e($openBasedir) ?></code></dd>

            <dt>Extensión cURL cargada</dt>
            <dd><?= $curlExtensionLoaded ? 'Sí' : 'No' ?></dd>

            <dt>Transporte cURL utilizable</dt>
            <dd class="<?= $curlTransportAvailable ? 'success' : 'warning' ?>">
                <?= $curlTransportAvailable === null ? 'Sin evaluar' : ($curlTransportAvailable ? 'Sí' : 'No') ?>
            </dd>

            <dt>allow_url_fopen</dt>
            <dd><?= $streamAllowUrlFopen ? 'Sí' : 'No' ?></dd>

            <dt>Wrapper HTTPS</dt>
            <dd><?= $httpsWrapperAvailable ? 'Sí' : 'No' ?></dd>

            <dt>Transporte HTTPS streams utilizable</dt>
            <dd class="<?= $streamTransportAvailable ? 'success' : 'warning' ?>">
                <?= $streamTransportAvailable === null ? 'Sin evaluar' : ($streamTransportAvailable ? 'Sí' : 'No') ?>
            </dd>

            <dt>OpenSSL cargado</dt>
            <dd><?= $opensslExtensionLoaded ? 'Sí' : 'No' ?></dd>

            <dt>Transporte TLS/SSL registrado</dt>
            <dd><?= $tlsSocketTransportRegistered ? 'Sí' : 'No' ?></dd>

            <dt>Transporte TLS socket utilizable</dt>
            <dd class="<?= $socketTransportAvailable ? 'success' : 'warning' ?>">
                <?= $socketTransportAvailable === null ? 'Sin evaluar' : ($socketTransportAvailable ? 'Sí' : 'No') ?>
            </dd>

            <dt>Transporte HTTPS seleccionado por xCurl</dt>
            <dd class="<?= $selectedHttpTransport !== null ? 'success' : 'error' ?>">
                <code><?= e($xcurlAvailable ? ($selectedHttpTransport ?? 'ninguno') : 'sin evaluar') ?></code>
            </dd>

            <dt>.env suministrado</dt>
            <dd><code><?= e($expectedEnvPath ?? '(ninguno; entorno de proceso)') ?></code></dd>

            <dt>.env legible</dt>
            <dd class="<?= $expectedEnvPath === null || $expectedEnvReadable ? 'success' : 'error' ?>">
                <?= $expectedEnvPath === null ? 'No aplica' : ($expectedEnvReadable ? 'Sí' : 'No') ?>
            </dd>

            <dt>Escritura junto al .env requerida</dt>
            <dd class="success">No</dd>

            <dt>Directorio MMDB</dt>
            <dd><code><?= e($databaseDirectory) ?></code></dd>

            <dt>Directorio MMDB escribible</dt>
            <dd class="<?= $databaseDirectoryWritable ? 'success' : 'warning' ?>">
                <?= $databaseDirectoryWritable ? 'Sí' : 'No' ?>
            </dd>

            <dt>State del updater</dt>
            <dd><code><?= e($stateDirectoryPath ?? '—') ?></code></dd>

            <dt>State existe</dt>
            <dd><?= $stateDirectoryExists ? 'Sí' : 'No' ?></dd>

            <dt>State escribible/creable</dt>
            <dd class="<?= $stateDirectoryWritable || $stateDirectoryCreatable ? 'success' : 'warning' ?>">
                <?= $stateDirectoryWritable || $stateDirectoryCreatable ? 'Sí' : 'No' ?>
            </dd>
        </dl>

        <p class="muted">
            El <code>.env</code> privado solo necesita lectura. La escritura en
            el directorio de la MMDB y en <code>.state</code> solo es necesaria
            cuando se ejecuta el updater; los lookups normales no escriben ahí.
        </p>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 2                                                  -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-2">
        <h2>
            Prueba 2:
            Base MaxMind local
        </h2>

        <dl class="kv">
            <dt>Ruta</dt>
            <dd><code><?= e($config->maxMind->databasePath) ?></code></dd>

            <dt>Existe</dt>
            <dd class="<?= $databaseExists ? 'success' : 'error' ?>">
                <?= $databaseExists ? 'Sí' : 'No' ?>
            </dd>

            <dt>Legible</dt>
            <dd class="<?= $databaseReadable ? 'success' : 'error' ?>">
                <?= $databaseReadable ? 'Sí' : 'No' ?>
            </dd>

            <dt>Build UTC</dt>
            <dd><?= e(dateLabel($databaseBuildAt)) ?></dd>
        </dl>

        <?php if ($databaseError !== null) : ?>
            <p class="error">
                <?= e($databaseError) ?>
            </p>
        <?php elseif (!$databaseReadable) : ?>
            <p class="warning">
                Los lookups públicos no podrán ejecutarse hasta instalar
                una <code>GeoLite2-City.mmdb</code> válida.
            </p>
        <?php endif; ?>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 3                                                  -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-3">
        <h2>
            Prueba 3:
            HttpContext efectivo
        </h2>

        <pre><?php var_dump($context); ?></pre>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 4                                                  -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-4">
        <h2>
            Prueba 4:
            ProxyDiagnostics::inspect()
        </h2>

        <dl class="kv">
                <dt>REMOTE_ADDR</dt>
                <dd><?= e($diagnostics->remoteAddress ?? '—') ?></dd>

                <dt>Providers configurados</dt>
                <dd><?= e(implode(', ', $diagnostics->configuredProviders) ?: 'ninguno') ?></dd>

                <dt>Peer inmediato confiable</dt>
                <dd class="<?= $diagnostics->remoteTrusted ? 'success' : 'warning' ?>">
                    <?= $diagnostics->remoteTrusted ? 'Sí' : 'No' ?>
                </dd>

                <dt>IP candidata</dt>
                <dd><?= e($diagnostics->candidateClientIp ?? '—') ?></dd>
            </dl>

            <h3>Forwarding headers observados</h3>
            <pre><?php var_dump($diagnostics->forwardingHeaders); ?></pre>

            <?php if ($diagnostics->warnings !== []) : ?>
                <h3>Avisos</h3>
                <ul>
                    <?php foreach ($diagnostics->warnings as $warning) : ?>
                        <li class="warning"><?= e($warning) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 5                                                  -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-5">
        <h2>
            Prueba 5:
            VisitorGeoLocator::locate($context)
        </h2>

        <p>
            Cookie configurada:
            <code><?= e($config->cookie->name) ?></code>
        </p>

        <p>
            Cookie presente al comenzar esta petición:
            <strong class="<?= $cookiePresentBeforeLookup ? 'success' : 'warning' ?>">
                <?= $cookiePresentBeforeLookup ? 'Sí' : 'No' ?>
            </strong>
        </p>

        <p>
            IP usada por <code>VisitorGeoLocator</code>:
            <strong>
                <code><?= e(visitorIpLabel($visitorResult, $effectiveRemoteAddress)) ?></code>
            </strong>
        </p>

        <?php if ($visitorError !== null) : ?>
            <p class="error">
                <?= e($visitorError) ?>
            </p>
        <?php elseif ($visitorResult !== null) : ?>
            <p class="<?= geoClass($visitorResult) ?>">
                <?= e(geoLabel($visitorResult)) ?>
            </p>

            <p>
                Cache hit:
                <strong class="<?= $visitorResult->cacheHit ? 'success' : 'warning' ?>">
                    <?= $visitorResult->cacheHit ? 'Sí' : 'No' ?>
                </strong>
            </p>

            <p class="muted">
                En la primera carga debe aparecer <code>cacheHit=false</code>.
                Recarga la misma URL: si la IP efectiva sigue siendo la misma y la
                cookie es válida, debe pasar a <code>cacheHit=true</code>. Al cambiar
                la IP simulada, el <code>ipToken</code> debe invalidar esa entrada y
                volver a <code>cacheHit=false</code>.
            </p>

            <pre><?php var_dump($visitorResult); ?></pre>
        <?php endif; ?>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 6                                                  -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-6">
        <h2>
            Prueba 6:
            GeoLocator::locate('not-an-ip')
        </h2>

        <p class="<?= geoClass($invalidResult) ?>">
            <?= e(geoLabel($invalidResult)) ?>
        </p>

        <pre><?php var_dump($invalidResult); ?></pre>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 7                                                  -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-7">
        <h2>
            Prueba 7:
            GeoLocator::locate('127.0.0.1')
        </h2>

        <p class="<?= geoClass($nonPublicResult) ?>">
            <?= e(geoLabel($nonPublicResult)) ?>
        </p>

        <pre><?php var_dump($nonPublicResult); ?></pre>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 8                                                  -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-8">
        <h2>
            Prueba 8:
            GeoLocator::locate('8.8.8.8')
        </h2>

        <?php if (!$databaseReadable || $databaseError !== null) : ?>
            <p class="warning">
                Prueba omitida porque la MMDB local no está disponible o no es válida.
            </p>
        <?php elseif ($fixedPublicError !== null) : ?>
            <p class="error">
                <?= e($fixedPublicError) ?>
            </p>
        <?php elseif ($fixedPublicResult !== null) : ?>
            <p class="<?= geoClass($fixedPublicResult) ?>">
                <?= e(geoLabel($fixedPublicResult)) ?>
            </p>

            <pre><?php var_dump($fixedPublicResult); ?></pre>
        <?php endif; ?>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 9                                                  -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-9">
        <h2>
            Prueba 9:
            Lookup manual
        </h2>

        <p>
            Prueba directamente la API pública
            <code>GeoLocator::locate()</code>. Esta operación consulta
            únicamente la MMDB local; no hace ninguna petición externa.
        </p>

        <form
            method="post"
            action="<?= $simulationActive && $simulatedIp !== null
                ? '?simulate_ip=' . rawurlencode($simulatedIp)
                : '' ?>#prueba-9"
            class="test-grid"
        >
            <input
                type="hidden"
                name="action"
                value="lookup"
            >

            <?php if ($simulationActive && $simulatedIp !== null) : ?>
                <input
                    type="hidden"
                    name="simulate_ip"
                    value="<?= e($simulatedIp) ?>"
                >
            <?php endif; ?>

            <label for="ip_address">
                <strong>Dirección IP</strong>
            </label>

            <div class="inline-fields">
                <input
                    type="text"
                    id="ip_address"
                    name="ip_address"
                    value="<?= e($manualIp) ?>"
                    autocomplete="off"
                >

                <button type="submit">
                    Localizar
                </button>
            </div>
        </form>

        <?php if ($manualLookupError !== null) : ?>
            <p class="error">
                <?= e($manualLookupError) ?>
            </p>
        <?php elseif ($manualLookup !== null) : ?>
            <p class="<?= geoClass($manualLookup) ?>">
                <?= e(geoLabel($manualLookup)) ?>
            </p>

            <pre><?php var_dump($manualLookup); ?></pre>
        <?php endif; ?>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 10                                                 -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-10">
        <h2>
            Prueba 10:
            Estado del updater
        </h2>

        <p>
            Credenciales MaxMind configuradas:
            <strong class="<?= $updaterConfigured ? 'success' : 'warning' ?>">
                <?= $updaterConfigured ? 'Sí' : 'No' ?>
            </strong>
        </p>

        <?php if ($updateStateError !== null) : ?>
            <p class="error">
                <?= e($updateStateError) ?>
            </p>
        <?php elseif ($updateState instanceof UpdateState) : ?>
            <dl class="kv">
                <dt>Último check</dt>
                <dd><?= e(dateLabel($updateState->lastCheckAt)) ?></dd>

                <dt>Última actualización instalada</dt>
                <dd><?= e(dateLabel($updateState->lastSuccessfulUpdateAt)) ?></dd>

                <dt>Build registrado</dt>
                <dd><?= e(dateLabel($updateState->databaseBuildAt)) ?></dd>
            </dl>

            <pre><?php var_dump($updateState); ?></pre>
        <?php endif; ?>
    </div>

    <!-- ========================================================= -->
    <!-- PRUEBA 11                                                 -->
    <!-- ========================================================= -->

    <div class="box" id="prueba-11">
        <h2>
            Prueba 11:
            MaxMindUpdater::updateIfDue()
        </h2>

        <p class="notice">
            Esta prueba sí puede realizar peticiones reales a MaxMind y,
            cuando exista una versión nueva o falte la MMDB, puede descargar
            e instalar una base nueva. El updater aplica sus límites de
            reintento, SHA-256, validación MMDB, lock y reemplazo atómico.
        </p>

        <?php if (!$updaterConfigured) : ?>
            <p class="warning">
                Configura <code>MAXMIND_ACCOUNT_ID</code> y
                <code>MAXMIND_LICENSE_KEY</code> para habilitar esta prueba.
            </p>
        <?php endif; ?>

        <form
            method="post"
            action="<?= $simulationActive && $simulatedIp !== null
                ? '?simulate_ip=' . rawurlencode($simulatedIp)
                : '' ?>#prueba-11"
        >
            <input
                type="hidden"
                name="action"
                value="update"
            >

            <button type="submit" <?= $updaterConfigured ? '' : 'disabled' ?>>
                Comprobar actualización
            </button>
        </form>

        <?php if ($manualUpdateError !== null) : ?>
            <p class="error">
                <?= e($manualUpdateError) ?>
            </p>
        <?php elseif ($manualUpdate !== null) : ?>
            <p class="<?= updateClass($manualUpdate) ?>">
                <?= e(updateLabel($manualUpdate)) ?>
            </p>

            <pre><?php var_dump($manualUpdate); ?></pre>
        <?php endif; ?>
    </div>

<?php endif; ?>

</body>
</html>
