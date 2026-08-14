<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Algorithm\EffortBudget;
use Packvium\Config\PackingConfig;
use Packvium\Config\SolverProfile;
use Packvium\Domain\Container;
use Packvium\Domain\Item;
use Packvium\Result\PackingStatus;
use Packvium\Unit\Length;

/**
 * Randomised orders checked against the properties that must hold for all of them.
 *
 * Hand-written scenarios test the cases somebody thought of. These generate orders
 * from fixed seeds and assert the guarantees the library owes every caller, which is
 * how the awkward interactions — a group of non-stackable items under a payload
 * ceiling — get exercised without anyone having to imagine them first.
 *
 * The seeds are fixed on purpose: a failure here must be reproducible from the seed
 * alone, not only on the machine that first saw it. The Python suite runs the same
 * shape of test in packvium-python/tests/test_invariants.py.
 */
final class InvariantTest extends TestCase
{
    private const TRIALS = 16;

    /** @return array{0:list<Item>,1:list<Container>} */
    private static function generate(int $seed): array
    {
        mt_srand($seed);
        $items = [];
        $types = mt_rand(1, 5);
        for ($index = 0; $index < $types; $index++) {
            $options = [
                'quantity' => mt_rand(1, 4),
                'weight' => mt_rand(50, 900) . ' g',
                'stackable' => mt_rand(0, 9) > 1,
                'mustBeOnFloor' => mt_rand(0, 99) > 84,
                'keepUpright' => mt_rand(0, 99) > 79,
                'minimumSupportRatio' => [0.0, 0.0, 0.5, 0.75][mt_rand(0, 3)],
            ];
            if (mt_rand(0, 99) > 74) {
                $options['maxTopLoad'] = mt_rand(500, 4000) . ' g';
            }
            if (mt_rand(0, 99) > 79) {
                $options['group'] = 'g' . ($index % 2);
            }
            $items[] = Support::item("i{$index}", mt_rand(2, 13) * 5, mt_rand(2, 13) * 5, mt_rand(2, 13) * 5, $options);
        }

        $containers = [];
        $stock = mt_rand(1, 2);
        for ($index = 0; $index < $stock; $index++) {
            $options = ['quantity' => mt_rand(1, 3), 'costMinor' => mt_rand(0, 899)];
            if (mt_rand(0, 99) > 69) {
                $options['maxPayload'] = mt_rand(2, 12) . ' kg';
            }
            $containers[] = Support::box("c{$index}", mt_rand(8, 21) * 10, mt_rand(8, 21) * 10, mt_rand(8, 21) * 10, $options);
        }
        return [$items, $containers];
    }

    private static function budget(?SolverProfile $profile = null): PackingConfig
    {
        return new PackingConfig($profile ?? SolverProfile::Balanced, timeLimitMs: 250);
    }

    public static function testEveryGeneratedOrderIsAnsweredSoundly(): void
    {
        for ($seed = 0; $seed < self::TRIALS; $seed++) {
            [$items, $containers] = self::generate($seed);
            $result = Support::pack($items, $containers, self::budget());

            self::assertSame([], Support::problems($result, $items, $containers), "seed {$seed}");
            self::assertSame($result->unpacked === [], $result->complete(), "seed {$seed}");
        }
    }

    public static function testNoItemIsLostOrDuplicated(): void
    {
        // The strongest bookkeeping property there is: every instance the caller asked
        // for comes back exactly once, either placed or explained.
        for ($seed = 0; $seed < self::TRIALS; $seed++) {
            [$items, $containers] = self::generate($seed);
            $result = Support::pack($items, $containers, self::budget());

            $placed = Support::placedIds($result->containers);
            self::assertSame(count($placed), count(array_unique($placed)), "seed {$seed}");

            $expected = 0;
            foreach ($items as $item) {
                $expected += $item->quantity;
            }
            self::assertSame($expected, count($placed) + count($result->unpacked), "seed {$seed}");
        }
    }

    public static function testContainerStockIsNeverOverdrawn(): void
    {
        for ($seed = 0; $seed < self::TRIALS; $seed++) {
            [$items, $containers] = self::generate($seed);
            $result = Support::pack($items, $containers, self::budget());

            $used = [];
            foreach ($result->containers as $packed) {
                $used[$packed->container->id] = ($used[$packed->container->id] ?? 0) + 1;
            }
            foreach ($containers as $container) {
                if ($container->quantity !== null) {
                    self::assertLessThanOrEqual($container->quantity, $used[$container->id] ?? 0, "seed {$seed}");
                }
            }
        }
    }

    public static function testEveryUnpackedItemCarriesARecognisedReason(): void
    {
        // A caller acts on these strings, so an unexplained failure is a failure.
        $known = ['no_compatible_container_dimensions', 'payload_exceeded', 'no_feasible_placement',
                  'group_cannot_fit_together', 'time_limit', 'insufficient_support'];
        for ($seed = 0; $seed < self::TRIALS; $seed++) {
            [$items, $containers] = self::generate($seed);
            foreach (Support::pack($items, $containers, self::budget())->unpacked as $unpacked) {
                self::assertContains($unpacked->reason, $known, "seed {$seed}");
            }
        }
    }

    public static function testEveryProfileAnswersSoundly(): void
    {
        foreach ([SolverProfile::Fast, SolverProfile::Quality, SolverProfile::ExactSmall] as $profile) {
            for ($seed = 0; $seed < 6; $seed++) {
                [$items, $containers] = self::generate($seed);
                $result = Support::pack($items, $containers, self::budget($profile));

                self::assertSame([], Support::problems($result, $items, $containers),
                    "{$profile->value} seed {$seed}");
                self::assertNotSame(PackingStatus::InvalidResult, $result->status);
            }
        }
    }

    public static function testAContainerBudgetIsNeverExceeded(): void
    {
        for ($seed = 0; $seed < 8; $seed++) {
            [$items, $containers] = self::generate($seed);
            foreach ([1, 2] as $budget) {
                $result = Support::pack($items, $containers,
                    new PackingConfig(timeLimitMs: 250, maxContainers: $budget));
                self::assertLessThanOrEqual($budget, count($result->containers), "seed {$seed}");
            }
        }
    }

    public static function testClearanceIsHonouredOnGeneratedOrders(): void
    {
        // A gap that the solver applies but the validator does not know about would go
        // unnoticed on hand-written cases where everything fits comfortably.
        $gap = Length::mm(1);
        for ($seed = 0; $seed < 8; $seed++) {
            [$items, $containers] = self::generate($seed);
            $result = Support::pack($items, $containers, PackingConfig::balanced(250, clearance: $gap));

            self::assertSame([], Support::problems($result, $items, $containers, 0.0, $gap), "seed {$seed}");
            foreach ($result->containers as $packed) {
                foreach ($packed->placements as $placement) {
                    self::assertSame($placement->dimensions->expand($gap)->length->ticks,
                        $placement->envelopeDimensions->length->ticks);
                }
            }
        }
    }

    public static function testATightBudgetNeverProducesAnUnsoundAnswer(): void
    {
        // Partial work must still be a valid packing; an escaping deadline used to
        // discard it entirely.
        for ($seed = 0; $seed < 8; $seed++) {
            [$items, $containers] = self::generate($seed);
            $result = Support::pack($items, $containers, new PackingConfig(timeLimitMs: 1));
            self::assertSame([], Support::problems($result, $items, $containers), "seed {$seed}");
        }
    }

    // A wall clock is not a reproducible measure of work -- how far a search
    // gets before a wall-clock slice runs out depends on the host and its load, not on
    // the request. `PackingTest::testTheSameRequestProducesTheSameAnswer` already shows
    // this holds for one hand-built scenario against the real clock; here the same
    // claim is checked across many randomly generated orders, with an effort budget
    // tight enough to actually cut the search short rather than merely be present.

    private static function tickingClock(int $nsPerCall): \Closure
    {
        $ticks = 0;
        return static function () use (&$ticks, $nsPerCall): int {
            $ticks += $nsPerCall;
            return $ticks;
        };
    }

    /** The answer a caller receives, without the effort diagnostics or the runners-up. */
    private static function chosenAnswer(array $report): array
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

    public static function testABitingEffortBudgetIsBitIdenticalRegardlessOfSimulatedClockSpeed(): void
    {
        // Two ticking clocks at very different simulated rates stand in for "two
        // machines under different load". The wall-clock ceiling stays generous enough
        // that only the effort budget can plausibly trip first.
        $effort = new EffortBudget(maxCandidatesEvaluated: 40, maxPlacementAttempts: 40, maxSearchNodes: 20, maxRestarts: 2);
        $config = new PackingConfig(timeLimitMs: 600_000, seed: 1234, effortBudget: $effort);
        for ($seed = 0; $seed < self::TRIALS; $seed++) {
            [$items, $containers] = self::generate($seed);
            $fastMachine = self::chosenAnswer(Support::pack($items, $containers, $config, self::tickingClock(1))->toArray());
            $slowMachine = self::chosenAnswer(Support::pack($items, $containers, $config, self::tickingClock(1_000_000))->toArray());
            self::assertSame($fastMachine, $slowMachine, "seed {$seed}");
        }
    }

    public static function testTheEffortBudgetAboveActuallyBitesOnAtLeastOneGeneratedOrder(): void
    {
        // Guards the reproducibility test above against being vacuously true because a
        // budget that never actually constrains the search would of course be
        // reproducible. Measured, not assumed.
        $effort = new EffortBudget(maxCandidatesEvaluated: 40, maxPlacementAttempts: 40, maxSearchNodes: 20, maxRestarts: 2);
        $config = new PackingConfig(timeLimitMs: 600_000, seed: 1234, effortBudget: $effort);
        $bitAtLeastOnce = false;
        for ($seed = 0; $seed < self::TRIALS; $seed++) {
            [$items, $containers] = self::generate($seed);
            $result = Support::pack($items, $containers, $config, self::tickingClock(1));
            $bitAtLeastOnce = $bitAtLeastOnce || $result->algorithm->effortLimitReached;
        }
        self::assertTrue($bitAtLeastOnce);
    }

    public static function testTheWallClockStillStopsASearchWhenTheEffortBudgetIsLooser(): void
    {
        // An effort budget is an additional bound, not a replacement for the wall
        // clock: with the simulated clock advancing far faster than a tight time
        // budget and an effort budget generous enough never to bite first, the wall
        // clock is still what stops the search.
        [$items, $containers] = self::generate(0);
        $looseEffort = new EffortBudget(maxCandidatesEvaluated: 1_000_000, maxPlacementAttempts: 1_000_000, maxSearchNodes: 1_000_000, maxRestarts: 1_000);
        $config = new PackingConfig(timeLimitMs: 1, seed: 1234, effortBudget: $looseEffort);
        $result = Support::pack($items, $containers, $config, self::tickingClock(10_000_000));

        self::assertSame([], Support::problems($result, $items, $containers));
        self::assertTrue($result->algorithm->timeLimitReached);
    }

    // A longer time budget must never make the packer choose a solution the
    // active objective ranks worse than what a shorter budget, same request and same
    // seed, would have chosen. The ticking clock stands in for a slow, heavily-loaded
    // machine (the same technique used above) so a tiny, fast-to-run fixture can still
    // exercise genuine truncation at millisecond-scale budgets instead of needing a
    // real BR1-sized corpus and second-scale deadlines -- and a slow simulated machine
    // is exactly the regime (bookkeeping overhead competing against search time) where
    // the violation this guards against was actually found. Mirrors
    // packvium-python/tests/test_invariants.py::
    // test_raising_the_time_limit_never_lowers_the_chosen_rank.
    public static function testRaisingTheTimeLimitNeverLowersTheChosenRank(): void
    {
        // Hand-built fixture: seven differently-sized items in a container that cannot
        // hold all of them, so `exact_small`'s branch-and-bound search genuinely has
        // several distinct, differently-scored candidates to choose between at
        // different budgets rather than converging on one obvious answer immediately.
        $items = [
            Support::item('i0', 85, 50, 80, ['weight' => '265 g']),
            Support::item('i1', 20, 40, 60, ['weight' => '298 g']),
            Support::item('i2', 50, 80, 85, ['weight' => '205 g']),
            Support::item('i3', 55, 45, 65, ['weight' => '161 g']),
            Support::item('i4', 60, 30, 40, ['weight' => '121 g']),
            Support::item('i5', 80, 25, 65, ['weight' => '459 g']),
            Support::item('i6', 40, 60, 75, ['weight' => '464 g']),
        ];
        $containers = [Support::box('box', 240, 170, 190, ['quantity' => 1])];
        $timeLimitsMs = [1, 2, 3, 5, 8, 12, 18, 25, 35];

        $scores = [];
        foreach ($timeLimitsMs as $index => $timeLimitMs) {
            $config = new PackingConfig(profile: SolverProfile::ExactSmall, timeLimitMs: $timeLimitMs);
            $result = Support::pack($items, $containers, $config, self::tickingClock(50_000));
            $scores[] = $result->score;
            if ($index === 0) {
                // Guards against vacuous success: the smallest budget must actually be
                // too tight to finish, or every budget trivially finding the same
                // complete answer would pass this test without exercising anything.
                self::assertTrue($result->algorithm->timeLimitReached);
            }
        }

        self::assertGreaterThan(1, count(array_unique(array_map('serialize', $scores))),
            'fixture never produced distinct candidates across budgets');
        for ($index = 1; $index < count($scores); $index++) {
            self::assertTrue(
                $scores[$index] <= $scores[$index - 1],
                "raising the time limit to {$timeLimitsMs[$index]}ms chose a worse-ranked solution ("
                . json_encode($scores[$index]) . ') than a shorter budget had already found ('
                . json_encode($scores[$index - 1]) . ')',
            );
        }
    }

    // `SolverOrchestrator` can run every start after the first one
    // concurrently, each in its own forked process (`PackingConfig::$parallelStarts >
    // 1`), instead of one after another sharing a single sliced deadline. The tests
    // below are the actual proof the ticket asks for -- real `pcntl_fork()` children on
    // the real system clock, repeated enough times that genuine OS scheduling jitter
    // gets a real chance to vary which start happens to finish first. Every repetition
    // must still choose the same packing. Mirrors
    // the Python suite's identical concurrency section.
    private const CONCURRENT_REPEATS = 8;

    public static function testConcurrentStartsReproduceTheSequentialAnswerUnderAnAmpleBudget(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension not available');
        }
        for ($seed = 0; $seed < 4; $seed++) {
            [$items, $containers] = self::generate($seed);
            $sequential = new PackingConfig(
                SolverProfile::Balanced, timeLimitMs: 10_000, seed: 1234, multiStartOrders: 6,
                solvers: ['extreme_points', 'layer'], parallelStarts: 1,
            );
            $concurrent = new PackingConfig(
                SolverProfile::Balanced, timeLimitMs: 10_000, seed: 1234, multiStartOrders: 6,
                solvers: ['extreme_points', 'layer'], parallelStarts: 4,
            );

            $baseline = self::chosenAnswer(Support::pack($items, $containers, $sequential)->toArray());
            for ($repeat = 0; $repeat < self::CONCURRENT_REPEATS; $repeat++) {
                $answer = self::chosenAnswer(Support::pack($items, $containers, $concurrent)->toArray());
                self::assertSame($baseline, $answer, "seed {$seed}, repeat {$repeat}");
            }
        }
    }

    public static function testConcurrentStartsAreBitIdenticalUnderABitingEffortBudget(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension not available');
        }
        [$items, $containers] = self::generate(7);
        $effort = new EffortBudget(maxCandidatesEvaluated: 40, maxPlacementAttempts: 40, maxSearchNodes: 20, maxRestarts: 4);
        $config = new PackingConfig(
            SolverProfile::Balanced, timeLimitMs: 10_000, seed: 1234, effortBudget: $effort,
            multiStartOrders: 4, solvers: ['extreme_points', 'layer'], parallelStarts: 4,
        );

        $baseline = self::chosenAnswer(Support::pack($items, $containers, $config)->toArray());
        for ($repeat = 0; $repeat < self::CONCURRENT_REPEATS; $repeat++) {
            self::assertSame($baseline, self::chosenAnswer(Support::pack($items, $containers, $config)->toArray()), "repeat {$repeat}");
        }
    }

    public static function testParallelStartsFallsBackToSequentialWithAnInjectedClock(): void
    {
        // An injected test clock has no meaning across a fork boundary (see
        // Deadline::usesRealClock): its captured state diverges independently between
        // parent and child the instant they fork. `parallelStarts > 1` must silently
        // fall back to the exact sequential path rather than trying to fork against it.
        [$items, $containers] = self::generate(2);
        $sequential = new PackingConfig(SolverProfile::Balanced, timeLimitMs: 1, seed: 1234, parallelStarts: 1);
        $concurrent = new PackingConfig(SolverProfile::Balanced, timeLimitMs: 1, seed: 1234, parallelStarts: 8);
        $frozenClock = static fn(): int => 0;

        $baseline = self::chosenAnswer(Support::pack($items, $containers, $sequential, $frozenClock)->toArray());
        $answer = self::chosenAnswer(Support::pack($items, $containers, $concurrent, $frozenClock)->toArray());
        self::assertSame($baseline, $answer);
    }
}
