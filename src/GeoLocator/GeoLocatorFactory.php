<?php

declare(strict_types=1);

namespace GeoLocator;

use AbsolutOffice\XCurl\Client\HttpClient;
use AbsolutOffice\XCurl\Client\RetryPolicy;
use AbsolutOffice\XCurl\HttpTransportFactory;
use GeoLocator\Config\CacheConfig;
use GeoLocator\Config\CookieConfig;
use GeoLocator\Config\EnvLoader;
use GeoLocator\Config\GeoLocatorConfig;
use GeoLocator\Config\MaxMindConfig;
use GeoLocator\Config\ProxyConfig;
use GeoLocator\Config\PackagePathResolver;
use GeoLocator\Config\SecurityConfig;
use GeoLocator\Config\UpdateConfig;
use GeoLocator\Cache\CookieAuthenticator;
use GeoLocator\Cache\GeoCacheCodec;
use GeoLocator\Cache\GeoCachePolicy;
use GeoLocator\Cache\IpTokenGenerator;
use GeoLocator\Exception\ConfigurationException;
use GeoLocator\Http\CallableDebugModeResolver;
use GeoLocator\Http\ClientIpResolver;
use GeoLocator\Http\CookieGeoCacheStore;
use GeoLocator\Http\CookieSecureResolver;
use GeoLocator\Http\HttpContext;
use GeoLocator\Http\VisitorGeoLocator;
use GeoLocator\Proxy\CloudflareProxyProvider;
use GeoLocator\Proxy\CustomProxyProvider;
use GeoLocator\Proxy\ProxyDiagnostics;
use GeoLocator\Proxy\TrustedProxyProvider;
use GeoLocator\Proxy\TrustedProxyResolver;
use GeoLocator\Provider\MaxMind\MaxMindProvider;
use GeoLocator\Update\FileUpdateStateStore;
use GeoLocator\Update\MaxMindArchiveExtractor;
use GeoLocator\Update\MaxMindDatabaseInspector;
use GeoLocator\Update\MaxMindUpdater;
use GeoLocator\Update\MaxMindHttpDownloadClient;
use RuntimeException;

/**
 * Assembles GeoLocator services from validated configuration or explicit environment input.
 *
 * Local lookup, visitor caching and database updates have separate entry points.
 * Factory creation wires dependencies; database lookups and downloads occur only
 * when the returned services are invoked.
 */
final class GeoLocatorFactory
{
    /**
     * Build a local MaxMind-backed lookup service with lazy MMDB access.
     *
     * @param GeoLocatorConfig $config Aggregate configuration providing the MaxMind database and locale settings.
     * @return GeoLocator Core lookup service; the MMDB file has not yet been opened.
     */
    public static function create(GeoLocatorConfig $config): GeoLocator
    {
        return new GeoLocator(new MaxMindProvider($config->maxMind));
    }

    /**
     * Build a MaxMind updater with xcurl policies, file state storage and a shared lock path.
     *
     * The configured total attempt count becomes maxRetries = httpMaxAttempts - 1.
     * Transport selection occurs during creation; redirects and transient retries are
     * handled by xcurl when the updater later requests metadata or archives.
     *
     * @param GeoLocatorConfig $config Configuration including download credentials and a nonblank state directory.
     * @return MaxMindUpdater Ready-to-run updater; creation does not fetch or install a database.
     *
     * @throws ConfigurationException If credentials/state or xcurl are missing, or no HTTPS transport is available.
     * @throws \GeoLocator\Exception\UpdateException If supplied download credentials are blank.
     */
    public static function createUpdater(GeoLocatorConfig $config): MaxMindUpdater
    {
        $accountId = $config->maxMind->accountId;
        $licenseKey = $config->maxMind->licenseKey;
        $stateDirectory = $config->update->stateDirectory;

        if ($accountId === null || $licenseKey === null) {
            throw new ConfigurationException(
                'MAXMIND_ACCOUNT_ID and MAXMIND_LICENSE_KEY are required to create the updater.',
            );
        }
        if ($stateDirectory === null || trim($stateDirectory) === '') {
            throw new ConfigurationException('MAXMIND_STATE_DIR is required to create the updater.');
        }

        $stateDirectory = rtrim($stateDirectory, DIRECTORY_SEPARATOR);

        if (!class_exists(HttpClient::class)) {
            throw new ConfigurationException(
                'The MaxMind updater requires absolutoffice/xcurl. Run composer install to load its dependencies.',
            );
        }

        try {
            $httpClient = new HttpClient(
                HttpTransportFactory::create(),
                new RetryPolicy(maxRetries: $config->update->httpMaxAttempts - 1),
            );
        } catch (RuntimeException $exception) {
            throw new ConfigurationException(
                'MaxMind updater requires an available xCurl HTTPS transport (cURL, HTTPS streams or TLS sockets).',
                0,
                $exception,
            );
        }

        return new MaxMindUpdater(
            maxMindConfig: $config->maxMind,
            updateConfig: $config->update,
            stateStore: new FileUpdateStateStore($stateDirectory . DIRECTORY_SEPARATOR . 'maxmind-update.json'),
            downloadClient: new MaxMindHttpDownloadClient(
                accountId: $accountId,
                licenseKey: $licenseKey,
                client: $httpClient,
                timeoutSeconds: $config->update->httpTimeoutSeconds,
            ),
            databaseInspector: new MaxMindDatabaseInspector(),
            archiveExtractor: new MaxMindArchiveExtractor(),
            lockFile: $stateDirectory . DIRECTORY_SEPARATOR . 'maxmind-update.lock',
        );
    }

    /**
     * Build a visitor locator using the current globals for cookie Secure inference.
     *
     * @param GeoLocatorConfig $config Provider, proxy, key, cache and cookie settings.
     * @param null|callable(): mixed $debugResolver Optional callback returning bool or null to infer debug mode.
     * @return VisitorGeoLocator Visitor lookup service using the configured authenticated cookie cache.
     *
     * @throws ConfigurationException If proxy/cookie/security configuration or the debug callback result is invalid.
     * @throws \Throwable If an evaluated debug callback fails; its exception propagates unchanged.
     */
    public static function createVisitor(
        GeoLocatorConfig $config,
        ?callable $debugResolver = null,
    ): VisitorGeoLocator {
        return self::createVisitorForContext(
            $config,
            HttpContext::fromGlobals(),
            $debugResolver,
        );
    }

    /**
     * Build a visitor locator with explicit context for cookie Secure inference.
     *
     * The provided context determines the inferred Secure flag at construction time.
     * The resulting cookie store still reads/writes the current request's cookies;
     * the caller supplies the lookup context when invoking the visitor service.
     *
     * @param GeoLocatorConfig $config Provider, proxy, key, cache and cookie settings.
     * @param HttpContext $context Request snapshot used to infer the cache cookie's Secure attribute.
     * @param null|callable(): mixed $debugResolver Optional callback returning bool or null to defer to detection.
     * @return VisitorGeoLocator Visitor service using validated proxy trust and independently derived cache keys.
     *
     * @throws ConfigurationException If a proxy network, cookie configuration or debug resolver value is invalid.
     * @throws \Throwable If an evaluated debug callback fails; its exception propagates unchanged.
     */
    public static function createVisitorForContext(
        GeoLocatorConfig $config,
        HttpContext $context,
        ?callable $debugResolver = null,
    ): VisitorGeoLocator {
        $trustedResolver = self::trustedProxyResolver($config->proxy);
        $clientIpResolver = new ClientIpResolver($trustedResolver);

        $cookieAuthenticator = new CookieAuthenticator($config->security->cookieAuthKey());
        $ipTokenGenerator = new IpTokenGenerator($config->security->ipTokenKey());
        $codec = new GeoCacheCodec($cookieAuthenticator);
        $debugModeResolver = $debugResolver !== null
            ? new CallableDebugModeResolver($debugResolver)
            : null;
        $secure = (new CookieSecureResolver())->resolve(
            $config->cookie,
            $context,
            $debugModeResolver,
        );
        $cacheStore = new CookieGeoCacheStore($codec, $config->cookie, $secure);

        return new VisitorGeoLocator(
            geoLocator: self::create($config),
            clientIpResolver: $clientIpResolver,
            cacheStore: $cacheStore,
            cachePolicy: new GeoCachePolicy($ipTokenGenerator),
            ipTokenGenerator: $ipTokenGenerator,
            cacheConfig: $config->cache,
        );
    }

    /**
     * Load configuration and assemble a visitor locator using the current request globals.
     *
     * @param string|null $envFile Explicit dotenv path; null reads only the process/PHP environment sources.
     * @param null|callable(): mixed $debugResolver Optional callback returning bool or null for Secure inference.
     * @return VisitorGeoLocator Configured visitor lookup service.
     *
     * @throws ConfigurationException If environment parsing, required configuration or visitor setup fails.
     * @throws \Throwable If an evaluated debug callback fails; its exception propagates unchanged.
     */
    public static function visitorFromEnvironment(
        ?string $envFile = null,
        ?callable $debugResolver = null,
    ): VisitorGeoLocator {
        return self::createVisitor(self::configFromEnvironment($envFile), $debugResolver);
    }

    /**
     * Load configuration and assemble a visitor locator for an explicit request context.
     *
     * @param HttpContext $context Request snapshot used to infer the cache cookie's Secure attribute.
     * @param string|null $envFile Explicit dotenv path; null uses only process/PHP environment sources.
     * @param null|callable(): mixed $debugResolver Optional callback returning bool or null for Secure inference.
     * @return VisitorGeoLocator Configured visitor service whose cookie store uses the current request cookies.
     *
     * @throws ConfigurationException If environment parsing, required configuration or visitor setup fails.
     * @throws \Throwable If an evaluated debug callback fails; its exception propagates unchanged.
     */
    public static function visitorForContextFromEnvironment(
        HttpContext $context,
        ?string $envFile = null,
        ?callable $debugResolver = null,
    ): VisitorGeoLocator {
        return self::createVisitorForContext(
            self::configFromEnvironment($envFile),
            $context,
            $debugResolver,
        );
    }

    /**
     * Build a diagnostic view using the same trusted-proxy rules as visitor lookups.
     *
     * @param GeoLocatorConfig $config Aggregate configuration whose proxy settings will be applied.
     * @return ProxyDiagnostics Service for inspecting peer trust and resolved client addresses.
     *
     * @throws ConfigurationException If a custom trusted proxy address or network is invalid.
     */
    public static function createProxyDiagnostics(GeoLocatorConfig $config): ProxyDiagnostics
    {
        $trustedResolver = self::trustedProxyResolver($config->proxy);

        return new ProxyDiagnostics(
            $trustedResolver,
            new ClientIpResolver($trustedResolver),
        );
    }

    /**
     * Load and validate all configuration from the environment and an optional dotenv file.
     *
     * For each key, precedence is getenv(), then scalar $_ENV/$_SERVER values, then
     * the explicitly supplied file. No .env location is inferred. Relative database
     * and state paths are anchored to the package root; the default state directory is
     * .state beside the MMDB file. The older MAXMIND_STATE_DIRECTORY alias remains
     * accepted when MAXMIND_STATE_DIR is absent or blank.
     *
     * @param string|null $envFile Consumer-selected dotenv file path; null skips file loading.
     * @return GeoLocatorConfig Validated aggregate with normalized paths, secret and typed configuration values.
     *
     * @throws ConfigurationException If dotenv input, required keys, secret, ranges or enum-like options are invalid.
     */
    public static function configFromEnvironment(?string $envFile = null): GeoLocatorConfig
    {
        // Same integration principle as TaxId: the consumer owns the .env
        // location and passes it explicitly. GeoLocator never infers a
        // project root from the package installation directory.
        $fileValues = $envFile !== null ? EnvLoader::load($envFile) : [];

        $secret = self::requiredEnvironmentValue('GEOLOCATION_SECRET', $fileValues);
        $databasePath = (new PackagePathResolver())->resolve(
            self::requiredEnvironmentValue('MAXMIND_DATABASE_PATH', $fileValues),
        );

        $pathResolver = new PackagePathResolver();
        $stateDirectory = self::environmentValue('MAXMIND_STATE_DIR', $fileValues);
        if ($stateDirectory === null || trim($stateDirectory) === '') {
            // Backward-compatible alias from pre-0.8.8 builds. New
            // configuration should use MAXMIND_STATE_DIR.
            $stateDirectory = self::environmentValue('MAXMIND_STATE_DIRECTORY', $fileValues);
        }
        if ($stateDirectory === null || trim($stateDirectory) === '') {
            // Runtime state belongs with the writable MaxMind data, never
            // beside the consumer-owned secrets file. This keeps .private
            // strictly read-only for the PHP process.
            $stateDirectory = dirname($databasePath) . DIRECTORY_SEPARATOR . '.state';
        } else {
            $stateDirectory = $pathResolver->resolve($stateDirectory);
        }

        return new GeoLocatorConfig(
            security: SecurityConfig::fromBase64Url($secret),
            maxMind: new MaxMindConfig(
                databasePath: $databasePath,
                preferredLocales: self::parseCsv(
                    self::nonBlankOrDefault(
                        self::environmentValue('MAXMIND_PREFERRED_LOCALES', $fileValues),
                        'es,en',
                    ),
                ),
                accountId: self::nullableTrimmed(self::environmentValue('MAXMIND_ACCOUNT_ID', $fileValues)),
                licenseKey: self::nullableTrimmed(self::environmentValue('MAXMIND_LICENSE_KEY', $fileValues)),
            ),
            cache: new CacheConfig(
                ttlDays: self::positiveInt(
                    'GEOLOCATION_CACHE_TTL_DAYS',
                    self::environmentValue('GEOLOCATION_CACHE_TTL_DAYS', $fileValues),
                    30,
                ),
            ),
            cookie: new CookieConfig(
                name: self::nonBlankOrDefault(
                    self::environmentValue('GEOLOCATION_COOKIE_NAME', $fileValues),
                    'geolocator',
                ),
                secure: self::nullableBool(
                    'GEOLOCATION_COOKIE_SECURE',
                    self::environmentValue('GEOLOCATION_COOKIE_SECURE', $fileValues),
                ),
                httpOnly: self::boolOrDefault(
                    'GEOLOCATION_COOKIE_HTTPONLY',
                    self::environmentValue('GEOLOCATION_COOKIE_HTTPONLY', $fileValues),
                    true,
                ),
                sameSite: self::nonBlankOrDefault(
                    self::environmentValue('GEOLOCATION_COOKIE_SAMESITE', $fileValues),
                    'Lax',
                ),
                path: self::nonBlankOrDefault(
                    self::environmentValue('GEOLOCATION_COOKIE_PATH', $fileValues),
                    '/',
                ),
                domain: self::nullableTrimmed(
                    self::environmentValue('GEOLOCATION_COOKIE_DOMAIN', $fileValues),
                ),
            ),
            proxy: new ProxyConfig(
                provider: self::proxyProvider(
                    self::environmentValue('GEOLOCATION_TRUSTED_PROXY_PROVIDER', $fileValues) ?? 'none',
                ),
                customTrustedProxies: self::parseCsv(
                    self::environmentValue('GEOLOCATION_TRUSTED_PROXIES', $fileValues) ?? '',
                ),
            ),
            update: new UpdateConfig(
                intervalDays: self::positiveInt(
                    'MAXMIND_UPDATE_INTERVAL_DAYS',
                    self::environmentValue('MAXMIND_UPDATE_INTERVAL_DAYS', $fileValues),
                    7,
                ),
                stateDirectory: $stateDirectory,
                httpTimeoutSeconds: self::positiveInt(
                    'MAXMIND_HTTP_TIMEOUT_SECONDS',
                    self::environmentValue('MAXMIND_HTTP_TIMEOUT_SECONDS', $fileValues),
                    30,
                ),
                httpMaxAttempts: self::positiveInt(
                    'MAXMIND_HTTP_MAX_ATTEMPTS',
                    self::environmentValue('MAXMIND_HTTP_MAX_ATTEMPTS', $fileValues),
                    3,
                ),
            ),
        );
    }

    /**
     * Resolve one key by process, PHP-superglobal and file-value precedence.
     *
     * An existing getenv() value wins even when blank. For PHP superglobals, $_ENV
     * takes precedence over $_SERVER; only scalar values are converted to strings.
     *
     * @param string $key Environment variable name to resolve.
     * @param array<string, string> $fileValues Values parsed from the optional dotenv file.
     * @return string|null First applicable value, or null when no source supplies it.
     */
    private static function environmentValue(string $key, array $fileValues): ?string
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        $envValue = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if (is_scalar($envValue)) {
            return (string) $envValue;
        }

        return $fileValues[$key] ?? null;
    }

    /**
     * Resolve a mandatory configuration value and strip surrounding whitespace.
     *
     * @param string $key Required environment variable name, also used in the failure message.
     * @param array<string, string> $fileValues Fallback values parsed from the optional dotenv file.
     * @return string Nonblank, trimmed value.
     *
     * @throws ConfigurationException If the resolved value is absent or blank.
     */
    private static function requiredEnvironmentValue(string $key, array $fileValues): string
    {
        $value = self::environmentValue($key, $fileValues);
        if ($value === null || trim($value) === '') {
            throw new ConfigurationException(sprintf('%s is required.', $key));
        }

        return trim($value);
    }

    /**
     * Normalize an optional text setting while treating blank values as unset.
     *
     * @param string|null $value Raw configuration value to trim.
     * @param string $default Value used when the input is absent or blank; returned without normalization.
     * @return string Trimmed nonblank input or the supplied default.
     */
    private static function nonBlankOrDefault(?string $value, string $default): string
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }

    /**
     * Normalize optional credential or attribute text to a value or null.
     *
     * @param string|null $value Raw value to trim when present.
     * @return string|null Trimmed nonblank text, or null for absent/blank input.
     */
    private static function nullableTrimmed(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Parse a positive integer setting while preserving its default when unset.
     *
     * @param string $key Configuration key used in validation-error messages.
     * @param string|null $value Raw integer text; absent or blank input selects the default.
     * @param int $default Internal default used unchanged when the input is unset.
     * @return int Parsed positive integer, or the supplied default.
     *
     * @throws ConfigurationException If nonblank input is not a valid integer greater than or equal to one.
     */
    private static function positiveInt(string $key, ?string $value, int $default): int
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        $value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new ConfigurationException(sprintf('%s must be an integer greater than or equal to 1.', $key));
        }

        return (int) $value;
    }

    /**
     * Split comma-separated settings, trim entries and discard empty elements.
     *
     * The remaining order and duplicate values are preserved.
     *
     * @param string $value Raw comma-separated text, including an empty string for an empty list.
     * @return list<string> Nonblank trimmed items with consecutive integer keys.
     */
    private static function parseCsv(string $value): array
    {
        $items = array_map('trim', explode(',', $value));
        $items = array_values(array_filter($items, static fn (string $item): bool => $item !== ''));

        return $items;
    }

    /**
     * Parse an optional boolean override while preserving the absence of a decision.
     *
     * @param string $key Configuration key used in validation-error messages.
     * @param string|null $value Raw boolean text; null or blank means no override.
     * @return bool|null Parsed flag, or null when input is absent/blank.
     *
     * @throws ConfigurationException If nonblank input is not a supported boolean spelling.
     */
    private static function nullableBool(string $key, ?string $value): ?bool
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::parseBool($key, $value);
    }

    /**
     * Parse a boolean setting or use its default when the input is unset.
     *
     * @param string $key Configuration key used in validation-error messages.
     * @param string|null $value Raw boolean text; null or blank selects the default.
     * @param bool $default Flag to use when no nonblank value is supplied.
     * @return bool Parsed flag or the supplied default.
     *
     * @throws ConfigurationException If nonblank input is not a supported boolean spelling.
     */
    private static function boolOrDefault(string $key, ?string $value, bool $default): bool
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        return self::parseBool($key, $value);
    }

    /**
     * Recognize the supported case-insensitive true and false spellings.
     *
     * @param string $key Configuration key used in validation-error messages.
     * @param string $value Text to trim and parse: 1/true/yes/on or 0/false/no/off.
     * @return bool Boolean represented by the normalized input.
     *
     * @throws ConfigurationException If the text does not match a supported spelling.
     */
    private static function parseBool(string $key, string $value): bool
    {
        $normalized = strtolower(trim($value));
        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new ConfigurationException(sprintf('%s must be a boolean value.', $key)),
        };
    }

    /**
     * Combine the selected built-in proxy source with any validated custom networks.
     *
     * @param ProxyConfig $config Built-in selection and additional IP/CIDR trust entries.
     * @return TrustedProxyResolver Resolver trusting any matching configured provider, or none when no sources exist.
     *
     * @throws ConfigurationException If a custom trusted IP address or network is malformed.
     */
    private static function trustedProxyResolver(ProxyConfig $config): TrustedProxyResolver
    {
        $trustedProviders = [];
        if ($config->provider === TrustedProxyProvider::CLOUDFLARE) {
            $trustedProviders[] = new CloudflareProxyProvider();
        }
        if ($config->customTrustedProxies !== []) {
            $trustedProviders[] = new CustomProxyProvider($config->customTrustedProxies);
        }

        return new TrustedProxyResolver($trustedProviders);
    }

    /**
     * Resolve a case-insensitive provider setting to the supported backed enum.
     *
     * @param string $value Built-in provider name, trimmed and lowercased before matching.
     * @return TrustedProxyProvider Matching built-in selection.
     *
     * @throws ConfigurationException If the name does not identify a supported provider.
     */
    private static function proxyProvider(string $value): TrustedProxyProvider
    {
        $value = strtolower(trim($value));
        $provider = TrustedProxyProvider::tryFrom($value);

        if ($provider === null) {
            throw new ConfigurationException(sprintf(
                'GEOLOCATION_TRUSTED_PROXY_PROVIDER must be one of: %s.',
                implode(', ', array_column(TrustedProxyProvider::cases(), 'value')),
            ));
        }

        return $provider;
    }
}
