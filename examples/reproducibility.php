<?php
/**
 * Get the same answer on every run: counted work instead of a clock.
 *
 * Run it:
 *
 *     php examples/reproducibility.php
 *
 * The search tries several starts and keeps the best. Something has to decide when it
 * stops, and there are two choices:
 *
 * - `timeLimitMs` (`time_limit_ms` in JSON), a wall clock. Its default is one second.
 *   When it runs out, the search keeps what it has, and how far it got depends on the
 *   machine: its speed, what else is running, the garbage collector. The same request can
 *   give a different answer on a loaded server than on your laptop.
 * - `effortBudget` (`effort_budget`), counted work: candidates evaluated, placements
 *   attempted, search nodes, restarts. The count is the same on every machine, so the
 *   answer is too.
 *
 * Set an effort budget whenever the answer is stored, compared, audited or replayed, and
 * keep the time limit as a generous fuse against a genuine hang. The result says which one
 * stopped the search, in `termination`.
 *
 * To make a slow machine repeatable here, this example uses the injected clock the
 * `Packer` accepts for testing: a clock that advances a fixed amount each time it is read
 * stands in for a faster or slower host. A real deployment never passes one.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Algorithm\EffortBudget;
use Packvium\Config\{PackingConfig, SolverProfile};
use Packvium\Domain\{Container, Dimensions, Item};
use Packvium\Extension\ExtensionRegistry;
use Packvium\Packer;
use Packvium\Result\PackingResult;

$items = [
    Item::create('kettle', Dimensions::mm('310', '220', '140'), '2 kg', quantity: 5),
    Item::create('toaster', Dimensions::mm('250', '250', '250'), '3 kg', quantity: 4),
    Item::create('knife-block', Dimensions::mm('400', '150', '100'), '1 kg', quantity: 6),
    Item::create('scale', Dimensions::mm('180', '120', '90'), '0.5 kg', quantity: 8),
];
$boxes = [
    Container::create('small', Dimensions::mm('450', '350', '300'), costMinor: 100),
    Container::create('medium', Dimensions::mm('600', '400', '400'), costMinor: 160),
    Container::create('large', Dimensions::mm('800', '600', '500'), costMinor: 240),
];

/** A fuse, not a limit: nothing below comes close to it on a real machine. */
const FUSE_MS = 60000;

/**
 * A monotonic clock, in nanoseconds, that moves `$step` every time it is read: a larger
 * step is a slower or busier machine doing the same work.
 */
function host(int $step): Closure
{
    $now = -$step;
    return static function () use (&$now, $step): int {
        $now += $step;
        return $now;
    };
}

function config(int $timeLimitMs, ?EffortBudget $budget = null): PackingConfig
{
    return new PackingConfig(SolverProfile::Balanced, timeLimitMs: $timeLimitMs, effortBudget: $budget);
}

function solveOn(PackingConfig $config, int $step, array $items, array $boxes): PackingResult
{
    return (new Packer($config, new ExtensionRegistry(), null, host($step)))->pack($items, $boxes);
}

/**
 * Whether an artifact of this result could promise an exact replay: only when no clock
 * stopped the search. It is the rule `Packvium\Artifacts\OperationalArtifact` applies.
 */
function replayLevel(PackingResult $result): string
{
    return $result->toArray()['algorithm']['time_limit_reached'] ? 'not_guaranteed' : 'exact';
}

function describe(PackingResult $result): string
{
    $opened = implode(',', array_map(static fn($packed): string => $packed->container->id, $result->containers));
    return sprintf('%-6s left out %2d  termination %-12s replay %s',
        $opened, count($result->unpacked), $result->termination->code, replayLevel($result));
}

function section(string $title): void
{
    echo "\n", str_repeat('=', 78), "\n", $title, "\n", str_repeat('=', 78), "\n";
}

// --------------------------------------------------------------------------------------
section('1. A clock-limited search answers differently on different machines');

// The same 100 ms limit on four simulated hosts.
foreach (['fast host' => 10000, 'busy host' => 50000, 'slower host' => 200000, 'overloaded' => 1000000] as $label => $step) {
    printf("  %-12s %s\n", $label, describe(solveOn(config(100), $step, $items, $boxes)));
}
echo '
  Same request, four answers. Each is a valid packing of what the search reached
  before the clock ran out, and the items it never reached are listed as left out,
  but none can be reproduced: run it again on a different day and you get whichever
  one the machine allows. `termination` says
  `time_limit`, and the replay level refuses to promise a replay.
';

// --------------------------------------------------------------------------------------
section('2. An effort budget answers the same everywhere');

$enough = new EffortBudget(5000);
foreach (['fast host' => 10000, 'slower host' => 200000, 'overloaded' => 1000000] as $label => $step) {
    printf("  %-12s %s\n", $label, describe(solveOn(config(FUSE_MS, $enough), $step, $items, $boxes)));
}
echo '
  The budget is large enough that the search finishes, so `termination` is
  `complete`, and it would stop at the same count on any machine if it were not.
';

// --------------------------------------------------------------------------------------
section('3. A budget that is too small is still reproducible, and says so');

$tooSmall = new EffortBudget(500);
foreach (['fast host' => 10000, 'overloaded' => 1000000] as $label => $step) {
    printf("  %-12s %s\n", $label, describe(solveOn(config(FUSE_MS, $tooSmall), $step, $items, $boxes)));
}
echo '
  The search stopped early and left items out -- `termination` is `effort_limit`,
  not `complete` -- but it stopped at the same point on both hosts, so the answer
  can be replayed exactly. Raise the budget until the answer is good enough, then
  keep it fixed.
';

// --------------------------------------------------------------------------------------
section('4. Reading `termination`');

$termination = (new Packer(config(FUSE_MS, new EffortBudget(null, null, null, 4))))
    ->pack($items, $boxes)->toArray()['termination'];
echo "  code: {$termination['code']}\n";
foreach ($termination['starts'] as $start) {
    echo rtrim(sprintf('    %-28s completed=%-5s%s', $start['id'], $start['completed'] ? 'true' : 'false',
        $start['selected'] ? '  <- selected' : '')), "\n";
}
echo '
  `maxRestarts` caps how many portfolio starts run -- here four -- which bounds the
  work of a large request as well as fixing it. `code` describes the selected start:
  a losing start that was cut short leaves it `complete`, and shows up in its own
  record and in `all_required_starts_completed` instead. Over JSON, the same budget
  is `configuration.effort_budget`.
';
