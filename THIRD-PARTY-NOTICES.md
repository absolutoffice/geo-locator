# Third-party notices

GeoLocator's own source is distributed under `LGPL-3.0-or-later`; see [LICENSE](LICENSE).

Composer installs the following production dependencies. The standalone download bundles their source files and original license notices under `vendor/`.

| Package | License declared by the package | Upstream source |
| --- | --- | --- |
| `absolutoffice/xcurl` | `LGPL-3.0-only` | <https://github.com/absolutoffice/xcurl> |
| `maxmind-db/reader` | `Apache-2.0` | <https://github.com/maxmind/MaxMind-DB-Reader-php> |

The exact bundled versions and source references are recorded in `composer.lock` and `vendor/composer/installed.json`. Preserve each dependency's own license file when redistributing the standalone package. Composer's generated autoloader includes its own license notice under `vendor/composer/`.

The MaxMind database reader is a software dependency. GeoLite2/GeoIP database files and account credentials are supplied separately by the consuming application and are not included in these public distributions.

Development tools are declared in `require-dev` and are not bundled in the standalone production download.
