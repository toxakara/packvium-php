<?php
declare(strict_types=1);

/**
 * Runs every `*Test.php` suite in this directory.
 *
 * Discovery rather than a hand-maintained list: a suite that was added but never
 * registered is a test file that silently never runs, which is worse than no test.
 */

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/TestCase.php';
require __DIR__ . '/Support.php';

use Packvium\Tests\TestCase;

$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

foreach ($files as $file) {
    require_once $file;
    /** @var class-string<TestCase> $suite */
    $suite = 'Packvium\\Tests\\' . basename($file, '.php');
    if (!class_exists($suite)) {
        fwrite(STDERR, "No suite class {$suite} in " . basename($file) . "\n");
        exit(1);
    }
    $suite::run();
}

$status = TestCase::report();
if (getenv('PACKVIUM_COVERAGE_CHILD') === '1') {
    $GLOBALS['PACKVIUM_TEST_STATUS'] = $status;
    return;
}
exit($status);
