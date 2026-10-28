<?php

/**
 * Build the downloadable standalone distribution from a production Composer install.
 *
 * Usage: php tools/build-release.php --version=dev-main --output=dist
 * Run composer install --no-dev --prefer-dist --optimize-autoloader first.
 * The selected files and dependency versions are reproducible; ZIP timestamps may vary.
 * No network requests, Composer mutations or Git operations are performed here.
 */

declare(strict_types=1);

/**
 * Parse a safe release identifier and output directory from CLI options.
 *
 * @param list<string> $arguments Full PHP argument vector, including this script's path.
 * @return array{version: string, output: string}|null Options, or null after displaying help.
 * @throws RuntimeException When options are missing, unknown or unsafe for an artifact filename.
 */
function releaseOptions(array $arguments): ?array
{
    for ($index = 1; $index < count($arguments); ++$index) {
        $argument = $arguments[$index];
        if ($argument === '--help' || str_starts_with($argument, '--version=')) {
            continue;
        }
        if (str_starts_with($argument, '--output=')) {
            continue;
        }
        if (in_array($argument, ['--version', '--output'], true) && isset($arguments[$index + 1])) {
            ++$index;
            continue;
        }
        throw new RuntimeException('Unknown or incomplete release option: ' . $argument);
    }
    $options = getopt('', ['version:', 'output:', 'help'], $restIndex);
    if ($options === false || $restIndex !== count($arguments)) {
        throw new RuntimeException('Use --version=<SemVer|dev-main> and optionally --output=<directory>.');
    }
    if (isset($options['help'])) {
        echo "Usage: php tools/build-release.php --version=<SemVer|dev-main> [--output=dist]\n";
        return null;
    }

    $version = $options['version'] ?? null;
    $output = $options['output'] ?? 'dist';
    if (!is_string($version) || !is_string($output) || trim($output) === '' || str_contains($output, "\0")) {
        throw new RuntimeException('A version and a nonempty output directory are required.');
    }
    $version = str_starts_with($version, 'v') ? substr($version, 1) : $version;
    $semver = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)'
        . '(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)'
        . '(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?'
        . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';
    if ($version !== 'dev-main' && preg_match($semver, $version) !== 1) {
        throw new RuntimeException('Version must be dev-main or SemVer, for example 1.0.0 or 1.0.0-rc.1.');
    }

    return ['version' => $version, 'output' => $output];
}

/**
 * Read and validate the stable source release identifier.
 *
 * @param string $root Canonical package root containing VERSION.
 * @return string SemVer source release identifier without a leading v.
 * @throws RuntimeException When VERSION is absent, unreadable or malformed.
 */
function releaseSourceVersion(string $root): string
{
    $path = $root . '/VERSION';
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Cannot read VERSION.');
    }
    $version = trim($contents);
    $semver = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)'
        . '(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)'
        . '(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?'
        . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';
    if (preg_match($semver, $version) !== 1) {
        throw new RuntimeException('VERSION must contain a valid SemVer identifier.');
    }

    return $version;
}

/**
 * Read a JSON object without converting invalid or missing metadata into an empty set.
 *
 * @param string $path Existing metadata file to read.
 * @return array<string, mixed> Decoded JSON object.
 * @throws RuntimeException When the file cannot be read or does not contain a JSON object.
 * @throws JsonException When the JSON is malformed.
 */
function releaseJson(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Cannot read release metadata: ' . $path);
    }
    $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($value) || array_is_list($value)) {
        throw new RuntimeException('Release metadata must be a JSON object: ' . $path);
    }

    return $value;
}

/**
 * Require exactly the production packages and references recorded in composer.lock.
 *
 * This validates installed metadata rather than authenticating third-party source files.
 * The build should start from a clean checkout and composer install --no-dev.
 *
 * @param string $root Package root containing composer.lock and vendor/.
 * @return list<string> Locked package names allowed beneath vendor/.
 * @throws RuntimeException When vendor is missing, development packages are installed or metadata differs.
 * @throws JsonException When Composer metadata is malformed.
 */
function releaseProductionPackages(string $root): array
{
    if (!is_file($root . '/vendor/autoload.php') || !is_file($root . '/vendor/composer/installed.json')) {
        throw new RuntimeException('Run composer install --no-dev --prefer-dist --optimize-autoloader first.');
    }
    $lock = releaseJson($root . '/composer.lock');
    $installed = releaseJson($root . '/vendor/composer/installed.json');
    if (($installed['dev'] ?? true) !== false || ($installed['dev-package-names'] ?? []) !== []) {
        throw new RuntimeException('Development dependencies are installed; run composer install --no-dev first.');
    }

    $sets = [];
    foreach ([$lock['packages'] ?? null, $installed['packages'] ?? null] as $packages) {
        if (!is_array($packages)) {
            throw new RuntimeException('Composer metadata does not provide a production package list.');
        }
        $set = [];
        foreach ($packages as $package) {
            $name = is_array($package) ? ($package['name'] ?? null) : null;
            if (!is_string($name) || preg_match('/^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*$/D', $name) !== 1) {
                throw new RuntimeException('Composer metadata contains an invalid package name.');
            }
            if (isset($set[$name])) {
                throw new RuntimeException('Composer metadata contains a duplicate package name.');
            }
            $set[$name] = [
                'version' => $package['version'] ?? null,
                'source' => $package['source']['reference'] ?? null,
                'dist' => $package['dist']['reference'] ?? null,
            ];
        }
        ksort($set, SORT_STRING);
        $sets[] = $set;
    }
    if ($sets[0] !== $sets[1]) {
        throw new RuntimeException('Installed production package versions/references do not match composer.lock.');
    }

    return array_keys($sets[0]);
}

/**
 * Identify local credentials, live data and development metadata omitted from archives.
 *
 * @param string $relative Slash-separated path relative to the package root.
 * @return bool True when a path component or filename must not be distributed.
 */
function releaseExcluded(string $relative): bool
{
    foreach (explode('/', $relative) as $part) {
        if (in_array($part, ['.git', '.github', '.hg', '.svn', '.private', '.state', 'nbproject'], true)) {
            return true;
        }
        if (
            ($part !== '.env.example' && ($part === '.env' || str_starts_with($part, '.env.')))
            || in_array($part, ['auth.json', 'config.local.php'], true)
            || preg_match('/\.(?:mmdb(?:\..*)?|pem|key|p12|pfx|log)$/iD', $part) === 1
        ) {
            return true;
        }
    }

    return str_starts_with($relative, 'vendor/bin/');
}

/**
 * Collect regular files beneath an approved directory without following symbolic links.
 *
 * @param string $root Canonical package root.
 * @param string $relative Approved directory relative to the package root.
 * @return array<string, string> Relative archive paths mapped to absolute source paths.
 * @throws RuntimeException When a selected tree contains a symbolic link or a non-regular entry.
 */
function releaseDirectoryFiles(string $root, string $relative): array
{
    $directory = $root . '/' . $relative;
    if (is_link($directory)) {
        throw new RuntimeException('Symbolic links are not accepted in release inputs: ' . $relative);
    }
    if (!is_dir($directory)) {
        return [];
    }
    $files = [];
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        $name = $relative . '/' . $entry->getFilename();
        if ($entry->isLink()) {
            throw new RuntimeException('Symbolic links are not accepted in release inputs: ' . $name);
        }
        if (releaseExcluded($name)) {
            continue;
        }
        if ($entry->isDir()) {
            $files += releaseDirectoryFiles($root, $name);
        } elseif ($entry->isFile()) {
            $files[$name] = $entry->getPathname();
        } else {
            throw new RuntimeException('Non-regular release input: ' . $name);
        }
    }

    return $files;
}

/**
 * Select the documented project payload and production dependency trees.
 *
 * @param string $root Canonical package root.
 * @param list<string> $packages Production dependency names validated against composer.lock.
 * @return array<string, string> Sorted map of archive-relative paths to source files.
 * @throws RuntimeException When required files or dependency trees are missing, linked or unexpected.
 */
function releaseFiles(string $root, array $packages): array
{
    $files = [];
    foreach (
        [
            'composer.json', 'composer.lock', 'VERSION', 'LICENSE', 'README.md', 'CHANGELOG.md',
            'MIGRACION-XCURL.md', 'THIRD-PARTY-NOTICES.md', '.env.example', 'phpcs.xml.dist',
            'config/environment.php', 'db/MaxMind/README.txt', 'vendor/autoload.php',
        ] as $relative
    ) {
        $path = $root . '/' . $relative;
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('Required regular release file is missing: ' . $relative);
        }
        for ($part = dirname($path); $part !== $root && dirname($part) !== $part; $part = dirname($part)) {
            if (is_link($part)) {
                throw new RuntimeException('A required release file passes through a symbolic link: ' . $relative);
            }
        }
        $files[$relative] = $path;
    }
    if (is_link($root . '/autoload.php')) {
        throw new RuntimeException('Standalone autoload.php must not be a symbolic link.');
    }
    if (is_file($root . '/autoload.php')) {
        $files['autoload.php'] = $root . '/autoload.php';
    }
    foreach (['src', 'docs', 'examples', 'tests', 'tools', 'vendor/composer'] as $relative) {
        $files += releaseDirectoryFiles($root, $relative);
    }
    foreach ($packages as $package) {
        if (!is_dir($root . '/vendor/' . $package)) {
            throw new RuntimeException('Installed package directory is missing: ' . $package);
        }
        $files += releaseDirectoryFiles($root, 'vendor/' . $package);
    }

    // Fail on stale/unexpected packages left behind by an unclean vendor tree.
    foreach (releaseDirectoryFiles($root, 'vendor') as $relative => $path) {
        if (!isset($files[$relative])) {
            throw new RuntimeException('Unexpected file beneath vendor/: ' . $relative);
        }
    }
    ksort($files, SORT_STRING);

    return $files;
}

/**
 * Resolve/create the artifact directory while keeping it outside all input trees.
 *
 * @param string $root Canonical package root.
 * @param string $requested Absolute path, or path relative to the package root.
 * @return string Canonical writable output directory.
 * @throws RuntimeException When an output component is linked, unwritable or overlaps a release input tree.
 */
function releaseOutputDirectory(string $root, string $requested): string
{
    $absolute = preg_match('~^(?:[/\\\\]|[A-Za-z]:[/\\\\])~', $requested) === 1;
    $path = $absolute ? $requested : $root . '/' . $requested;
    if (in_array('..', explode('/', str_replace('\\', '/', $path)), true)) {
        throw new RuntimeException('Output paths must not contain ..; use an absolute external path instead.');
    }
    for ($part = $path; dirname($part) !== $part; $part = dirname($part)) {
        if (is_link($part)) {
            throw new RuntimeException('Output directory must not pass through symbolic links.');
        }
    }
    $missing = [];
    $ancestor = $path;
    while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
        array_unshift($missing, basename($ancestor));
        $ancestor = dirname($ancestor);
    }
    $existing = realpath($ancestor);
    if ($existing === false || !is_dir($existing)) {
        throw new RuntimeException('Output directory has no usable existing parent.');
    }
    $output = $existing . ($missing === [] ? '' : '/' . implode('/', $missing));
    $normalized = str_replace('\\', '/', $output);
    $base = str_replace('\\', '/', $root);
    foreach (['', '/src', '/config', '/db', '/docs', '/examples', '/tests', '/tools', '/vendor'] as $suffix) {
        if (
            $normalized === $base . $suffix
            || ($suffix !== '' && str_starts_with($normalized, $base . $suffix . '/'))
        ) {
            throw new RuntimeException('Output directory overlaps release inputs; use dist/ or an external directory.');
        }
    }
    if (!is_dir($output) && !mkdir($output, 0775, true) && !is_dir($output)) {
        throw new RuntimeException('Unable to create the output directory.');
    }
    if (!is_writable($output)) {
        throw new RuntimeException('Output directory is not writable.');
    }

    return $output;
}

/**
 * Create a ZIP and verify its exact entry set and byte hashes before publishing its checksum.
 *
 * @param string $version Validated artifact version identifier.
 * @param string $output Canonical output directory controlled by the caller.
 * @param array<string, string> $files Approved relative-to-absolute source file mapping.
 * @return string Absolute path to the completed standalone ZIP.
 * @throws Throwable When archive creation, verification or artifact writes fail.
 */
function releaseArchive(string $version, string $output, array $files): string
{
    $name = 'geo-locator-' . $version . '-standalone.zip';
    $destination = $output . '/' . $name;
    $checksums = $output . '/SHA256SUMS';
    if (file_exists($destination) || is_link($destination) || file_exists($checksums) || is_link($checksums)) {
        throw new RuntimeException('Release output already exists; choose an empty output directory.');
    }
    $temporary = $output . '/.geo-locator-' . bin2hex(random_bytes(8)) . '.zip';
    $archive = null;
    try {
        $archive = new PharData($temporary, FilesystemIterator::SKIP_DOTS, null, Phar::ZIP);
        $hashes = [];
        foreach ($files as $relative => $path) {
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('Cannot read release input: ' . $relative);
            }
            $entry = 'geo-locator/' . $relative;
            $archive->addFromString($entry, $bytes);
            $archive[$entry]->chmod(0644);
            $hashes[$entry] = hash('sha256', $bytes);
        }
        $archive->compressFiles(Phar::GZ);
        // Open a fresh directory iterator after populating the archive.
        unset($archive);
        $archive = new PharData($temporary);
        $actual = [];
        foreach (new RecursiveIteratorIterator($archive) as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            $relative = substr($entry->getPathname(), strlen('phar://' . $temporary . '/'));
            $bytes = $entry->getContent();
            $actual[$relative] = hash('sha256', $bytes);
        }
        ksort($actual, SORT_STRING);
        ksort($hashes, SORT_STRING);
        if ($actual !== $hashes) {
            throw new RuntimeException('Archive entries or hashes differ from the approved input manifest.');
        }
        unset($archive);
        $digest = hash_file('sha256', $temporary);
        if (!is_string($digest)) {
            throw new RuntimeException('Cannot hash the completed release archive.');
        }
        if (!rename($temporary, $destination)) {
            throw new RuntimeException('Cannot move the completed archive into its output path.');
        }
        $handle = fopen($checksums, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot exclusively create SHA256SUMS.');
        }
        try {
            $line = $digest . '  ' . $name . "\n";
            if (fwrite($handle, $line) !== strlen($line)) {
                throw new RuntimeException('Cannot write SHA256SUMS completely.');
            }
        } finally {
            fclose($handle);
        }
        printf("Built %s (%d files)\nSHA-256: %s\n", $destination, count($hashes), $digest);

        return $destination;
    } finally {
        unset($archive);
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

try {
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80512 || PHP_VERSION_ID >= 90000) {
        throw new RuntimeException('Release builds require PHP ^8.5.12 CLI.');
    }
    if (!class_exists(PharData::class) || !extension_loaded('zlib')) {
        throw new RuntimeException('Release builds require the Phar and zlib extensions.');
    }
    $options = releaseOptions($argv);
    if ($options !== null) {
        $root = dirname(__DIR__);
        $sourceVersion = releaseSourceVersion($root);
        if ($options['version'] !== 'dev-main' && $options['version'] !== $sourceVersion) {
            throw new RuntimeException(
                'Requested release version ' . $options['version'] . ' does not match VERSION ' . $sourceVersion . '.'
            );
        }
        $packages = releaseProductionPackages($root);
        $files = releaseFiles($root, $packages);
        $output = releaseOutputDirectory($root, $options['output']);
        releaseArchive($options['version'], $output, $files);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Release build failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
