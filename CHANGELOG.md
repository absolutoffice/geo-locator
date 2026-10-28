# Changelog

## Unreleased

No unreleased changes.

## 1.0.0 - 2026-10-28

### Composer installation and standalone release assets

- Prepare a clean source tree for the existing Composer identity `absolutoffice/geo-locator`, preserving its PSR-4 namespace, PHP `^8.5.12` requirement and PHP 9 runtime boundary.
- Separate public source distribution from a standalone Release asset containing the locked production dependencies and their licenses.
- Exclude installation-specific credentials, MMDB files, backups, updater state, IDE metadata and generated output from the public package.
- Add installation examples and an offline consumer-autoload integration test; document explicit application-owned configuration and absolute persistent data paths outside `vendor/`.
- Add build/lint tooling and GitHub CI/Release workflows, with a SHA-256 manifest for the standalone asset.
- Document direct Composer VCS installation, GitHub source downloads, the standalone asset, version tags and optional Packagist registration.
- Assign the initial stable source release identifier `1.0.0` in `VERSION`; Composer continues to derive the installable package version from Git branches and tags rather than a manual `composer.json` field.

### 2026-10-25 - PHP 8.5.12 and xcurl migration

- Complete descriptive PHPDoc for all 68 production class/interface/enum declarations, 205 methods, 118 properties, 10 constants and 17 enum cases.
- Document parameter/return semantics, units, null outcomes, collection/callable shapes, preconditions, side effects and propagated exceptions, including private helpers and promoted properties.
- Complete PHPDoc for standalone test fixtures, helper functions and browser-console functions, with file-level descriptions of test scope.
- Verify the PHPDoc revision with PHP 8.5.12 lint, the 10-file test suite, maximum-level PHPStan and PHPCS; compare tokenized PHP with the previous xcurl build to confirm the revision changes documentation only.
- Require PHP `^8.5.12` and the published Composer package `absolutoffice/xcurl:^1.1`.
- Delegate HTTPS transports, capability diagnostics, redirects and HTTP retries to xcurl; remove `GeoLocator\Update\Http` and its duplicated networking implementation.
- Make `MaxMindHttpDownloadClient` a provider adapter around `AbsolutOffice\XCurl\Client\HttpClient`; its constructor now takes `client` instead of `transport`, `retryPolicy` and `sleeper`.
- Preserve `MaxMindDownloadClientInterface`, HEAD/Last-Modified, checksum verification, one integrity redownload, MMDB validation, locking and atomic installation.
- Map total HTTP attempts to xcurl's retry count (`attempts - 1`) and translate final HTTP/client failures to GeoLocator exceptions.
- Follow xcurl's redirect semantics: strip credentials across origins and retry the current signed storage URL after a redirect.
- Reject existing download targets and symlinks; retain xcurl's ownership-aware temporary-file handling.
- Update the deprecated native download wrapper, tests, browser diagnostics and deployment documentation.
- Document the PHP security guidance published on 21/10/2026 and 23/10/2026, separating runtime upgrades from Composer package changes.
- Historical 0.9 RC real-host validation below refers to the former HTTP implementation; current verification is recorded in `MIGRACION-XCURL.md`.

## 0.9.0-rc1 - 2026-09-20 - Release candidate consolidation

- Freeze the planned 1.0 public feature set; this release candidate is for bug fixing, compatibility verification and documentation cleanup rather than new functionality.
- Record successful end-to-end MaxMind updater validation against the real service with both a missing MMDB and an actually older GeoLite2 City database.
- Record successful shared-hosting validation where `curl_exec()` is disabled and `allow_url_fopen=Off`; the updater selected the direct TLS-socket transport and completed redirect handling, SHA-256 verification, extraction, validation and atomic installation.
- Record successful post-update provider lookup and immediate second updater call returning `NOT_DUE`.
- Confirm runtime state/lock operation under `db/MaxMind/.state/` while the consumer-owned private `.env` remains read-only.
- Correct the final README deployment tree, which still showed the obsolete pre-0.8.8 `state/` directory beside `.env`.
- Add an explicit RC validation section and clarify that PHPStan/PHPCS remain release-gate checks when their development tools are available.
- Ensure a final HTTP transport failure removes any partially written archive before propagating `UpdateException`; add a regression test for that cleanup contract.

## 0.8.10 - 2026-09-20 - Direct TLS-socket fallback for restricted hosting

- Add `SocketHttpTransport`, implemented on `stream_socket_client()` with TLS peer/hostname verification and no dependency on `allow_url_fopen`.
- Select HTTP transports in order: complete cURL runtime, HTTPS streams, direct TLS socket.
- Support HEAD/GET, `Content-Length`, `Transfer-Encoding: chunked`, connection-close bodies, timeouts and local-file streaming without loading the MaxMind archive into memory.
- Keep redirects and MaxMind credential scoping in `MaxMindHttpDownloadClient`, so redirected object-storage hosts never receive the Account ID/License Key authorization header.
- Extend browser diagnostics with OpenSSL, TLS transport registration, socket-function availability and socket-transport usability.
- Add deterministic socket transport tests, including chunked downloads and truncated-response failure handling.
- Cover the shared-hosting case where `curl_exec()` is disabled and `allow_url_fopen=Off`, but outbound TLS sockets are available.

## 0.8.9 - 2026-09-20 - Shared-hosting HTTP transport hardening

- Treat cURL as usable only when every cURL function actually used by the updater is available; this covers hosting providers that load `ext-curl` but disable functions such as `curl_exec()`.
- Fall back automatically to HTTPS streams when the cURL runtime is incomplete instead of selecting a transport that will fatal at request time.
- Harden HTTPS-stream capability detection in the same way by checking the stream functions used by the transport before selecting it.
- Add `HttpTransportFactory::selectedTransportName()` for diagnostics.
- Extend `tests/index.php` with cURL/stream capability details and the transport selected by the updater.
- Add a regression test for the real ISP case where `curl_exec()` is unavailable.

## 0.8.8 - 2026-09-18 - Read-only private configuration and runtime state separation

- Keep the consumer-owned `.env` location strictly read-only from GeoLocator's perspective; no state, lock or download files are created beside it.
- Default updater state to `dirname(MAXMIND_DATABASE_PATH)/.state`, normally `db/MaxMind/.state/`.
- Add canonical `MAXMIND_STATE_DIR` override; relative values are package-root relative.
- Keep `MAXMIND_STATE_DIRECTORY` as a temporary pre-1.0 compatibility alias.
- Extend the browser test console with `open_basedir`, MMDB-directory and updater-state writability diagnostics for shared-hosting validation.
- Preserve normal public lookups when updater runtime paths are not writable; write access is required only when the updater is executed.

## 0.8.7 - Explicit consumer-owned environment path

- Align GeoLocator environment loading with the established TaxId pattern: the consuming application supplies the `.env` path explicitly.
- Remove automatic `.env` discovery and all `packageRoot -> baseDir -> .private` inference.
- Keep process environment precedence; `configFromEnvironment(null)` now uses process environment only.
- Introduce `PackagePathResolver` so relative `MAXMIND_DATABASE_PATH` values remain anchored to the GeoLocator package itself regardless of installation under `./GeoLocator`, `./lib/GeoLocator`, or another location.
- Default updater state to `<dirname(envFile)>/state` only when an explicit environment file is supplied; otherwise `MAXMIND_STATE_DIRECTORY` must be supplied to create the updater.
- Add browser-test support for explicit `GEOLOCATION_ENV_FILE` or ignored `tests/config.local.php`, without adding any `.env` search behavior to the library.
- Add regression tests proving that a nearby private `.env` is not auto-discovered and that both `./GeoLocator` and `./lib/GeoLocator` package roots resolve the MMDB correctly.

## 0.8.6 - 2026-09-17

- Make the private environment convention explicit: `baseDir/../.private/GeoLocator/.env`.
- Define `baseDir` as the directory that contains the GeoLocator package.
- Example deployment: `/var/www/geoLocator` resolves the environment file to `/var/.private/GeoLocator/.env`.
- Add regression tests for package root, baseDir and private environment resolution.
- Improve browser test diagnostics so configuration errors show the expected `.env` path and readability.

## 0.8.5 - Project-relative MaxMind path and generic consumer naming

- Resolve relative `MAXMIND_DATABASE_PATH` values from the GeoLocator project root (directory containing `composer.json`).
- Keep absolute MaxMind database paths supported unchanged.
- Recommend `db/MaxMind/GeoLite2-City.mmdb` in `.env.example`.
- Replace application-specific documentation wording with the generic consumer name `myProject`.

## 0.8.4 - Resilient test bootstrap

- `tests/index.php` no longer requires `config/environment.php` to exist.
- If the shared environment loader is missing, the browser console falls back locally to Composer autoload when available or GeoLocator's internal PSR-4 loader otherwise.
- `tests/bootstrap.php` uses the same fallback, so copied test files remain usable on an older 0.8.2-style tree.
- This specifically supports replacing only `tests/index.php` during development without forcing a full package extraction first.

## 0.8.3 - Development autoload fallback

- Added `config/environment.php`, matching the TaxId development pattern: Composer autoload is preferred and GeoLocator's own PSR-4 fallback is registered when `vendor/autoload.php` is absent.
- `tests/index.php` no longer aborts only because Composer has not been installed.
- Browser diagnostics now show whether Composer autoload and `maxmind-db/reader` are available.
- Public MaxMind lookups report a local actionable warning when `maxmind-db/reader` is unavailable; non-provider tests remain usable.
- `tests/bootstrap.php` now reuses the same runtime environment loader.

## 0.8.2 - Local visitor IP simulation

- Added browser-only visitor IP simulation to `tests/index.php` for local/private-network development.
- The simulated address is strictly validated with `FILTER_VALIDATE_IP` and never changes production classes or the global `$_SERVER`.
- The simulated `HttpContext` removes forwarding headers so the selected address cannot be overridden by proxy metadata.
- Added quick selectors for public, private and loopback IPv4/IPv6 addresses plus a one-click return to the real peer address.
- Preserved the simulation query across manual lookup/update POST actions.
- Added an explicit manual cache-change scenario (`8.8.8.8` -> reload -> `1.1.1.1`) to verify `ipToken` invalidation.
- Hardened `EnvironmentDetector` so loopback/private IP hosts are recognized as local even when `SERVER_ADDR` is unavailable (for example PHP's built-in development server).

## 0.8 - Integration hardening

- Added context-aware visitor construction with `GeoLocatorFactory::createVisitorForContext()`.
- Added `GeoLocatorFactory::visitorForContextFromEnvironment()`.
- Added `GeoLocatorFactory::createProxyDiagnostics()`.
- Centralized automatic cookie `Secure` resolution in `CookieSecureResolver`.
- Preserved `createVisitor()` / `visitorFromEnvironment()` as global-request convenience APIs.
- Added integration tests for explicit debug mode, environment fallback, `SameSite=None`, trusted-proxy diagnostics and explicit `HttpContext` handling.
- Added `composer test` script and optional `ext-curl` suggestion.
- Added the LGPL-3.0 license file matching `LGPL-3.0-or-later` package metadata.
- Re-verified embedded Cloudflare origin-facing ranges against the official definitive list on 2026-09-16.

## 0.7 - Updater retry and integrity hardening

- Added transport abstraction with cURL preference and HTTPS-stream fallback.
- Added retry policy for transient network/HTTP failures.
- Added Content-Length and MaxMind SHA-256 verification with one full integrity redownload.
- Kept credentials restricted to the MaxMind download host across redirects.

## 0.8.1 - Browser test console

- Reworked `tests/index.php` to mirror the TaxId dual-purpose test console.
- CLI mode discovers and runs every `*Test.php` file and prints a compact pass/fail summary.
- Browser mode provides an interactive integration page for configuration, MMDB health, HTTP context, trusted proxies, visitor cache, manual IP lookups and MaxMind updates.
- Secrets and MaxMind credentials are never rendered; only configuration-presence flags are shown.
- Real MaxMind update remains an explicit POST action.
