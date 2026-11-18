# Registro de validación — geoLocator 1.0.1

**Estado a 18/11/2026: candidato sin tag; validación funcional aportada y superada en PHP 8.4.27 y 8.5.12 según los informes de `docs/validation/`; CI, tag `v1.0.1` y release siguen pendientes.**

Este registro distingue las comprobaciones efectuadas sobre la distribución fuente de las que deben ejecutarse en el equipo de validación. No se asigna el estado «OK» a una prueba que no se haya ejecutado. Los resultados de los equipos de validación se citan siempre con atribución (§2 ter) y no se presentan como reejecuciones locales.

## 1. Identificación y límites de compatibilidad

| Dato | Valor |
| --- | --- |
| Proyecto | `absolutoffice/geo-locator` |
| Versión fuente (`VERSION`) | `1.0.1` |
| Paquete HTTP | `absolutoffice/xcurl:1.1.1` (versión exacta) |
| PHP 8.4 | `>=8.4.27 <8.5` |
| PHP 8.5 | `>=8.5.12 <8.6` |
| PHP 8.6 y 9 | No admitidos por esta combinación |
| Repositorio privado de revisión | `damasosc/geo-locator` |
| Destino final previsto | `absolutoffice/geo-locator` |

La restricción de PHP se corresponde con el `require.php` del `composer.json` de **xCurl `v1.1.1`**: `>=8.4.27 <8.5 || >=8.5.12 <8.6`. Referencia pública: <https://github.com/absolutoffice/xcurl/blob/v1.1.1/composer.json>.

## 2. Evidencia obtenida en el entorno de preparación

| Comprobación | Resultado | Alcance |
| --- | --- | --- |
| `VERSION` frente a documentos principales | OK | `1.0.1` |
| Dependencia declarada y `composer.lock` | OK | xCurl `1.1.1`, tag `v1.1.1`, ref. `055048cc51c91a9bacca663ea3d809fb1a94da92` |
| Sintaxis PHP con `php tools/lint.php` | OK | 87 archivos, 0 errores, usando PHP 8.4.24 |
| Integridad del ZIP fuente revisado antes de añadir este registro | OK | Archivo comprimido sin errores; el ZIP definitivo debe comprobarse de nuevo |
| Sintaxis del verificador Bash (`bash -n`) | OK | Sin errores de análisis sintáctico |
| Suite de regresión completa | OK en validación (informe; §2 ter) | Ejecutada en PHP 8.4.27 y 8.5.12; la preparación (8.4.24) sigue sin cumplir el mínimo |
| `composer check`, PHPStan y PHPCS | OK en validación (informe; §2 ter) | Composer no está disponible en el entorno de preparación |
| Instalación desde una aplicación independiente | OK en validación (informe; §2 ter) | Con repositorio `path`; la instalación desde el repositorio remito y el tag definitivo queda pendiente |
| Construcción de release autónoma y SHA-256 | OK en validación (informe; §2 ter) | Solo en la ejecución PHP 8.5; en PHP 8.4 el modo `--release` no se solicitó |
| GitHub Actions y tag `v1.0.1` | PENDIENTE | CI nunca ejecutada; no crear el tag hasta tenerla en verde |

**Importante:** el «OK» de sintaxis en PHP 8.4.24 no demuestra compatibilidad funcional con 8.4.27 ni con 8.5.12. El paquete bloquea correctamente la suite si se ejecuta con un PHP no admitido.


## 2 bis. Primera ejecución en Vulcano — 18/11/2026

Resultados aportados por el responsable de la validación tras ejecutar la primera entrega del script, **antes de aplicar el ajuste de Composer**. Estos resultados se conservan para trazabilidad y no equivalen a la validación completa. Los logs locales están en `var/validation/1.0.1/`, fuera de Git.

| Comprobación | PHP 8.5.12 (`php`, `--release`) | PHP 8.4.27 (`php8.4`) |
| --- | --- | --- |
| Intérprete compatible con xCurl 1.1.1 | OK | OK |
| Sintaxis PHP | OK | OK |
| CLI Composer | OK | OK |
| `composer validate --strict` (script anterior) | **ERROR 1** | **ERROR 1** |
| Instalación Composer con dependencias de desarrollo | OK | OK |
| `composer check-platform-reqs` | OK | OK |
| `composer check` | **ERROR 1** (bloqueado por la validación previa) | **ERROR 1** (bloqueado por la validación previa) |
| Suite de contingencia sin `curl_exec` ni `allow_url_fopen` | OK | OK |
| Instalación Composer desde aplicación independiente con repositorio `path` | OK | OK |
| Instalación y comprobación de plataforma en modo producción | OK | No solicitada |
| Construcción de ZIP autónomo, SHA-256 e integridad | OK | No solicitada |
| Restauración de dependencias de desarrollo | OK | No solicitada |
| Suite normal, PHPStan y PHPCS dentro de `composer check` | **NO EJECUTADOS** | **NO EJECUTADOS** |

**Diagnóstico:** Composer no pudo deducir la versión raíz del checkout sin tag y mostró `defaulting to '1.0.0'`. La advertencia que provocó el código de salida 1 fue `exact version constraints (1.1.1) should be avoided`; `--strict` convierte este aviso en error. El segundo fallo de `composer check` es consecuencia del mismo motivo, no un fallo observado de los tests, PHPStan ni PHPCS. En PHP 8.4, Xdebug también emitió avisos de conexión y archivo de log que no explican la salida 1.

**Ajuste del candidato:** mantener `absolutoffice/xcurl:1.1.1` exacta y el lock intactos; cambiar los comandos de comprobación actuales a `validate --strict --no-check-all`; identificar la versión raíz de forma temporal en el verificador (`COMPOSER_ROOT_VERSION=1.0.1`) y desactivar el modo debug de Xdebug en su entorno CLI. `--no-check-all` suprime la advertencia de restricción de versiones pero mantiene el control del lock y las restantes comprobaciones estrictas. Documentación oficial: <https://getcomposer.org/doc/03-cli.md#validate> y <https://getcomposer.org/doc/articles/troubleshooting.md#root-package-version-detection>.

**Estado tras el ajuste (histórico):** en el momento del ajuste, las ejecuciones nuevas de las dos ramas y sus informes estaban pendientes; los resultados posteriores, ya exitosos, se documentan en la §2 ter. Esta primera ejecución fallida se conserva únicamente como trazabilidad histórica y no invalida la evidencia posterior. La CI y el tag `v1.0.1` siguen **PENDIENTES**.

## 2 ter. Ejecución en Vulcano tras el ajuste — 18/11/2026

Resultados **aportados** por el responsable de la validación mediante los informes versionados `docs/validation/1.0.1-php85.md` y `docs/validation/1.0.1-php84.md`, generados por `tools/verify-1.0.1.sh` con los ajustes descritos en la §2 bis. Este registro **no reejecuta** las pruebas localmente: el entorno de preparación no dispone de un PHP compatible. Los logs completos siguen en `var/validation/1.0.1/`, fuera de Git.

| Comprobación | PHP 8.5.12 (19:11:22, modo `--release`) | PHP 8.4.27 (19:11:45) |
| --- | --- | --- |
| Intérprete compatible y `VERSION` 1.0.1 | OK | OK |
| `composer validate --strict --no-check-all` con `COMPOSER_ROOT_VERSION=1.0.1` | OK | OK |
| Instalación Composer con dependencias de desarrollo y requisitos de plataforma | OK | OK |
| xCurl `1.1.1` instalado realmente | OK | OK |
| `composer check` (validate, lint, tests, PHPStan, PHPCS) | OK | OK |
| Suite sin `curl_exec` ni `allow_url_fopen` | OK | OK |
| Instalación Composer desde aplicación independiente (repositorio `path`) | OK | OK |
| Instalación y comprobación de plataforma en modo producción | OK | No solicitada |
| Construcción de ZIP autónomo 1.0.1, SHA-256 e integridad | OK | No solicitada (`--release` no se pidió en esta rama) |
| Conservar ZIP anterior y restaurar dependencias de desarrollo | OK | No solicitada |

Estos resultados corresponden a la ejecución corregida tras la §2 bis: comprobación de Composer con `--no-check-all`, identificación temporal de la versión raíz y `XDEBUG_MODE=off`, suite completa mediante `composer check`, prueba de consumidor independiente y, en PHP 8.5, el build autónomo con verificación SHA-256.

**Pendiente de publicación:** GitHub Actions en verde sobre el commit de publicación (la CI nunca se ha ejecutado), el tag `v1.0.1`, la GitHub Release generada por el workflow y la instalación desde el repositorio remito con el tag definitivo. No se crea el tag todavía.

## 3. Comandos en el equipo de validación

Carpeta de trabajo acordada:

```bash
cd /home/Apache24/html/paquetes/geo-locator
```

Identificar primero los binarios disponibles (el alias exacto depende de la instalación):

```bash
command -v php
php -v
command -v php8.4 || true
php8.4 -v
```

Si `php` apunta a PHP 8.5.12 o posterior **dentro de la rama 8.5.x**, ejecutar la matriz secuencialmente:

```bash
bash tools/verify-1.0.1.sh php --release
bash tools/verify-1.0.1.sh php8.4
```

La opción `--release` prueba además el ZIP autónomo con dependencias de producción, comprueba `SHA256SUMS` y restaura las dependencias de desarrollo. Si ya hay un ZIP o un `SHA256SUMS` de una ejecución anterior, el verificador los guarda previamente en `var/validation/1.0.1/php85/previous-release-*/` (fuera de Git) para permitir una construcción limpia sin perder la evidencia previa. Si las versiones PHP están en rutas diferentes, pasar **la ruta absoluta del binario** en lugar de `php` o `php8.4`. No hay necesidad de modificar el PHP predeterminado del servidor cuando ambos CLI están accesibles.

En la revisión vigente, la comprobación de Composer se hace con `validate --strict --no-check-all` para permitir el pin exacto de xCurl sin dejar de validar manifiesto, resto de avisos y lock. El verificador configura `COMPOSER_ROOT_VERSION=1.0.1` **solo durante esta ejecución** para evitar el mensaje de fallback a 1.0.0; no modifica la versión pública del paquete, que dependerá del tag. El entorno CLI del script usa `XDEBUG_MODE=off` por defecto para evitar intentos de conexión de Xdebug.

El script lanza **Composer con el mismo binario PHP elegido**; ello evita validar la suite en una versión y resolver dependencias con otra. Composer 2 debe estar instalado. Si `composer` es un wrapper no ejecutable por PHP, definir `COMPOSER_CLI=/ruta/a/composer.phar` antes de invocar el script.

> Si `php8.4 -v` indica una versión anterior a 8.4.27, la verificación de PHP 8.4 quedará **BLOQUEADA**, no aprobada; hay que seleccionar un binario actualizado. Ocurre lo mismo con versiones 8.5 anteriores a 8.5.12.

## 4. Resultados y registros para Git

El verificador genera un informe Markdown por rama de PHP:

- `docs/validation/1.0.1-php85.md` (ejecución con PHP 8.5).
- `docs/validation/1.0.1-php84.md` (ejecución con PHP 8.4).

Los logs completos se guardan en `var/validation/1.0.1/php85/` y `var/validation/1.0.1/php84/`, respectivamente. **La carpeta `var/` está excluida de Git**; los resúmenes Markdown sí pueden versionarse. Antes de compartir los logs, revisar posibles rutas privadas o información de entorno.

Por cada intérprete se comprueban la versión PHP, sintaxis, validación e instalación Composer, requisitos de plataforma, instalación exacta de xCurl 1.1.1, `composer check` (tests, PHPStan, PHPCS), fallback sin `curl_exec` y prueba de instalación desde una **aplicación Composer independiente** con repositorio local `path` para el candidato `1.0.1`. Esta última valida el paquete fuente antes de tener un tag Git; la instalación desde el repositorio remoto y el tag definitivo deberán comprobarse aparte.

Para registrar los resultados comprobados en la copia local de Git, después de revisar su contenido:

```bash
git status --short
git add docs/VALIDACION-1.0.1.md docs/validation/ tools/verify-1.0.1.sh
git diff --cached --check
git diff --cached --stat
```

No añadir `vendor/`, `var/`, `dist/`, `.env`, licencias privadas, archivos MMDB o credenciales. Los cambios de código fuente y documentación del candidato pueden ir en el mismo commit después de revisarlos.

## 5. Criterio para liberar v1.0.1

De los requisitos de publicación, los locales constan como superados en los informes aportados (§2 ter): pruebas sin errores en PHP 8.4.27 y 8.5.12 compatibles; dependencias y requisitos de plataforma comprobados; instalación Composer desde una aplicación independiente (repositorio `path`); análisis estático y PHPCS mediante `composer check`; build autónomo y SHA-256 verificados en la rama PHP 8.5. Quedan pendientes, y bloquean la publicación: GitHub Actions en verde sobre el commit de publicación, el tag `v1.0.1`, la GitHub Release generada por el workflow y la instalación desde el repositorio remito con el tag definitivo. Solo entonces dar la versión por publicada.

Las pruebas de conectividad real a MaxMind o de integración en un hosting específico necesitan sus propias credenciales y entorno controlado; la suite offline no sustituye esas comprobaciones operativas.
