<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Algorithm\ContainerState;
use Packvium\Algorithm\Deadline;
use Packvium\Algorithm\ExtremePointSolver;
use Packvium\Algorithm\RawSolution;
use Packvium\Algorithm\SearchStats;
use Packvium\Algorithm\SingleContainerSolution;
use Packvium\Algorithm\SingleContainerSolver;
use Packvium\Config\PackingConfig;
use Packvium\Config\SolverProfile;
use Packvium\Constraint\ConstraintContext;
use Packvium\Constraint\ConstraintResult;
use Packvium\Constraint\PlacementConstraint;
use Packvium\Domain\Container;
use Packvium\Domain\Dimensions;
use Packvium\Domain\ItemInstance;
use Packvium\Domain\Point;
use Packvium\Extension\CandidateScorer;
use Packvium\Extension\ContainerSelector;
use Packvium\Extension\ExtensionRegistry;
use Packvium\Extension\ItemOrderStrategy;
use Packvium\Objective\ObjectiveScore;
use Packvium\Objective\SolutionScorer;
use Packvium\Packer;

/**
 * The extension points: placement constraints, item orderings, solvers and the
 * candidate scorer.
 *
 * An extension may narrow what the library accepts but never widen it — the built-in
 * physical rules are applied alongside a custom constraint, not replaced by it. That
 * boundary is what these tests protect.
 */
final class ExtensionTest extends TestCase
{
    private static function floorOnly(): PlacementConstraint
    {
        return new class implements PlacementConstraint {
            public function evaluate(ConstraintContext $context): ConstraintResult
            {
                return $context->point->z === 0
                    ? ConstraintResult::allow()
                    : ConstraintResult::reject('custom_floor_only');
            }
        };
    }

    // -------------------------------------------------------------------- defaults

    public static function testAnEmptyRegistryRegistersNothing(): void
    {
        $registry = new ExtensionRegistry();
        self::assertSame([], $registry->placementConstraints);
        self::assertSame([], $registry->itemOrderStrategies);
        self::assertSame([], $registry->solvers);
        self::assertNull($registry->candidateScorer);
        self::assertNull($registry->containerSelector);
    }

    // ----------------------------------------------------------------- constraints

    public static function testAConstraintThatRefusesEverythingLeavesTheOrderUnpacked(): void
    {
        $rejectEverything = new class implements PlacementConstraint {
            public function evaluate(ConstraintContext $context): ConstraintResult
            {
                return ConstraintResult::reject('custom_rejection', $context->item->id());
            }
        };
        $result = (new Packer(new PackingConfig(), new ExtensionRegistry([$rejectEverything])))
            ->pack([Support::item('a', 10, 10, 10)], [Support::box('b', 100, 100, 100)]);

        self::assertFalse($result->complete());
        self::assertSame([], $result->containers);
    }

    public static function testACustomConstraintIsAppliedAtEveryCandidatePoint(): void
    {
        $items = [Support::item('a', 40, 40, 40, ['quantity' => 8])];
        $containers = [Support::box('c', 100, 100, 100, ['quantity' => 4])];
        $result = (new Packer(new PackingConfig(), new ExtensionRegistry([self::floorOnly()])))
            ->pack($items, $containers);

        foreach ($result->containers as $container) {
            foreach ($container->placements as $placement) {
                self::assertSame(0, $placement->envelopeOrigin->z);
            }
        }
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    public static function testACustomConstraintCannotWidenTheBuiltInRules(): void
    {
        // An always-allow extension still cannot make an over-large item fit: the
        // physical checks run alongside it rather than being replaced by it.
        $allowEverything = new class implements PlacementConstraint {
            public function evaluate(ConstraintContext $context): ConstraintResult
            {
                return ConstraintResult::allow();
            }
        };
        $result = (new Packer(new PackingConfig(), new ExtensionRegistry([$allowEverything])))
            ->pack([Support::item('slab', 200, 200, 200)], [Support::box('c', 100, 100, 100)]);
        self::assertFalse($result->complete());
    }

    public static function testACustomConstraintDisablesTheLatticeFastPath(): void
    {
        // The lattice places without consulting the constraint list, so a request
        // carrying a custom rule must not be routed to it.
        $result = (new Packer(new PackingConfig(), new ExtensionRegistry([self::floorOnly()])))
            ->pack([Support::item('a', 40, 40, 40, ['quantity' => 4])], [Support::box('c', 100, 100, 100)]);
        self::assertFalse(str_starts_with($result->algorithm->solver, 'grid'));
    }

    // -------------------------------------------------------------------- orderings

    public static function testACustomOrderingIsConsulted(): void
    {
        $strategy = new class implements ItemOrderStrategy {
            /** @var list<int> */
            public array $calls = [];

            public function name(): string
            {
                return 'reverse';
            }

            public function order(array $items, int $seed): array
            {
                $this->calls[] = $seed;
                return array_reverse($items);
            }
        };
        $items = [Support::item('a', 10, 10, 10), Support::item('b', 20, 20, 20)];
        $containers = [Support::box('box', 100, 100, 100)];
        $result = (new Packer(new PackingConfig(seed: 13, multiStartOrders: 5),
            new ExtensionRegistry([], [$strategy])))->pack($items, $containers);

        self::assertSame([13], $strategy->calls, 'the configured seed is handed to the strategy');
        self::assertTrue($result->complete());
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    // ---------------------------------------------------------------------- solvers

    public static function testACustomSolverIsConsulted(): void
    {
        $solver = new class implements SingleContainerSolver {
            public int $calls = 0;

            public function name(): string
            {
                return 'recording';
            }

            public function packOne(Container $container, int $sequence, array $items,
                                    PackingConfig $config, SearchStats $stats, Deadline $deadline): SingleContainerSolution
            {
                $this->calls++;
                return (new ExtremePointSolver())->packOne($container, $sequence, $items, $config, $stats, $deadline);
            }
        };
        $items = [Support::item('a', 40, 40, 40, ['quantity' => 4])];
        $containers = [Support::box('c', 100, 100, 100)];
        $result = (new Packer(new PackingConfig(), new ExtensionRegistry([], [], [$solver])))
            ->pack($items, $containers);

        self::assertGreaterThan(0, $solver->calls);
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    public static function testACustomSolverCompetesRatherThanReplacing(): void
    {
        // A custom solver that packs nothing must not be able to make the whole request
        // fail.
        $packsNothing = new class implements SingleContainerSolver {
            public function name(): string
            {
                return 'nothing';
            }

            public function packOne(Container $container, int $sequence, array $items,
                                    PackingConfig $config, SearchStats $stats, Deadline $deadline): SingleContainerSolution
            {
                return new SingleContainerSolution(new ContainerState($container, $sequence), $items);
            }
        };
        $result = (new Packer(new PackingConfig(), new ExtensionRegistry([], [], [$packsNothing])))
            ->pack([Support::item('a', 40, 40, 40, ['quantity' => 4])], [Support::box('c', 100, 100, 100)]);

        self::assertTrue($result->complete());
        self::assertFalse(str_starts_with($result->algorithm->solver, 'nothing'));
    }

    // --------------------------------------------------------------------- scoring

    public static function testACustomCandidateScorerRanksThePlacements(): void
    {
        // Receives the origin and envelope rather than a Candidate so the finder does
        // not have to build a throwaway object for every point/rotation pair it scores.
        $scorer = new class implements CandidateScorer {
            public int $calls = 0;

            public function score(ContainerState $state, Point $point, Dimensions $envelope): array
            {
                $this->calls++;
                // Prefer the far corner, the exact opposite of the built-in ordering.
                return [-$point->x, -$point->y, -$point->z];
            }
        };
        $items = [Support::item('a', 40, 40, 40, ['quantity' => 2])];
        $containers = [Support::box('c', 200, 200, 40)];
        $result = (new Packer(new PackingConfig(), new ExtensionRegistry(candidateScorer: $scorer)))
            ->pack($items, $containers);

        self::assertGreaterThan(0, $scorer->calls);
        self::assertTrue($result->complete());
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    public static function testACustomContainerSelectorCanPreferLowerDimensionalWeightOverCost(): void
    {
        // "big" is cheaper by cost_minor and would win under the default selector, but
        // a shipping-cost-aware selector can prefer whichever container is lighter to
        // bill -- dimensional weight, exposed exactly for this purpose.
        $selector = new class implements ContainerSelector {
            public int $calls = 0;

            public function score(Container $container, SingleContainerSolution $solution): array
            {
                $this->calls++;
                return [$container->innerDimensions->dimensionalWeight(139, 'in', 'lb')->ticks, $container->id];
            }
        };
        $items = [Support::item('a', 90, 90, 90)];
        $containers = [
            Support::box('big', 200, 200, 200, ['costMinor' => 100]),
            Support::box('small', 110, 110, 110, ['costMinor' => 200]),
        ];
        $result = (new Packer(new PackingConfig(), new ExtensionRegistry(containerSelector: $selector)))
            ->pack($items, $containers);

        self::assertGreaterThan(0, $selector->calls);
        self::assertTrue($result->complete());
        self::assertSame('small', $result->containers[0]->container->id);
    }

    public static function testACustomSolutionScorerIsConsulted(): void
    {
        // Passed directly to Packer's constructor, on the same terms as every other
        // language, since ranking finished solutions is a different concern from
        // shaping how a single container is packed and does not belong in the registry.
        $scorer = new class implements SolutionScorer {
            public int $calls = 0;

            public function score(RawSolution $solution): ObjectiveScore
            {
                $this->calls++;
                return new ObjectiveScore([count($solution->unpacked), count($solution->containers), 0, 0, 0]);
            }
        };
        $items = [Support::item('a', 40, 40, 40, ['quantity' => 4])];
        $containers = [Support::box('c', 100, 100, 100)];
        $result = (new Packer(new PackingConfig(), new ExtensionRegistry(), $scorer))->pack($items, $containers);

        self::assertGreaterThan(0, $scorer->calls);
        self::assertTrue($result->complete());
    }

    public static function testACustomSolutionScorerDeterminesTheReportedScore(): void
    {
        $scorer = new class implements SolutionScorer {
            public function score(RawSolution $solution): ObjectiveScore
            {
                return new ObjectiveScore([0, 0, 0, 0, 42]);
            }
        };
        $items = [Support::item('a', 40, 40, 40, ['quantity' => 4])];
        $containers = [Support::box('c', 100, 100, 100)];
        $result = (new Packer(new PackingConfig(), new ExtensionRegistry(), $scorer))->pack($items, $containers);

        self::assertSame([0, 0, 0, 0, 42], $result->score);
    }

    public static function testAStableSolutionScorerCanSelectTheBetterCentreOfMass(): void
    {
        // Prove that the public scorer changes the selected packing, not only
        // the vector printed on an otherwise unchanged result.
        $scorer = new class implements SolutionScorer {
            public function score(RawSolution $solution): ObjectiveScore
            {
                $offset = 0;
                foreach ($solution->containers as $container) {
                    $offset += $container->centreOfMassOffsetPpm();
                }
                return new ObjectiveScore([
                    count($solution->unpacked),
                    $offset,
                    count($solution->containers),
                    0,
                    0,
                ]);
            }
        };
        $items = [
            Support::item('a', 34, 58, 36, ['weight' => 42, 'quantity' => 3]),
            Support::item('b', 42, 41, 35, ['weight' => 941, 'quantity' => 3]),
            Support::item('c', 40, 32, 47, ['weight' => 914, 'quantity' => 2]),
        ];
        $containers = [Support::box('c', 100, 100, 100, ['quantity' => 2])];
        $config = new PackingConfig(
            profile: SolverProfile::Quality,
            timeLimitMs: 10_000,
            topK: 20,
            seed: 0,
            multiStartOrders: 24,
            maxCandidatesPerItem: 3,
        );
        $default = (new Packer($config))->pack($items, $containers);
        $balanced = (new Packer($config, new ExtensionRegistry(), $scorer))->pack($items, $containers);
        $defaultOffset = array_sum(array_map(
            static fn($container): int => $container->centreOfMassOffsetPpm(),
            $default->containers,
        ));
        $balancedOffset = array_sum(array_map(
            static fn($container): int => $container->centreOfMassOffsetPpm(),
            $balanced->containers,
        ));

        self::assertTrue($balancedOffset < $defaultOffset);
        self::assertSame([0, $balancedOffset, 1, 0, 0], $balanced->score);
    }
}
