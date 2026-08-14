<?php
declare(strict_types=1);

/**
 * Determinism under real concurrent CPU load, not simulated jitter.
 *
 * `InvariantTest`/`PackingTest` already prove reproducibility under an effort budget
 * using the real clock (one hand-built scenario across all four profiles) and a
 * simulated ticking clock across many generated orders (the injected-clock contribution) --
 * both fast, deterministic and safe to run on every commit. Neither puts the machine
 * under the kind of contention this targets: "concurrent
 * compilation or CI load changes how far QUALITY search progresses". This script
 * closes that gap directly by forking real OS processes that burn CPU for the
 * duration of the run, then repeating a fixed request 100 times against the real wall
 * clock while they compete for cores.
 *
 * Kept out of the default test run on purpose, for the same reason
 * `benchmarks/run_baselines.py` is: it depends on real OS scheduling under real
 * concurrent load, not only this repository's own state. Run by hand with
 * `make stress-determinism`. Needs the `pcntl` extension; skips itself (exit 0) with a
 * clear message when it is not loaded, since that is an environment fact, not a
 * determinism failure.
 */

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/Support.php';

use Packvium\Algorithm\EffortBudget;
use Packvium\Config\PackingConfig;
use Packvium\Domain\PackingRequest;
use Packvium\Tests\Support;
use Packvium\Validation\IndependentSolutionValidator;

const RUNS = 100;
const LOAD_WORKERS = 4;

function burnCpuUntil(float $stopAt): void
{
    $total = 0;
    while (microtime(true) < $stopAt) {
        for ($i = 0; $i < 2_000; $i++) {
            $total += $i * $i;
        }
    }
}

/** @return array{0:list<\Packvium\Domain\Item>,1:list<\Packvium\Domain\Container>} */
function scenario(): array
{
    $items = [
        Support::item('a', 40, 30, 20, ['quantity' => 6]),
        Support::item('b', 60, 50, 40, ['quantity' => 4]),
        Support::item('c', 25, 25, 25, ['quantity' => 8]),
    ];
    $containers = [Support::box('box', 150, 150, 150, ['quantity' => 3])];
    return [$items, $containers];
}

/** @param array<string,mixed> $report @return array<string,mixed> */
function chosenAnswer(array $report): array
{
    foreach (['duration_ms', 'candidates_evaluated', 'placements_attempted'] as $field) {
        unset($report['algorithm'][$field]);
    }
    foreach (['any_start_truncated', 'all_required_starts_completed', 'global_deadline_reached', 'starts'] as $field) {
        unset($report['termination'][$field]);
    }
    unset($report['alternatives']);
    return $report;
}

function isSound(array $items, array $containers, $result): bool
{
    $issues = (new IndependentSolutionValidator())->validate(
        new PackingRequest($items, $containers), $result->containers
    )->issues;
    return $issues === [];
}

function main(): int
{
    if (!function_exists('pcntl_fork')) {
        echo "SKIP: pcntl extension not available, cannot fork real concurrent load\n";
        return 0;
    }

    $loadDeadline = microtime(true) + 30.0;
    $childPids = [];
    for ($i = 0; $i < LOAD_WORKERS; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            fwrite(STDERR, "FAIL: could not fork a load worker\n");
            return 1;
        }
        if ($pid === 0) {
            burnCpuUntil($loadDeadline);
            exit(0);
        }
        $childPids[] = $pid;
    }

    $exitCode = 0;
    try {
        [$items, $containers] = scenario();

        // The actual acceptance: bounded by counted work alone, real clock, real
        // concurrent load -- every run must land on the exact same answer.
        $effortConfig = new PackingConfig(
            timeLimitMs: 60_000,
            seed: 1234,
            effortBudget: new EffortBudget(maxSearchNodes: 200, maxRestarts: 4),
        );
        $answers = [];
        for ($i = 0; $i < RUNS; $i++) {
            $answers[] = chosenAnswer(Support::pack($items, $containers, $effortConfig)->toArray());
        }
        $mismatches = 0;
        foreach ($answers as $answer) {
            if ($answer !== $answers[0]) {
                $mismatches++;
            }
        }
        if ($mismatches > 0) {
            fwrite(STDERR, "FAIL: {$mismatches}/" . RUNS . " effort-budget runs disagreed with the first under concurrent load\n");
            return $exitCode = 1;
        }
        echo 'OK: ' . RUNS . '/' . RUNS . ' effort-budget runs were bit-identical under ' . LOAD_WORKERS . "-process concurrent load\n";

        // The contrast the acceptance also names: a truncated wall-clock-only run
        // makes no bit-identical claim. Soundness is still checked -- that promise
        // never lapses -- but equality across runs is deliberately not asserted here.
        $tightWallClock = new PackingConfig(timeLimitMs: 5, seed: 1234);
        $unsound = 0;
        $distinctAnswers = [];
        for ($i = 0; $i < RUNS; $i++) {
            $result = Support::pack($items, $containers, $tightWallClock);
            if (!isSound($items, $containers, $result)) {
                $unsound++;
            }
            $distinctAnswers[serialize(chosenAnswer($result->toArray()))] = true;
        }
        if ($unsound > 0) {
            fwrite(STDERR, "FAIL: {$unsound}/" . RUNS . " wall-clock-only runs under load were unsound\n");
            return $exitCode = 1;
        }
        echo 'OK: ' . RUNS . '/' . RUNS . ' wall-clock-only runs under load stayed sound ('
            . count($distinctAnswers) . " distinct answer(s) seen -- no equality claimed or required)\n";
        return $exitCode = 0;
    } finally {
        foreach ($childPids as $pid) {
            posix_kill($pid, SIGTERM);
        }
        foreach ($childPids as $pid) {
            pcntl_waitpid($pid, $status);
        }
    }
}

exit(main());
