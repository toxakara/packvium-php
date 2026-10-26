<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

$compatRoot = getenv('PACKVIUM_PHP74_ROOT');
if (!is_string($compatRoot) || $compatRoot === '') {
    throw new RuntimeException('PACKVIUM_PHP74_ROOT must name the generated PHP 7.4 tree');
}

// Rector's file cache and its PHPStan container cache default to one shared temp directory,
// and the container cache keeps the absolute paths of the tree that last ran. A second
// checkout, or a deleted one, then fails the downgrade with a missing-file error. One cache
// per tree, keyed by its real path, keeps every checkout's cache its own.
$cacheRoot = sys_get_temp_dir() . '/packvium-rector-' . substr(sha1((string) realpath($compatRoot)), 0, 16);
// Rector creates its file cache itself but requires the container cache to exist.
if (!is_dir($cacheRoot . '/container') && !mkdir($cacheRoot . '/container', 0777, true)) {
    throw new RuntimeException('cannot create the Rector cache at ' . $cacheRoot);
}

return RectorConfig::configure()
    ->withCache($cacheRoot . '/files', null, $cacheRoot . '/container')
    ->withoutParallel()
    ->withPaths([
        $compatRoot . '/src',
        $compatRoot . '/examples',
        $compatRoot . '/bin',
        // The package entry point. It was copied verbatim for three releases while every
        // other shipped file was downgraded, and its `str_starts_with` made the 7.4
        // artifact fatal on its first autoload.
        $compatRoot . '/autoload.php',
    ])
    ->withDowngradeSets(php73: true);
