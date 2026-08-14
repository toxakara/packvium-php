<?php
declare(strict_types=1);

/** Collect deterministic line coverage for the dependency-free PHP test runner.
 *
 * Usage: php -d pcov.enabled=1 -d pcov.directory=/path/to/src
 *            tests/coverage.php /path/to/coverage.json
 *
 * The JSON follows the subset of coverage.py's schema consumed by
 * scripts/check_coverage_baseline.py, so Python and PHP share one ratchet.
 */

if (!extension_loaded('pcov') || !function_exists('pcov\\start')) {
    fwrite(STDERR, "coverage.php requires the PCOV extension\n");
    exit(2);
}

$output = $argv[1] ?? null;
if (!is_string($output) || $output === '') {
    fwrite(STDERR, "usage: php -d pcov.enabled=1 tests/coverage.php <coverage.json>\n");
    exit(2);
}

$packageRoot = realpath(dirname(__DIR__));
$sourceRoot = realpath(dirname(__DIR__) . '/src');
if ($packageRoot === false || $sourceRoot === false) {
    fwrite(STDERR, "cannot resolve package source root\n");
    exit(2);
}

putenv('PACKVIUM_COVERAGE=1');
putenv('PACKVIUM_COVERAGE_CHILD=1');
\pcov\start();
require __DIR__ . '/run.php';
\pcov\stop();
$coverage = \pcov\collect();

$files = [];
$totalExecutable = 0;
$totalCovered = 0;
foreach ($coverage as $path => $lines) {
    $realPath = realpath($path);
    if ($realPath === false || !str_starts_with($realPath, $sourceRoot . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $executableLines = [];
    $executedLines = [];
    foreach ($lines as $line => $hits) {
        // PCOV uses -2 for dead code, -1 for executable-but-uncovered and 1 for
        // covered. Dead opcodes are not statements and must not depress the baseline.
        if ((int) $hits === -2) {
            continue;
        }
        $executableLines[] = (int) $line;
        if ((int) $hits > 0) {
            $executedLines[] = (int) $line;
        }
    }
    sort($executableLines, SORT_NUMERIC);
    sort($executedLines, SORT_NUMERIC);
    $covered = count($executedLines);
    $count = count($executableLines);
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($realPath, strlen($packageRoot) + 1));
    $files[$relative] = [
        'summary' => [
            'covered_lines' => $covered,
            'num_statements' => $count,
            'percent_covered' => $count === 0 ? 100.0 : $covered * 100.0 / $count,
        ],
        'executed_lines' => $executedLines,
        'executable_lines' => $executableLines,
    ];
    $totalExecutable += $count;
    $totalCovered += $covered;
}

ksort($files, SORT_STRING);
$report = [
    'meta' => ['format' => 3, 'tool' => 'pcov', 'branch_coverage' => false],
    'files' => $files,
    'totals' => [
        'covered_lines' => $totalCovered,
        'num_statements' => $totalExecutable,
        'percent_covered' => $totalExecutable === 0 ? 100.0 : $totalCovered * 100.0 / $totalExecutable,
    ],
];
$directory = dirname($output);
if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
    fwrite(STDERR, "cannot create coverage output directory {$directory}\n");
    exit(2);
}
file_put_contents(
    $output,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);
printf(
    "PHP coverage: %d/%d lines (%.2f%%)\n",
    $totalCovered,
    $totalExecutable,
    $report['totals']['percent_covered']
);
exit((int) ($GLOBALS['PACKVIUM_TEST_STATUS'] ?? 0));
