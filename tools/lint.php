<?php

/**
 * Check syntax of project PHP files with the same interpreter running this script.
 *
 * No Composer dependencies or network access are required. The local browser test
 * configuration is excluded because it belongs to the consuming installation.
 */

declare(strict_types=1);

/**
 * Collect source, configuration, example, test and tooling PHP files in stable order.
 *
 * @param string $root Absolute project root containing the source directories.
 * @return list<string> Absolute paths to regular PHP files, excluding local configuration.
 */
function lintProjectFiles(string $root): array
{
    $files = [];
    foreach (['src', 'config', 'examples', 'tests', 'tools'] as $directory) {
        if (!is_dir($root . '/' . $directory)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root . '/' . $directory,
            FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $file) {
            if ($file->isFile() && !$file->isLink() && $file->getExtension() === 'php') {
                if ($file->getPathname() !== $root . '/tests/config.local.php') {
                    $files[] = $file->getPathname();
                }
            }
        }
    }
    if (is_file($root . '/autoload.php')) {
        $files[] = $root . '/autoload.php';
    }
    sort($files, SORT_STRING);

    return $files;
}

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Run the syntax checker from PHP CLI.');
}

$files = lintProjectFiles(dirname(__DIR__));
$failed = 0;
foreach ($files as $file) {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-l', $file],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        fwrite(STDERR, 'Cannot start PHP syntax checker for ' . $file . PHP_EOL);
        ++$failed;
        continue;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        ++$failed;
        fwrite(STDERR, (string) $stdout . (string) $stderr);
    }
}
printf("PHP syntax: %d files checked, %d failed.\n", count($files), $failed);
exit($failed === 0 ? 0 : 1);
