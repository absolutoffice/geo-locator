#!/usr/bin/env bash
# Run GeoLocator 1.0.1 checks with a specific PHP CLI binary.
# The selected interpreter is also used to launch Composer, its scripts and tests.
# Reports in docs/validation/ are intended for Git; detailed logs in var/ are ignored.
set -uo pipefail

# Only affects CLI verification; prevents noisy Xdebug debug-session attempts.
export XDEBUG_MODE="${XDEBUG_MODE:-off}"

usage() {
    cat <<'HELP'
Usage: bash tools/verify-1.0.1.sh [php-command-or-absolute-path] [--release]

Examples:
  bash tools/verify-1.0.1.sh php
  bash tools/verify-1.0.1.sh php8.4
  bash tools/verify-1.0.1.sh /path/to/php8.5 --release

The optional --release step switches vendor/ to production dependencies,
checks the standalone ZIP and restores development dependencies afterward.

If Composer is not a directly executable PHP file, set COMPOSER_CLI to the
absolute path of composer.phar (or to a PHP-based Composer executable):
  COMPOSER_CLI=/path/to/composer.phar bash tools/verify-1.0.1.sh php8.4
HELP
}

case "${1:-}" in
    -h|--help) usage; exit 0 ;;
esac

if (( $# > 2 )) || { (( $# == 2 )) && [[ "${2:-}" != '--release' ]]; }; then
    usage >&2
    exit 2
fi

php_command="${1:-php}"
release_mode="${2:-}"
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)" || exit 1
cd -- "$root" || exit 1

if ! php_bin="$(command -v -- "$php_command" 2>/dev/null)" || [[ ! -f "$php_bin" ]]; then
    printf 'No encuentro el ejecutable PHP: %s\n' "$php_command" >&2
    printf 'Comprueba su ubicación con: command -v php; command -v php8.4; command -v php8.5\n' >&2
    exit 2
fi

if ! php_version="$("$php_bin" -r 'echo PHP_VERSION;' 2>/dev/null)" || \
   ! php_id="$("$php_bin" -r 'echo PHP_VERSION_ID;' 2>/dev/null)" || \
   ! php_branch="$("$php_bin" -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;' 2>/dev/null)"; then
    printf 'No se puede utilizar el binario PHP: %s\n' "$php_bin" >&2
    exit 2
fi

case "$php_branch" in
    8.4) report_suffix='php84' ;;
    8.5) report_suffix='php85' ;;
    *) report_suffix="php${php_branch//./}" ;;
esac

report_dir="$root/docs/validation"
log_dir="$root/var/validation/1.0.1/$report_suffix"
report="$report_dir/1.0.1-$report_suffix.md"
mkdir -p -- "$report_dir" "$log_dir"

labels=()
statuses=()
log_names=()
errors=0
notes=()

record() {
    labels+=("$1")
    statuses+=("$2")
    log_names+=("$3")
}

run_logged() {
    local label="$1" slug="$2" exit_code
    shift 2
    printf '\n==> %s\n' "$label"
    if "$@" > "$log_dir/$slug.log" 2>&1; then
        printf '[OK] %s\n' "$label"
        record "$label" 'OK' "$slug.log"
        return 0
    else
        exit_code=$?
        printf '[ERROR %d] %s\n' "$exit_code" "$label" >&2
        tail -n 22 "$log_dir/$slug.log" >&2 || true
        record "$label" "ERROR ($exit_code)" "$slug.log"
        errors=$((errors + 1))
        return 1
    fi
}

skip() {
    printf '[PENDIENTE] %s: %s\n' "$1" "$2"
    record "$1" 'PENDIENTE' '-'
    notes+=("$1: $2")
}

write_report() {
    local date_time
    date_time="$(date '+%d/%m/%Y %H:%M:%S %z')"
    {
        printf '# Validación local de geoLocator 1.0.1 — PHP %s\n\n' "$php_branch"
        printf -- '- Fecha de ejecución: **%s**.\n' "$date_time"
        printf -- '- Versión probada: **1.0.1** (`VERSION`).\n'
        printf -- '- Ejecutable PHP: `%s`.\n' "$php_bin"
        printf -- '- PHP CLI: **%s**.\n' "$php_version"
        printf -- '- Composer: `%s`.\n' "${composer_cli:-no disponible}"
        printf -- '- Dependencia requerida: `absolutoffice/xcurl:1.1.1` (restricción exacta intencionada).\n'
        printf -- '- Identificación local: `COMPOSER_ROOT_VERSION=1.0.1` (no modifica Composer ni el tag).\n'
        printf -- '- Validación Composer: `--strict --no-check-all` (omite solo avisos sobre restricciones de versión).\n'
        printf -- '- Modo de construcción del ZIP autónomo: **%s**.\n\n' "${release_mode:---release no solicitado}"
        printf '| Comprobación | Resultado | Registro local (no se incluye en Git) |\n'
        printf '| --- | --- | --- |\n'
        for i in "${!labels[@]}"; do
            printf '| %s | **%s** | `%s` |\n' "${labels[$i]}" "${statuses[$i]}" "${log_names[$i]}"
        done
        if (( ${#notes[@]} > 0 )); then
            printf '\n## Comprobaciones pendientes y motivos\n\n'
            for note in "${notes[@]}"; do
                printf -- '- %s.\n' "$note"
            done
        fi
        printf '\n## Criterio de publicación\n\n'
        if (( errors == 0 && ${#notes[@]} == 0 )); then
            printf 'Las comprobaciones **seleccionadas en esta ejecución** se completaron sin errores. '
            printf 'La publicación requiere también la otra rama PHP, GitHub Actions y la revisión del tag.\n'
        else
            printf '**No se ha acreditado un resultado global satisfactorio.** Revisar errores y pendientes antes de publicar el tag `v1.0.1`.\n'
        fi
        printf '\nLos registros completos permanecen en `var/validation/1.0.1/%s/` ' "$report_suffix"
        printf '(ignorado por `.gitignore`); este resumen sí está destinado a Git.\n'
        printf 'No incluir secretos, `.env`, bases MMDB ni capturas con credenciales en los commits.\n'
    } > "$report"
    printf '\nInforme para Git: %s\n' "$report"
    printf 'Logs locales: %s\n' "$log_dir"
}

composer_cli="${COMPOSER_CLI:-$(command -v composer 2>/dev/null || true)}"

printf 'GeoLocator 1.0.1 | PHP %s (%s)\n' "$php_version" "$php_bin"
printf 'Proyecto: %s\n' "$root"

if [[ "$(tr -d '\r\n' < VERSION)" != '1.0.1' ]]; then
    printf 'ERROR: VERSION no es 1.0.1. No se ejecutan pruebas sobre otro árbol.\n' >&2
    record 'VERSION del proyecto' 'ERROR' '-'
    errors=$((errors + 1))
    write_report
    exit 1
fi
record 'VERSION del proyecto' 'OK' '-'

# Source checkout has not been tagged; avoid Composer incorrectly defaulting to 1.0.0.
# Do not add a hard-coded version to composer.json (VCS tags remain authoritative).
export COMPOSER_ROOT_VERSION='1.0.1'

if [[ "$php_id" =~ ^[0-9]+$ ]] && { (( php_id >= 80427 && php_id < 80500 )) || (( php_id >= 80512 && php_id < 80600 )); }; then
    printf '[OK] PHP dentro del rango publicado por xCurl 1.1.1\n'
    record 'Compatibilidad de PHP' 'OK' '-'
else
    printf '[BLOQUEADO] PHP %s queda fuera del rango >=8.4.27 <8.5 || >=8.5.12 <8.6.\n' "$php_version" >&2
    record 'Compatibilidad de PHP' 'BLOQUEADO' '-'
    skip 'Suite/Composer/paquete' 'Instalar o seleccionar un PHP admitido, sin modificar el predeterminado si hay binarios paralelos'
    run_logged 'Sintaxis PHP' 'lint' "$php_bin" tools/lint.php || true
    write_report
    exit 2
fi

run_logged 'Sintaxis PHP' 'lint' "$php_bin" tools/lint.php || true

if [[ -z "$composer_cli" ]] || [[ ! -f "$composer_cli" ]]; then
    skip 'Composer e instalación' 'No se ha encontrado Composer; instalar Composer 2 o definir COMPOSER_CLI=/ruta/composer.phar'
    skip 'Suite, análisis y estilo' 'Se requiere composer install para cargar xCurl y las dependencias de desarrollo'
    skip 'Instalación de consumidor' 'Se requiere Composer'
    if [[ "$release_mode" == '--release' ]]; then
        skip 'ZIP autónomo y SHA-256' 'Se requiere una instalación de dependencias de producción'
    fi
    write_report
    exit 1
fi

if ! run_logged 'Composer CLI con el PHP seleccionado' 'composer-version' "$php_bin" "$composer_cli" --version --no-ansi; then
    skip 'Dependencias y pruebas' 'El ejecutable Composer no pudo iniciarse con el PHP seleccionado; definir COMPOSER_CLI a un composer.phar'
    write_report
    exit 1
fi

# Exact 1.1.1 pin is deliberate; --no-check-all suppresses only constraint-style warnings.
run_logged 'composer validate --strict --no-check-all' 'composer-validate' "$php_bin" "$composer_cli" validate --strict --no-check-all --no-interaction || true

install_ok=0
if run_logged 'composer install (dependencias de desarrollo)' 'composer-install-dev' \
   "$php_bin" "$composer_cli" install --prefer-dist --no-interaction --no-progress --no-ansi; then
    install_ok=1
fi

consumer_smoke() {
    local temp rc=0
    temp="$(mktemp -d "${TMPDIR:-/tmp}/geo-locator-1.0.1.XXXXXX")" || return 1
    if ! "$php_bin" -r '
        $root = $argv[1];
        $file = $argv[2];
        $payload = [
            "name" => "geo-locator/verification-consumer",
            "type" => "project",
            "repositories" => [[
                "type" => "path", "url" => $root,
                "options" => ["symlink" => false, "versions" => ["absolutoffice/geo-locator" => "1.0.1"]]
            ]],
            "require" => ["absolutoffice/geo-locator" => "1.0.1"],
            "minimum-stability" => "stable",
            "prefer-stable" => true
        ];
        if (file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) {
            exit(1);
        }
    ' "$root" "$temp/composer.json"; then
        rc=1
    else
        (
            cd -- "$temp" || exit 1
            COMPOSER_ROOT_VERSION=dev-main "$php_bin" "$composer_cli" update --no-dev --prefer-dist --no-interaction --no-progress --no-ansi &&
            "$php_bin" "$root/tests/ComposerInstallationTest.php" "$temp/vendor/autoload.php"
        ) || rc=$?
    fi
    rm -rf -- "$temp"
    return "$rc"
}

if (( install_ok == 1 )); then
    run_logged 'composer check-platform-reqs' 'platform-reqs-dev' \
        "$php_bin" "$composer_cli" check-platform-reqs --no-ansi || true
    run_logged 'xCurl 1.1.1 instalado realmente' 'xcurl-installed' \
        "$php_bin" -r '
            require "vendor/autoload.php";
            $version = Composer\InstalledVersions::getPrettyVersion("absolutoffice/xcurl");
            if (!in_array($version, ["v1.1.1", "1.1.1"], true)) {
                fwrite(STDERR, "xCurl 1.1.1 requerida; instalada: " . ($version ?? "ausente") . PHP_EOL);
                exit(1);
            }
            echo "xCurl instalada: ", $version, PHP_EOL;
        ' || true
    run_logged 'composer check (CI: validate, lint, tests, PHPStan, PHPCS)' 'composer-check' \
        "$php_bin" "$composer_cli" check --no-interaction --no-ansi || true
    run_logged 'Suite sin curl_exec y sin allow_url_fopen' 'fallback-offline' \
        "$php_bin" -d disable_functions=curl_exec -d allow_url_fopen=0 tests/index.php || true
    run_logged 'Instalación Composer en aplicación independiente (path repository)' 'consumer-smoke' consumer_smoke || true
else
    skip 'check-platform-reqs / composer check / fallback / consumidor' 'Falló composer install; revisar el log correspondiente'
fi

# The release builder intentionally refuses to overwrite its ZIP or checksum.
# Preserve prior results from a previous --release run rather than deleting them.
archive_previous_release() {
    local source_dir="$root/dist" file backup_dir
    local -a existing=()
    for file in "$source_dir/geo-locator-1.0.1-standalone.zip" "$source_dir/SHA256SUMS"; do
        if [[ -e "$file" || -L "$file" ]]; then
            if [[ ! -f "$file" || -L "$file" ]]; then
                printf 'Artefacto previo no es un fichero regular seguro: %s\n' "$file" >&2
                return 1
            fi
            existing+=("$file")
        fi
    done
    if (( ${#existing[@]} == 0 )); then
        echo 'No hay artefactos previos.'
        return 0
    fi
    backup_dir="$log_dir/previous-release-$(date '+%Y%m%d-%H%M%S')-$$"
    mkdir -p -- "$backup_dir" || return 1
    for file in "${existing[@]}"; do
        mv -- "$file" "$backup_dir/" || return 1
    done
    printf 'Artefactos anteriores preservados en %s\n' "$backup_dir"
}

verify_standalone() {
    (
        cd -- "$root/dist" || exit 1
        sha256sum --check SHA256SUMS &&
        unzip -tq 'geo-locator-1.0.1-standalone.zip'
    )
}

if [[ "$release_mode" == '--release' ]]; then
    production_ok=0
    if run_logged 'composer install --no-dev (producción)' 'composer-install-prod' \
       "$php_bin" "$composer_cli" install --no-dev --prefer-dist --optimize-autoloader --no-scripts --no-interaction --no-progress --no-ansi; then
        production_ok=1
    fi
    if (( production_ok == 1 )); then
        run_logged 'composer check-platform-reqs --no-dev' 'platform-reqs-prod' \
            "$php_bin" "$composer_cli" check-platform-reqs --no-dev --no-ansi || true
        if run_logged 'Conservar ZIP anterior si existe (en var/)' 'backup-previous-release' archive_previous_release; then
            if run_logged 'Construcción ZIP autónomo 1.0.1' 'build-release' \
                "$php_bin" tools/build-release.php --version=1.0.1 --output=dist; then
                run_logged 'Verificar SHA-256 e integridad del ZIP autónomo' 'verify-standalone' verify_standalone || true
            else
                skip 'Verificar ZIP autónomo y SHA-256' 'Falló el generador de release'
            fi
        else
            skip 'Construcción ZIP autónomo' 'No fue posible guardar el ZIP anterior de forma segura'
        fi
    else
        skip 'ZIP autónomo y SHA-256' 'Falló la instalación de dependencias de producción'
    fi
    # Do not leave the checkout without its development tools.
    run_logged 'Restaurar dependencias de desarrollo' 'composer-restore-dev' \
        "$php_bin" "$composer_cli" install --prefer-dist --no-interaction --no-progress --no-ansi || true
fi

write_report
if (( errors > 0 || ${#notes[@]} > 0 )); then
    printf 'Validación NO completada: %d comprobación(es) con error, %d pendiente(s).\n' "$errors" "${#notes[@]}" >&2
    exit 1
fi
printf 'Todas las comprobaciones seleccionadas se han ejecutado correctamente.\n'
