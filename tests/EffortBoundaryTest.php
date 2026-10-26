<?php
declare(strict_types=1);

namespace Packvium\Tests;

use ReflectionMethod;
use Packvium\Algorithm\CandidateFinder;
use Packvium\Algorithm\ContainerState;
use Packvium\Algorithm\Deadline;
use Packvium\Algorithm\EffortBudget;
use Packvium\Algorithm\ExtremePointSolver;
use Packvium\Algorithm\MaximalSpaceSolver;
use Packvium\Algorithm\SearchStats;
use Packvium\Algorithm\SolverOrchestrator;
use Packvium\Algorithm\TimeLimitReached;
use Packvium\Config\PackingConfig;
use Packvium\Constraint\ConstraintSet;
use Packvium\Domain\Container;
use Packvium\Domain\Dimensions;
use Packvium\Domain\Item;
use Packvium\Extension\DefaultCandidateScorer;
use Packvium\Serialization\ArrayCodec;

/**
 * An effort budget names a stop, never a counter that merely reached its limit.
 *
 * A start that finishes on exactly its budget was not stopped, so it is reported complete; a
 * counted step past a limit is refused, so no counter ends above its limit; and under an
 * effort budget the clock is the request's fuse, never sliced per start. Mirrors
 * `packvium-python/tests/test_effort_boundary.py`.
 */
final class EffortBoundaryTest extends TestCase
{
    /** The m1 cart (conformance's regression-effort-budget-truncated-loser), with a budget that never binds. */
    private const M1_CART = [
        'units' => ['length' => 'cm'],
        'configuration' => [
            'objective' => 'lowest_landed_cost',
            'effort_budget' => ['max_candidates_evaluated' => 1_000_000, 'max_placement_attempts' => 1_000_000, 'max_search_nodes' => 1_000_000],
            'time_limit_ms' => 300_000,
            'dimensional_weight_length_unit' => 'cm',
            'dimensional_weight_weight_unit' => 'kg',
            'dimensional_weight_divisor' => 5000,
        ],
        'items' => [
            ['id' => 'product-21', 'quantity' => 10, 'dimensions' => ['length' => '22', 'width' => '18', 'height' => '25'], 'weight' => '1.4 kg'],
            ['id' => 'product-22', 'quantity' => 20, 'dimensions' => ['length' => '9', 'width' => '9', 'height' => '10'], 'weight' => '0.25 kg'],
            ['id' => 'product-11', 'quantity' => 20, 'dimensions' => ['length' => '10', 'width' => '8', 'height' => '9'], 'weight' => '0.35 kg'],
        ],
        'containers' => [
            ['id' => 'small', 'inner_dimensions' => ['length' => '25', 'width' => '20', 'height' => '15'], 'cost_minor' => 40,
                'rate_table' => ['weight_brackets_g' => [1000, 2000, 5000, 10000], 'prices_minor' => [495, 695, 995, 1495]]],
            ['id' => 'large', 'inner_dimensions' => ['length' => '40', 'width' => '30', 'height' => '30'],
                'outer_dimensions' => ['length' => '41', 'width' => '31', 'height' => '31'], 'cost_minor' => 90,
                'rate_table' => ['weight_brackets_g' => [3000, 20000], 'prices_minor' => [1200, 1900], 'minimum_charge_minor' => 1000, 'fuel_surcharge_permille' => 85]],
        ],
    ];

    private const CUBES = [['id' => 'cube', 'quantity' => 60, 'dimensions' => ['length' => '10', 'width' => '10', 'height' => '10']]];

    /**
     * @param array<string,int>|null $budget
     * @param list<string>|null $solvers
     * @param list<array<string,mixed>>|null $items
     * @return array<string,mixed>
     */
    private static function request(?array $budget, ?array $solvers = null, string $profile = 'fast', ?array $items = null): array
    {
        $configuration = ['solver_profile' => $profile, 'time_limit_ms' => 60_000];
        if ($budget !== null) {
            $configuration['effort_budget'] = $budget;
        }
        if ($solvers !== null) {
            $configuration['solvers'] = $solvers;
        }
        return [
            'units' => ['length' => 'mm'],
            'configuration' => $configuration,
            'items' => $items ?? [
                ['id' => 'a', 'quantity' => 3, 'dimensions' => ['length' => '40', 'width' => '30', 'height' => '20']],
                ['id' => 'b', 'quantity' => 3, 'dimensions' => ['length' => '25', 'width' => '25', 'height' => '35']],
            ],
            'containers' => [['id' => 'c', 'inner_dimensions' => ['length' => '100', 'width' => '80', 'height' => '90']]],
        ];
    }

    public static function testAStartThatFinishesOnExactlyItsBudgetIsNotStopped(): void
    {
        $unlimited = ArrayCodec::pack(self::request(null));
        $used = $unlimited['algorithm']['placements_attempted'];

        $exact = ArrayCodec::pack(self::request(['max_placement_attempts' => $used]));

        self::assertFalse($exact['algorithm']['effort_limit_reached']);
        self::assertSame('complete', $exact['termination']['code']);
        self::assertFalse($exact['termination']['winning_start_truncated']);
        self::assertSame($unlimited['score'], $exact['score']);
        self::assertSame($unlimited['containers'], $exact['containers']);
    }

    public static function testABudgetOneShortStopsTheStartOnItsLimit(): void
    {
        $unlimited = ArrayCodec::pack(self::request(null));
        $used = $unlimited['algorithm']['placements_attempted'];

        $short = ArrayCodec::pack(self::request(['max_placement_attempts' => $used - 1]));

        self::assertTrue($short['termination']['any_start_truncated']);
        self::assertLessThanOrEqual($used - 1, $short['algorithm']['placements_attempted']);
        $truncated = array_values(array_filter($short['termination']['starts'], static fn(array $start): bool => $start['truncated']));
        self::assertTrue($truncated !== []);
        foreach ($truncated as $start) {
            self::assertFalse($start['completed'], $start['id']);
        }
    }

    public static function testABlockCommitPlacesOnlyWhatTheBudgetHasRoomFor(): void
    {
        $result = ArrayCodec::pack(self::request(['max_placement_attempts' => 7], ['homogeneous_blocks'], 'quality', self::CUBES));

        self::assertSame(7, $result['algorithm']['placements_attempted']);
        self::assertSame(7, $result['algorithm']['candidates_evaluated']);
        self::assertSame(7, $result['summary']['packed_item_count']);
        self::assertTrue($result['algorithm']['effort_limit_reached']);
        self::assertSame('effort_limit', $result['termination']['code']);
    }

    public static function testANamedBlockSolverOutsideQualityReachesItsFirstBlock(): void
    {
        // Both profiles leave the node limit at one, short of the first block.
        foreach (['fast', 'balanced'] as $profile) {
            $result = ArrayCodec::pack(self::request(null, ['homogeneous_blocks'], $profile, self::CUBES));

            self::assertSame(60, $result['summary']['packed_item_count'], $profile);
            self::assertSame('complete', $result['termination']['code'], $profile);
        }
    }

    public static function testANodeLimitStopIsTheSearchAnswerNotATimeLimit(): void
    {
        $request = self::request(null, ['homogeneous_blocks'], 'quality', self::CUBES);
        $request['configuration']['container_plan_node_limit'] = 1;

        $result = ArrayCodec::pack($request);

        self::assertSame(0, $result['summary']['packed_item_count']);
        self::assertFalse($result['algorithm']['time_limit_reached']);
        self::assertSame('complete', $result['termination']['code']);
    }

    public static function testALatticeTooLargeForTheBudgetIsPlacedOneCountedItemAtATime(): void
    {
        $request = self::request(['max_candidates_evaluated' => 7], ['grid'], 'fast', self::CUBES);
        $request['configuration']['require_placement_coordinates'] = false;

        $result = ArrayCodec::pack($request);

        self::assertSame(7, $result['algorithm']['candidates_evaluated']);
        self::assertSame(7, $result['summary']['packed_item_count']);
        self::assertTrue($result['algorithm']['effort_limit_reached']);
    }

    public static function testAnEffortBudgetGivesEveryStartTheWholeRemainingClock(): void
    {
        $deadline = new Deadline(1_000_000_000, static fn(): int => 0);
        $startBudget = new ReflectionMethod(SolverOrchestrator::class, 'startBudget');

        $budgeted = $startBudget->invoke(null, $deadline, new EffortBudget(maxSearchNodes: 5), new SearchStats(), 8);
        $unbudgeted = $startBudget->invoke(null, $deadline, null, new SearchStats(), 8);

        self::assertSame(1_000_000_000, $budgeted->remainingNs());
        self::assertSame(intdiv(1_000_000_000, 8), $unbudgeted->remainingNs());
    }

    /**
     * (b): with the clock not sliced per start, a run either finishes every start and
     * gives the one answer, or the fuse fired as a whole and `time_limit_reached` says so.
     */
    public static function testABudgetThatNeverBindsGivesOneAnswerOrSaysTheClockFired(): void
    {
        $request = self::M1_CART;
        $reference = ArrayCodec::pack($request);
        $request['configuration']['time_limit_ms'] = 1_500;

        for ($run = 0; $run < 3; $run++) {
            $result = ArrayCodec::pack($request);
            self::assertTrue(
                $result['algorithm']['time_limit_reached']
                    || [$result['score'], $result['containers']] === [$reference['score'], $reference['containers']],
                "run {$run} answered differently without saying the clock fired",
            );
        }
    }

    public static function testAPollOnASpentBudgetRecordsTheStopAndALaterReadDoesNot(): void
    {
        $stats = new SearchStats();
        $deadline = Deadline::ofMilliseconds(60_000)->withEffort(new EffortBudget(maxSearchNodes: 3), $stats);
        $stats->searchNodesExpanded = 3;

        self::assertFalse($deadline->stopped());
        self::assertFalse($deadline->effortStopped());
        self::assertTrue($deadline->expired());
        self::assertTrue($deadline->effortStopped());
        self::assertTrue($deadline->stopped());
    }

    /** @return list<\Packvium\Domain\ItemInstance> */
    private static function cubes(int $quantity): array
    {
        return Item::create('cube', Dimensions::mm(30, 20, 10), quantity: $quantity)->instances();
    }

    public static function testAFeasibleCandidatePastItsLimitStopsTheScanInsideAPoint(): void
    {
        $config = PackingConfig::balanced();
        $stats = new SearchStats();
        $deadline = Deadline::ofMilliseconds(60_000)->withEffort(new EffortBudget(maxCandidatesEvaluated: 1), $stats);
        $state = new ContainerState(Container::create('c', Dimensions::mm(100, 100, 100)), 1);

        self::assertThrows(TimeLimitReached::class, static fn() => CandidateFinder::find(
            $state, self::cubes(1)[0], $config, ConstraintSet::defaults(0.0), $stats, $deadline, new DefaultCandidateScorer(), 1,
        ));

        self::assertSame(1, $stats->candidatesEvaluated);
        self::assertSame(1, $stats->candidatePointsConsidered);
        self::assertTrue($deadline->effortStopped());
    }

    public static function testABeamNodeReachedWithASpentBudgetIsRefusedAndNotCounted(): void
    {
        foreach (['greedy' => PackingConfig::balanced(), 'beam' => PackingConfig::quality()] as $name => $config) {
            $stats = new SearchStats();
            $stats->searchNodesExpanded = 2;
            $deadline = Deadline::ofMilliseconds(60_000)->withEffort(new EffortBudget(maxSearchNodes: 2), $stats);

            $solution = (new ExtremePointSolver())->packOne(Container::create('c', Dimensions::mm(100, 100, 100)), 1, self::cubes(3), $config, $stats, $deadline);

            self::assertSame(2, $stats->searchNodesExpanded, $name);
            self::assertTrue($solution->timeLimitReached, $name);
            self::assertTrue($deadline->effortStopped(), $name);
        }
    }

    public static function testAMaximalSpaceItemReachedWithASpentBudgetIsRefusedAndNotCounted(): void
    {
        $stats = new SearchStats();
        $stats->searchNodesExpanded = 2;
        $deadline = Deadline::ofMilliseconds(60_000)->withEffort(new EffortBudget(maxSearchNodes: 2), $stats);

        $solution = (new MaximalSpaceSolver())->packOne(Container::create('c', Dimensions::mm(100, 100, 100)), 1, self::cubes(3), PackingConfig::quality(), $stats, $deadline);

        self::assertSame(2, $stats->searchNodesExpanded);
        self::assertCount(3, $solution->unpacked);
        self::assertTrue($solution->timeLimitReached);
        self::assertTrue($deadline->effortStopped());
    }

    /** Every placement budget below a wide beam's own use stops it on the limit; its own use does not. */
    public static function testAWideBeamStopsOnEveryPlacementLimitShortOfItsUseAndOnlyThere(): void
    {
        $box = Container::create('c', Dimensions::mm(100, 100, 100));
        $full = new SearchStats();
        (new ExtremePointSolver())->packOne($box, 1, self::cubes(3), PackingConfig::quality(), $full, Deadline::ofMilliseconds(60_000));
        for ($limit = 1; $limit <= $full->placementsAttempted; $limit++) {
            $stats = new SearchStats();
            $deadline = Deadline::ofMilliseconds(60_000)->withEffort(new EffortBudget(maxPlacementAttempts: $limit), $stats);
            (new ExtremePointSolver())->packOne($box, 1, self::cubes(3), PackingConfig::quality(), $stats, $deadline);
            self::assertLessThanOrEqual($limit, $stats->placementsAttempted, "limit {$limit}");
            self::assertSame($limit < $full->placementsAttempted, $deadline->effortStopped(), "limit {$limit}");
        }
    }
}
