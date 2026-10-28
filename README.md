# GeoLocator

PHP IP geolocation library with a MaxMind provider, authenticated visitor cache, trusted-proxy handling and a separate database updater powered by `absolutoffice/xcurl`.

**Composer package:** `absolutoffice/geo-locator` · **Namespace:** `GeoLocator\` · **PHP:** `^8.5.12` · **License:** LGPL-3.0-or-later.

> **1.0.0 release source — 28/10/2026.** This tree is prepared for the initial `v1.0.0` Git tag and GitHub Release. `VERSION` records the source release identifier; Composer still derives the package version from Git, so the branch remains `dev-main` until the `v1.0.0` tag is published. The intended final repository is `https://github.com/absolutoffice/geo-locator`.

## Choose an installation route

| Route | Dependencies | Application entry point |
| --- | --- | --- |
| Composer from a GitHub VCS repository | Composer installs dependencies into the consuming application's `vendor/` | The application's `vendor/autoload.php` |
| GitHub source ZIP or clone | Run `composer install` in the extracted/cloned source | That installation's `vendor/autoload.php` |
| `geo-locator-<version>-standalone.zip` from GitHub Releases | Production dependencies are already bundled | The extracted `geo-locator/vendor/autoload.php` |

All routes require a compatible PHP runtime and the declared extensions. The public distributions contain no MaxMind database, local credentials or updater state. These belong to the consuming application. Detailed Spanish instructions are in [docs/INSTALLATION.md](docs/INSTALLATION.md); publishing and release commands are in [docs/RELEASING.md](docs/RELEASING.md).

### Composer directly from GitHub

After the repository exists, run these commands **in the consuming application**. Packagist registration is not required for this VCS route:

```bash
composer config repositories.geolocator vcs https://github.com/absolutoffice/geo-locator
composer require absolutoffice/geo-locator:dev-main
```

`dev-main` explicitly selects the development branch. After the prepared `v1.0.0` tag has been published, select its compatible stable range instead:

```bash
composer require 'absolutoffice/geo-locator:^1.0'
```

Keep the `repositories` entry in the application's root `composer.json`. Composer does not inherit repository definitions from dependencies. If the package is later registered on Packagist, consumers can use `composer require` without that VCS entry. See the official [Composer repository](https://getcomposer.org/doc/05-repositories.md) and [library publishing](https://getcomposer.org/doc/02-libraries.md) documentation.

Load the application's autoloader once:

```php
require __DIR__ . '/vendor/autoload.php';
```

Do not create a second nested Composer installation inside `vendor/absolutoffice/geo-locator/`.

### Direct GitHub download

GitHub's **Code → Download ZIP** and automatic source archives contain source code. Install their dependencies after extraction:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
```

For a host without Composer, select the separately attached **`geo-locator-<version>-standalone.zip`** Release asset. It includes `vendor/` of production, the corresponding lock, dependency licenses and source files. Composer is not needed to run that prepared archive; PHP and its extensions are still required. Verify its SHA-256 against the Release's `SHA256SUMS` before deployment.

Third-party components and their notices are documented in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

### Configuration and first lookup

Store the database and state outside `vendor/` and outside replaceable library directories. Supply the configuration file explicitly; GeoLocator never searches for one. For example, an application at `/srv/my-app` can use:

```dotenv
GEOLOCATION_SECRET=YOUR_GENERATED_BASE64URL_32_BYTE_SECRET
MAXMIND_DATABASE_PATH=/srv/my-app/var/geoip/GeoLite2-City.mmdb
MAXMIND_STATE_DIR=/srv/my-app/var/geoip/.state
```

Generate a real secret and configure the remaining values using [.env.example](.env.example) and the [installation guide](docs/INSTALLATION.md). The text above is a placeholder, not a usable secret. After placing your MMDB at the configured path or running an explicitly configured update:

```php
use GeoLocator\GeoLocatorFactory;

require __DIR__ . '/vendor/autoload.php';

$config = GeoLocatorFactory::configFromEnvironment(__DIR__ . '/private/geolocator.env');
$locator = GeoLocatorFactory::create($config);
$result = $locator->locate('8.8.8.8');
```

Composer installation and autoloading do not download a database. A public lookup without a usable MMDB raises `MaxMindProviderException`; a missing database is not an ordinary `NOT_FOUND` result. The updater runs only when the application explicitly invokes it.

## PHP policy

Use PHP `^8.5.12` in CLI and the web SAPI. The current Composer requirements and runtime guards retain the PHP 9 upper boundary by project decision; PHP 9 compatibility will be assessed when that runtime is available for validation. Apply later stable security/bugfix patches after the project's regression checks. Composer does not install or update the interpreter.

Runtime dependencies are `maxmind-db/reader` and `absolutoffice/xcurl`, with JSON, Phar, zlib and Sodium as declared in `composer.json`. xcurl requires a usable HTTPS backend; cURL is preferred, with streams or direct TLS sockets available when their prerequisites are present.

The PHP 8.5.12/xcurl migration and the security guidance recorded on 25/10/2026 remain documented in [MIGRACION-XCURL.md](MIGRACION-XCURL.md). That dated guidance is a historical snapshot; check the official PHP advisories when deploying.

## Current public core

- `GeoLocator::locate()` / `locateMany()`
- `GeoStatus`: `OK`, `INVALID_IP`, `NON_PUBLIC_IP`, `NOT_FOUND`
- `GeoLocation`, `GeoResult`, `ProviderResult`
- `IpAddress`, `IpType`
- provider/cache/update/debug/trusted-proxy contracts
- typed configuration objects
- `EnvLoader` for explicit consumer-supplied `.env` files
- `GeoLocatorFactory::configFromEnvironment()`, `create()`, `createVisitor()`, `createVisitorForContext()`, `createProxyDiagnostics()` and `createUpdater()`
- `ext-sodium` requirement with a Base64URL 32-byte master secret and separated derived subkeys
- `MaxMindProvider` using the official `maxmind-db/reader` package
- authenticated/versioned visitor-cache payloads without storing the client IP in clear text
- MaxMind database updater with state, lock, validation and atomic replacement

## PHPDoc API documentation

The documentation revision of **25/10/2026** covers every explicitly declared production component in `src/GeoLocator/`: **53 classes, 10 interfaces and 5 enums**. All **205 declared methods**, including constructors and private helpers, have a descriptive PHPDoc block. All **118 properties**, **10 constants** and **17 enum cases** are documented as well. Inherited vendor/PHP members and PHP-generated enum members are outside this count.

Method PHPDoc explains parameter meanings and units, return values and nullable outcomes, collection/callable types, side effects, preconditions and relevant exception propagation. Constructor-promoted properties have their own descriptions. Comments are in English, matching the existing source language. The standalone test helpers and browser-console functions also have PHPDoc; the test scripts describe their scope.

PHPDoc is part of the API contract: maintain the descriptions when changing behavior, especially proxy trust, cache validity, update scheduling, file ownership and HTTP retry rules. PHPStan validates the documented types against the code. The tag conventions follow phpDocumentor's references for [`@param`](https://docs.phpdoc.org/guide/references/phpdoc/tags/param.html), [`@return`](https://docs.phpdoc.org/guide/references/phpdoc/tags/return.html) and [`@throws`](https://docs.phpdoc.org/guide/references/phpdoc/tags/throws.html).

The PHPDoc revision of 25/10/2026 changed comments and documentation only; its token comparison and validation results are recorded in the migration guide. The packaging work dated 26/10/2026 builds on that documented source. New installation examples and tooling carry their own PHPDoc.

## Historical release-candidate validation

The supplied 0.9 RC documentation records the following validation of the previous internal HTTP implementation. This is historical evidence, not a claim that the xcurl migration has already been tested on those production hosts:

- missing MMDB -> provider raises `MaxMindProviderException` rather than `NOT_FOUND`;
- missing MMDB -> updater performs a real download and installs a valid database;
- an actual older GeoLite2 City database -> updater detects and replaces it with the current release;
- immediate second `updateIfDue()` -> `NOT_DUE`, with persisted build/check state;
- shared hosting with `ext-curl` loaded but `curl_exec()` disabled and `allow_url_fopen=Off` -> automatic direct TLS-socket fallback;
- TLS-socket download -> redirect handling, MaxMind SHA-256 verification, archive extraction, MMDB validation, atomic replacement and subsequent public-IP lookup all succeed;
- updater state/lock live under the writable MaxMind data area, while the consumer-owned private `.env` remains read-only;
- visitor cache -> first request miss, second request hit, and IP change invalidates the cached entry through `ipToken`.

The current migration's verification and remaining deployment checks are recorded in [MIGRACION-XCURL.md](MIGRACION-XCURL.md). PHPStan and PHPCS remain release-gate checks when development dependencies are installed.

## Cache security layer

The cache layer includes:

- `CookieAuthenticator`: authenticates payloads with `sodium_crypto_auth()`;
- `IpTokenGenerator`: derives a deterministic authenticated token from the normalized IP without storing the IP itself;
- `GeoCachePolicy`: invalidates entries when the TTL expires, the entry is from the future, the format version changes, or the client IP changes;
- `GeoCacheCodec`: serializes cache entries to versioned JSON, encodes them as Base64URL, and authenticates the encoded payload;
- manipulated, malformed, or unsupported-version cache values decode as a cache miss (`null`) rather than as an application exception;
- values generated internally are immediately round-tripped; if GeoLocator cannot verify its own generated cache value, a `SecurityException` is raised.

`GeoCacheEntry` deliberately does not contain the raw client IP or a complete `GeoResult`. It stores only the cacheable status/location/provider metadata, timestamps and `ipToken`.

## MaxMind provider

`MaxMindProvider`:

- opens the MMDB lazily on the first public-IP lookup;
- reuses the same Reader while the file is unchanged;
- detects an atomic MMDB replacement from file metadata and reopens the Reader;
- opens the replacement Reader before closing the old Reader;
- normalizes country, most-specific subdivision, city, postal code, coordinates, accuracy radius and timezone into `GeoLocation`;
- uses configured preferred locales (default `es,en`) for region/city names;
- returns `ProviderResult::notFound()` for an IP absent from the database;
- wraps technical Reader/database failures in `MaxMindProviderException`.

The MaxMind Reader itself is hidden behind small internal contracts so provider behavior can be tested without Internet access or a real MMDB fixture.

## MaxMind updater

`MaxMindUpdater` is separate from `MaxMindProvider`. The application or scheduler decides when to call `updateIfDue()`; the updater itself enforces `MAXMIND_UPDATE_INTERVAL_DAYS`.

The update flow is:

1. load update state;
2. return `NOT_DUE` without network access when the last successful check is still fresh and the local MMDB is valid;
3. acquire an exclusive filesystem lock and re-read state to avoid duplicate work;
4. issue a HEAD request to the official MaxMind permalink using HTTP Basic Authentication;
5. return `UP_TO_DATE` without downloading when the remote release is not newer;
6. otherwise download the `tar.gz` to a temporary file;
7. verify `Content-Length` when supplied and verify the archive against MaxMind's SHA-256 checksum artifact;
8. extract only `GeoLite2-City.mmdb` to a candidate file on the same filesystem as the final database;
9. validate the candidate with the MaxMind Reader and inspect its `buildEpoch`;
10. atomically rename the validated candidate over the old MMDB;
11. persist `lastCheckAt`, `lastSuccessfulUpdateAt` and `databaseBuildAt`.

Transient HTTP/network failures use xcurl's client policy, with at most three attempts per HTTP operation. `MAXMIND_HTTP_MAX_ATTEMPTS` still counts total attempts; the factory maps it to xcurl's `maxRetries = attempts - 1`. The default backoff is 1 second and then 3 seconds; HTTP `Retry-After` is honored with a 60-second cap. `408`, `429` and `5xx` are retryable, while permanent responses such as `401`, `403` and `404` fail immediately. The MaxMind adapter checks the final HTTP status and translates failures to `UpdateException`.

HTTP retries continue at the current redirect URL, following xcurl's policy. A retry of an object-storage request therefore uses the same signed URL rather than restarting at the MaxMind permalink. The separate integrity redownload starts a fresh MaxMind request. Neither GeoLocator nor xcurl retries authentication failures as a general recovery strategy.

A size or SHA-256 mismatch causes exactly one complete archive redownload. If the second copy still fails integrity verification, the update aborts and removes the temporary archive. Once SHA-256 succeeds, an MMDB validation failure is not treated as a connection error: the archive bytes match the MaxMind checksum, so the existing MMDB is preserved and the update fails closed instead of repeatedly downloading the same artifact.

A corrupt candidate never replaces the existing MMDB. A failed update does not advance `lastCheckAt`, so a failed check is not recorded as successful.

Updater runtime state never belongs beside the consumer-owned `.env`. The private configuration location may be read-only and may also sit outside a hosting provider's writable/open_basedir area. By default GeoLocator stores updater runtime files beside the writable MaxMind database:

```text
<dirname(MAXMIND_DATABASE_PATH)>/.state/maxmind-update.json
<dirname(MAXMIND_DATABASE_PATH)>/.state/maxmind-update.lock
```

With the application-owned database example above, this becomes `/srv/my-app/var/geoip/.state/`. The updater already needs write access to the MMDB directory to perform an atomic replacement. Public lookups do not require the state directory to be writable; only updater execution does.

`MAXMIND_STATE_DIR` optionally overrides the runtime state directory. Relative values are resolved from the GeoLocator package root, like `MAXMIND_DATABASE_PATH`. The pre-0.8.8 name `MAXMIND_STATE_DIRECTORY` remains accepted as a compatibility alias during the pre-1.0 phase.

### Shared hosting and `open_basedir`

On shared hosting, PHP may be restricted by `open_basedir`. GeoLocator does not try to bypass that restriction. The consumer-supplied `.env` path must be readable within the hosting provider's allowed paths. The updater's MMDB directory/state directory must likewise be writable within the allowed area if updates are to run. If the provider permits the `.env` outside the public web root, that remains the preferred layout; if it does not, the consuming application must choose another private/readable configuration source or provide the values through the process/server environment. GeoLocator never silently falls back to a public `.env`.

The HTTP layer is provided by `absolutoffice/xcurl`. Its factory selects usable cURL first, then HTTPS streams, then a direct TLS socket. The socket path supports environments where `curl_exec()` is disabled and `allow_url_fopen=Off`, provided outbound TLS sockets are permitted. Selection is based on runtime capabilities; a failed request does not switch backends. GeoLocator uses the regular `create()` factory, preserving all three choices rather than opting into `createStrict()`, which excludes streams.

The updater fails with `ConfigurationException` when the dependency or a usable transport is unavailable. xcurl verifies TLS, follows up to five HTTPS redirects and removes authorization when the origin changes. GeoLocator supplies Basic Authentication only on the initial official MaxMind request. Download files use exclusive creation and must not already exist, including symlinks; only owned download files are cleaned up. Archive extraction still requires `ext-phar` and `ext-zlib`.

`MAXMIND_HTTP_TIMEOUT_SECONDS` remains a transport timeout. It is not a hard whole-update wall-clock limit: backend I/O, redirects, retries and separate checksum/integrity requests can extend the overall operation. The default migration does not introduce a new deadline or a fixed archive-size limit.

## Environment file ownership

GeoLocator follows the same integration principle as TaxId: **the consuming application owns the location of its `.env` file**. GeoLocator does not infer `projectRoot`, count parent directories, scan for `.env` files, or depend on where the package was installed.

The `.env` is treated as configuration input only. GeoLocator never creates state, locks, downloads, or other runtime files beside it, so the PHP/web-server user only needs read permission on that private location.

The consumer passes the environment file explicitly:

```php
use GeoLocator\GeoLocatorFactory;

$envFile = PROJECT_ROOT . '/../.private/GeoLocator/.env';
$config = GeoLocatorFactory::configFromEnvironment($envFile);
```

`PROJECT_ROOT` in this example belongs to `myProject`; GeoLocator does not define or require that constant. The application may build the path in any other way.

This remains valid whether GeoLocator is installed as:

```text
myProject/GeoLocator/
myProject/lib/GeoLocator/
myProject/vendor/.../GeoLocator/
```

If `configFromEnvironment()` receives `null`, GeoLocator reads only the real process environment (`getenv()`, `$_ENV`, `$_SERVER`) and does **not** auto-discover a file. Process environment values continue to take precedence over values loaded from an explicitly supplied file.

## Database paths and Composer installations

For Composer-managed installations, use an **absolute path to application-owned persistent storage**:

```dotenv
MAXMIND_DATABASE_PATH=/srv/my-app/var/geoip/GeoLite2-City.mmdb
MAXMIND_STATE_DIR=/srv/my-app/var/geoip/.state
```

This keeps the MMDB and updater state independent of dependency updates or removal/reinstallation of `vendor/`. The private configuration file may live in a separate read-only location.

Legacy relative configuration remains supported. Relative `MAXMIND_DATABASE_PATH` and `MAXMIND_STATE_DIR` values are always anchored to the **GeoLocator package root**, not the consuming application's root, the `.env` directory or the process working directory. Thus `db/MaxMind/GeoLite2-City.mmdb` would resolve inside `vendor/absolutoffice/geo-locator/` when installed through Composer. Use it only when you intentionally manage a writable standalone package tree; the public package supplies an empty database directory, not a database.

## HTTP / visitor integration

`VisitorGeoLocator` provides both `locate(HttpContext $context)` and `locateFromGlobals()`. `GeoLocatorFactory::createVisitor()` is the convenience path for the current global request; `createVisitorForContext()` must be used when the consumer supplies an explicit `HttpContext`, so cookie security and client-IP resolution are based on the same request context.

Forwarding headers are ignored unless the immediate peer is trusted. The trusted intermediary may be Apache, nginx, HAProxy, Traefik, a load balancer, another reverse proxy, or a documented chain of them. GeoLocator does not depend on a specific web server.

Cloudflare trust is enabled explicitly with `GEOLOCATION_TRUSTED_PROXY_PROVIDER=cloudflare`; custom IP/CIDR intermediaries can be added with `GEOLOCATION_TRUSTED_PROXIES`. `CF-Connecting-IP` is accepted only when the immediate peer is actually a trusted Cloudflare address.

myProject can provide its existing `is_debug()` through the factory callable. Cookie `Secure` is then resolved from the explicit cookie override or from HTTPS/debug context; `HttpOnly` remains enabled by default.

## myProject integration example

GeoLocator remains independent from myProject. myProject only supplies configuration and, optionally, its existing `is_debug()` callable.

```php
use GeoLocator\GeoLocatorFactory;

$envFile = PROJECT_ROOT . '/../.private/GeoLocator/.env';

$visitor = GeoLocatorFactory::visitorFromEnvironment(
    envFile: $envFile,
    debugResolver: 'is_debug',
);

$geo = $visitor->locateFromGlobals();
```

For myProject, set the private configuration value:

```dotenv
GEOLOCATION_COOKIE_NAME=myproject_geo
```

For code that already owns an explicit request context, create the visitor for that exact context:

```php
use GeoLocator\GeoLocatorFactory;
use GeoLocator\Http\HttpContext;

$envFile = PROJECT_ROOT . '/../.private/GeoLocator/.env';
$config = GeoLocatorFactory::configFromEnvironment($envFile);
$context = HttpContext::fromServerArray($server);
$visitor = GeoLocatorFactory::createVisitorForContext($config, $context, 'is_debug');
$geo = $visitor->locate($context);
```

Proxy diagnostics are read-only and never change the trust configuration:

```php
$diagnostics = GeoLocatorFactory::createProxyDiagnostics($config);
$report = $diagnostics->inspect($context);
```

The updater is intentionally separate from visitor lookups:

```php
$updater = GeoLocatorFactory::createUpdater($config);
$updateResult = $updater->updateIfDue();
```

Application code decides whether and where to catch `GeoLocatorException` subclasses. GeoLocator does not log, render an error page or call `die()`/`exit()`.

## Tests and release gates

In a repository checkout with development dependencies installed:

```bash
composer install
composer validate --strict
composer check-platform-reqs
composer test
composer lint
composer analyse
composer cs
```

The standalone bundle includes the regression tests and lint runner, so `php tests/index.php` and `php tools/lint.php` also work without development dependencies. PHPStan and PHPCS require a development installation. Tests supplied in an installed dependency are development resources; run maintenance scripts in a dedicated checkout instead of modifying an application's dependency tree.

For a release, start with the validated checkout, install its locked production dependencies and build the standalone asset:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-scripts --no-interaction
php tools/build-release.php --version=1.0.0 --output=dist
```

For this release, `VERSION` is `1.0.0`; the builder rejects a stable artifact version that does not match it. It also checks the production dependency set against `composer.lock` and creates the ZIP and `SHA256SUMS`. See [docs/RELEASING.md](docs/RELEASING.md) for CI, tags, GitHub Release assets and optional Packagist registration.

## Browser test console

`tests/index.php` follows the same dual-purpose approach as TaxId:

```bash
php tests/index.php
```

runs the complete local test suite, while opening `/GeoLocator/tests/` in a browser provides an interactive integration console. The browser console never displays `GEOLOCATION_SECRET` or MaxMind credentials. The real updater is only executed after an explicit form submission.

The browser console is itself a consumer, so it also needs an explicit environment path. For local development, copy `tests/config.local.php.example` to `tests/config.local.php` and set `envFile`, or provide `GEOLOCATION_ENV_FILE` as a PHP constant/process environment variable. `tests/config.local.php` is ignored by Git. These are test-harness conveniences; the GeoLocator library itself does not search for the file.

For local development, the browser console can simulate the visitor `REMOTE_ADDR` through a validated `simulate_ip` query value. The simulation exists only in `tests/index.php`: production classes are unchanged. While simulation is active, forwarding headers are removed from the simulated `HttpContext` so proxy headers cannot override the selected test address. This makes it possible to exercise visitor-cache behavior from a `192.168.x.x`, `10.x.x.x`, loopback or other non-public development network.

A useful manual cache scenario is: select `8.8.8.8`, reload until `cacheHit=true`, then switch to `1.1.1.1`; the changed IP must invalidate the previous entry through `ipToken` and the first lookup for the new address must return `cacheHit=false`.

Local/debug detection also recognizes loopback and private IP hosts directly (for example `127.0.0.1:8000` or `192.168.1.20`) when `SERVER_ADDR` is unavailable, so HTTP development cookies are not incorrectly forced to `Secure`.

### Development without Composer autoload

`config/environment.php` loads `vendor/autoload.php` when present and otherwise registers a small PSR-4 fallback for GeoLocator's own classes. This fallback does not load external dependencies: public MaxMind lookups require `maxmind-db/reader`, and the updater plus its HTTP integration tests require `absolutoffice/xcurl`. Install Composer dependencies before running the complete suite. The browser diagnostics report an absent xcurl dependency rather than referring to the removed internal transports.

## Example consumer layout

One possible application layout is:

| Path | Responsibility |
| --- | --- |
| `/srv/my-app/vendor/autoload.php` | Consumer Composer autoloader |
| `/srv/my-app/vendor/absolutoffice/geo-locator/` | Replaceable package code |
| `/srv/my-app/private/geolocator.env` | Consumer-owned private configuration, readable by PHP |
| `/srv/my-app/var/geoip/GeoLite2-City.mmdb` | Persistent application-owned database |
| `/srv/my-app/var/geoip/.state/` | Writable updater state and lock files |
| `/srv/my-app/public/` | Public web document root |

The application passes `/srv/my-app/private/geolocator.env` explicitly and configures absolute MMDB/state paths. For the standalone asset, the code and its bundled autoloader may instead live at `/srv/lib/geo-locator/`; the same private configuration and database locations still work.
