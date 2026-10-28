<?php

/**
 * Exercise the real Composer installation without the development fallback autoloader.
 *
 * The default loader belongs to this source checkout or standalone bundle. Pass
 * an absolute application vendor/autoload.php as the first CLI argument to check
 * the same contract after installing GeoLocator as a Composer dependency.
 * The test verifies PSR-4 loading, production dependencies, the installed package
 * root and application-owned data paths from an unrelated working directory.
 * It does not require an MMDB, contact MaxMind or create runtime directories.
 */

declare(strict_types=1);

use AbsolutOffice\XCurl\Client\HttpClient;
use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use GeoLocator\Config\GeoLocatorConfig;
use GeoLocator\Config\MaxMindConfig;
use GeoLocator\Config\PackagePathResolver;
use GeoLocator\Config\SecurityConfig;
use GeoLocator\Config\UpdateConfig;
use GeoLocator\GeoLocatorFactory;
use GeoLocator\Result\GeoStatus;
use MaxMind\Db\Reader;

/**
 * Fail the installation smoke check with an actionable assertion message.
 *
 * @param bool $condition Evaluated installation contract.
 * @param string $message Explanation of the failed contract.
 * @return void
 * @throws RuntimeException When the installation contract is not satisfied.
 */
function expectComposerInstallation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$autoloadPath = $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';
expectComposerInstallation(is_file($autoloadPath), 'Composer autoload.php is missing; run composer install.');
$loader = require $autoloadPath;
expectComposerInstallation($loader instanceof ClassLoader, 'The selected file must return Composer ClassLoader.');

foreach ([GeoLocatorFactory::class, HttpClient::class, Reader::class] as $className) {
    expectComposerInstallation(class_exists($className), 'Composer cannot autoload required class: ' . $className);
}

$installedPath = InstalledVersions::getInstallPath('absolutoffice/geo-locator');
expectComposerInstallation($installedPath !== null, 'Composer must register the GeoLocator package installation.');
$resolver = new PackagePathResolver();
expectComposerInstallation(
    realpath($resolver->packageRoot()) === realpath($installedPath),
    'Package-relative paths must resolve against the installed library, not the application cwd.',
);

$applicationData = sys_get_temp_dir() . '/geolocator-consumer-' . bin2hex(random_bytes(6));
$databasePath = $applicationData . '/geoip/GeoLite2-City.mmdb';
$stateDirectory = $applicationData . '/geoip/.state';
$originalDirectory = getcwd();

try {
    expectComposerInstallation(chdir(sys_get_temp_dir()), 'Unable to change cwd for the installation check.');
    expectComposerInstallation(
        $resolver->resolve($databasePath) === $databasePath,
        'An application-owned absolute database path must survive package installation unchanged.',
    );

    $config = new GeoLocatorConfig(
        security: new SecurityConfig(str_repeat('T', SecurityConfig::MASTER_KEY_BYTES)),
        maxMind: new MaxMindConfig($databasePath),
        update: new UpdateConfig(stateDirectory: $stateDirectory),
    );
    $result = GeoLocatorFactory::create($config)->locate('127.0.0.1');

    expectComposerInstallation(
        $result->status === GeoStatus::NON_PUBLIC_IP,
        'An installed library must perform a local non-public lookup without an MMDB.',
    );
    expectComposerInstallation(
        $config->maxMind->databasePath === $databasePath && $config->update->stateDirectory === $stateDirectory,
        'Application-owned database and state paths must not move under vendor.',
    );
    expectComposerInstallation(
        !file_exists($applicationData),
        'Installation and lazy lookup must not create data paths.',
    );
} finally {
    if ($originalDirectory !== false) {
        chdir($originalDirectory);
    }
}

echo "ComposerInstallationTest OK\n";
