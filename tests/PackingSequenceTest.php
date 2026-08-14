<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Domain\AxisAlignedBox;
use Packvium\Domain\Container;
use Packvium\Domain\Dimensions;
use Packvium\Domain\Item;
use Packvium\Domain\Placement;
use Packvium\Domain\Point;
use Packvium\Domain\Rotation;
use Packvium\Packer;
use Packvium\Config\PackingConfig;
use Packvium\Sequence\InvalidDirectionError;
use Packvium\Sequence\LoadingDependencyGraph;
use Packvium\Sequence\Reachability;
use Packvium\Sequence\RouteSequenceError;
use Packvium\Sequence\SequenceError;
use Packvium\Sequence\SequenceReplayError;
use Packvium\Sequence\SequenceStep;
use Packvium\Sequence\SequenceWarning;
use Packvium\Sequence\UnloadingDependencyGraph;
use Packvium\Unit\Length;

/**
 * Loading and unloading dependency graphs and their safe-order
 * simulations.
 *
 * Audit reopen: the original module built one graph (children-dependency, full-scene
 * replay) and treated it as satisfying both "packing" and "unloading" -- it never
 * modelled an item depending on its *supporters* nor replayed from an empty
 * container. This suite tests both graphs, separately and against each other. The
 * Python suite runs the same shape of test in test_packing_sequence.py.
 */
final class PackingSequenceTest extends TestCase
{
    private const MM = 16_000;

    private static function box(int $x, int $y, int $z, int $l, int $w, int $h): AxisAlignedBox
    {
        return new AxisAlignedBox(
            new Point($x * self::MM, $y * self::MM, $z * self::MM),
            Dimensions::mm($l, $w, $h),
        );
    }

    /**
     * One instance of $item placed at (x, y, z) mm with no rotation, geometry and
     * envelope identical -- the minimal Placement verifyLoadingPrefixBusinessRules
     * needs; support/weight propagation reads it through LoadCalculator::units.
     */
    private static function stackedPlacement(Item $item, int $x, int $y, int $z): Placement
    {
        $instance = $item->instances()[0];
        $dimensions = $instance->item->dimensions;
        $at = new Point($x * self::MM, $y * self::MM, $z * self::MM);
        return new Placement($instance, $at, Rotation::LWH, $dimensions, $at, $dimensions);
    }

    // ------------------------------------------------------------------ unloading

    public static function testAStackedPairRequiresTheTopRemovedFirst(): void
    {
        $container = Dimensions::mm(20, 20, 20);
        $bottom = self::box(0, 0, 0, 10, 10, 10);
        $top = self::box(0, 0, 10, 10, 10, 10);
        $graph = UnloadingDependencyGraph::build([$bottom, $top]);
        self::assertSame([0 => [1 => true], 1 => []], $graph->dependsOn());
        self::assertSame([1, 0], UnloadingDependencyGraph::safeRemovalOrder([$bottom, $top], $container));
    }

    public static function testTwoUnstackedItemsHaveNoUnloadingDependency(): void
    {
        $container = Dimensions::mm(20, 10, 10);
        $a = self::box(0, 0, 0, 10, 10, 10);
        $b = self::box(10, 0, 0, 10, 10, 10);
        $graph = UnloadingDependencyGraph::build([$a, $b]);
        self::assertSame([0 => [], 1 => []], $graph->dependsOn());
    }

    public static function testAThreeLevelStackMustComeOffTopToBottom(): void
    {
        $container = Dimensions::mm(10, 10, 30);
        $stack = [self::box(0, 0, 0, 10, 10, 10), self::box(0, 0, 10, 10, 10, 10), self::box(0, 0, 20, 10, 10, 10)];
        self::assertSame([2, 1, 0], UnloadingDependencyGraph::safeRemovalOrder($stack, $container));
    }

    public static function testASingleAllowedDirectionOrdersByDistanceFromTheExit(): void
    {
        $container = Dimensions::mm(20, 10, 10);
        $near = self::box(0, 0, 0, 10, 10, 10);
        $far = self::box(10, 0, 0, 10, 10, 10);
        self::assertSame([0, 1], UnloadingDependencyGraph::safeRemovalOrder([$near, $far], $container, ['-x']));
        self::assertSame([1, 0], UnloadingDependencyGraph::safeRemovalOrder([$near, $far], $container, ['+x']));
    }

    public static function testAnEmptyDirectionSetIsACleanUnloadingDeadlock(): void
    {
        $container = Dimensions::mm(20, 20, 20);
        try {
            UnloadingDependencyGraph::safeRemovalOrder([self::box(0, 0, 0, 10, 10, 10)], $container, []);
            self::assertTrue(false, 'expected SequenceError');
        } catch (SequenceError $error) {
            self::assertSame([0], $error->stuck);
        }
    }

    public static function testTheRemovalOrderIsDeterministicAcrossTies(): void
    {
        $container = Dimensions::mm(30, 10, 10);
        $boxes = [self::box(0, 0, 0, 10, 10, 10), self::box(10, 0, 0, 10, 10, 10), self::box(20, 0, 0, 10, 10, 10)];
        $first = UnloadingDependencyGraph::safeRemovalOrder($boxes, $container);
        for ($i = 0; $i < 5; $i++) {
            self::assertSame($first, UnloadingDependencyGraph::safeRemovalOrder($boxes, $container));
        }
    }

    // ------------------------------------------------------------------ route

    public static function testARouteRespectingArrangementUnloadsStopByStop(): void
    {
        // `near` (due at stop 0, the first stop) sits flush against the only door
        // (`-x`); `far` (due at stop 1) sits behind it. LIFO holds: the earlier stop
        // is nearer the exit, so it never needs `far` moved out of the way first.
        $container = Dimensions::mm(20, 10, 10);
        $near = self::box(0, 0, 0, 10, 10, 10);
        $far = self::box(10, 0, 0, 10, 10, 10);
        $order = UnloadingDependencyGraph::safeRouteRemovalOrder([$near, $far], [0, 1], $container, ['-x']);
        self::assertSame([0, 1], $order);
    }

    public static function testALaterStopItemBlockingAnEarlierStopItemIsARouteViolation(): void
    {
        // The arrangement flipped: `near` (flush against the only door) is due at
        // stop 1, `far` (behind it) is due at stop 0 and must leave first -- but
        // `far` cannot reach the door without `near`, which is not due to leave yet,
        // moving first. This is exactly the property that separates cartonization
        // from vehicle loading.
        $container = Dimensions::mm(20, 10, 10);
        $near = self::box(0, 0, 0, 10, 10, 10);
        $far = self::box(10, 0, 0, 10, 10, 10);
        try {
            UnloadingDependencyGraph::safeRouteRemovalOrder([$near, $far], [1, 0], $container, ['-x']);
            self::assertTrue(false, 'expected RouteSequenceError');
        } catch (RouteSequenceError $error) {
            self::assertSame(0, $error->stop);
            self::assertSame([1], $error->stuck); // `far` (index 1) is the one stuck
        }
    }

    public static function testAnUnroutedPlacementNeverLeavesButCanStillBlock(): void
    {
        // A placement with no stop index (a fixture riding the whole route) is never
        // scheduled for removal, but still physically occupies its space -- if it
        // happens to stand in a routed item's only exit, that is a genuine, permanent
        // route violation.
        $container = Dimensions::mm(20, 10, 10);
        $fixture = self::box(0, 0, 0, 10, 10, 10);
        $due = self::box(10, 0, 0, 10, 10, 10);
        try {
            UnloadingDependencyGraph::safeRouteRemovalOrder([$fixture, $due], [null, 0], $container, ['-x']);
            self::assertTrue(false, 'expected RouteSequenceError');
        } catch (RouteSequenceError $error) {
            self::assertSame(0, $error->stop);
            self::assertSame([1], $error->stuck);
        }
    }

    public static function testASecondEscapeDirectionSavesAnOtherwiseBlockedStop(): void
    {
        // The same blocked arrangement as the violation case above, but with `+z`
        // also allowed: the usual engine scope (horizontal *or* vertical) is
        // exactly what safeRemovalOrder's directions sweep already models, so a clear
        // vertical lift is enough even when the horizontal exit is not.
        $container = Dimensions::mm(20, 10, 10);
        $near = self::box(0, 0, 0, 10, 10, 10);
        $far = self::box(10, 0, 0, 10, 10, 10);
        $order = UnloadingDependencyGraph::safeRouteRemovalOrder([$near, $far], [1, 0], $container, ['-x', '+z']);
        self::assertSame([1, 0], $order); // far (stop 0, index 1) lifts straight out first
    }

    public static function testNoRoutedPlacementsSchedulesNothing(): void
    {
        // Every existing single-stop request leaves stop_index unset on every item --
        // this must be a complete no-op, not merely a small one.
        $container = Dimensions::mm(20, 10, 10);
        $boxes = [self::box(0, 0, 0, 10, 10, 10), self::box(10, 0, 0, 10, 10, 10)];
        self::assertSame([], UnloadingDependencyGraph::safeRouteRemovalOrder($boxes, [null, null], $container));
    }

    public static function testItemsAtTheSameStopMayBeRemovedInEitherOrder(): void
    {
        // Two items due at the same stop, side by side with only one clear at a time
        // along the single allowed exit, is not a route violation -- it is an
        // ordinary same-stop tie, resolved the same deterministic way
        // safeRemovalOrder resolves any tie.
        $container = Dimensions::mm(20, 10, 10);
        $near = self::box(0, 0, 0, 10, 10, 10);
        $far = self::box(10, 0, 0, 10, 10, 10);
        $order = UnloadingDependencyGraph::safeRouteRemovalOrder([$near, $far], [0, 0], $container, ['-x']);
        self::assertSame([0, 1], $order);
    }

    public static function testRouteOrderAndStackingCanAgree(): void
    {
        // `top` (the contact graph: it must come off before `bottom`
        // regardless of any route) is also due at the earlier stop here, so the
        // structural requirement and the route requirement point the same way and
        // the order satisfies both at once.
        $container = Dimensions::mm(10, 10, 20);
        $bottom = self::box(0, 0, 0, 10, 10, 10);
        $top = self::box(0, 0, 10, 10, 10, 10);
        $order = UnloadingDependencyGraph::safeRouteRemovalOrder([$bottom, $top], [1, 0], $container);
        self::assertSame([1, 0], $order); // top (stop 0, index 1) first, then bottom (stop 1, index 0)
    }

    public static function testRouteOrderAndStackingCanConflict(): void
    {
        // The reverse of the previous case: `bottom` is due at the earlier stop, but
        // `top` -- structurally required off first, whatever the route says -- is
        // not due until later. No order can satisfy both, so this must be reported
        // as a route violation rather than silently reordered around the structural
        // constraint.
        $container = Dimensions::mm(10, 10, 20);
        $bottom = self::box(0, 0, 0, 10, 10, 10);
        $top = self::box(0, 0, 10, 10, 10, 10);
        try {
            UnloadingDependencyGraph::safeRouteRemovalOrder([$bottom, $top], [0, 1], $container);
            self::assertTrue(false, 'expected RouteSequenceError');
        } catch (RouteSequenceError $error) {
            self::assertSame(0, $error->stop);
            self::assertSame([0], $error->stuck);
        }
    }

    // ------------------------------------------------------------- reachability

    public static function testAnUnblockedItemIsReachableWithNoEvidence(): void
    {
        $container = Dimensions::mm(20, 10, 10);
        $near = self::box(0, 0, 0, 10, 10, 10);
        $far = self::box(10, 0, 0, 10, 10, 10);
        $reachability = UnloadingDependencyGraph::placementReachability([$near, $far], $container, null, ['-x', '+x']);
        foreach ($reachability as $entry) {
            self::assertTrue($entry->reachable);
            self::assertSame([], $entry->blockedBySupport);
            self::assertSame([], $entry->blockedByNeighbors);
            self::assertSame([], $entry->blockedByRoute);
        }
    }

    public static function testASingleExitDirectionMakesTheFarItemUnreachableThoughGeometricallyValid(): void
    {
        // A geometrically valid packing (no collisions, both boxes within bounds)
        // where `far` is nonetheless unreachable right now: its only allowed exit
        // sweep runs straight through `near`.
        $container = Dimensions::mm(20, 10, 10);
        $near = self::box(0, 0, 0, 10, 10, 10);
        $far = self::box(10, 0, 0, 10, 10, 10);
        $reachability = UnloadingDependencyGraph::placementReachability([$near, $far], $container, null, ['-x']);
        self::assertTrue($reachability[0]->reachable);
        self::assertFalse($reachability[1]->reachable);
        self::assertSame([0 => true], $reachability[1]->blockedByNeighbors);
        self::assertSame([], $reachability[1]->blockedBySupport);
        self::assertSame([], $reachability[1]->blockedByRoute);
    }

    public static function testAnItemBoxedInOnEverySideIsUnreachableEvenThoughNothingHasMoved(): void
    {
        // The centre item has a neighbour flush against all six of its faces --
        // every exit sweep is blocked and its one supporter-side neighbour also
        // still rests on it, so it cannot be reached at all.
        $container = Dimensions::mm(30, 30, 30);
        $center = self::box(10, 10, 10, 10, 10, 10);
        $boxes = [
            $center,
            self::box(0, 10, 10, 10, 10, 10), self::box(20, 10, 10, 10, 10, 10),
            self::box(10, 0, 10, 10, 10, 10), self::box(10, 20, 10, 10, 10, 10),
            self::box(10, 10, 0, 10, 10, 10), self::box(10, 10, 20, 10, 10, 10),
        ];
        $reachability = UnloadingDependencyGraph::placementReachability($boxes, $container);
        self::assertFalse($reachability[0]->reachable);
        // Evidence, not an order -- sort the keys before comparing.
        $blockingIndexes = array_keys($reachability[0]->blockedByNeighbors);
        sort($blockingIndexes);
        self::assertSame([1, 2, 3, 4, 5, 6], $blockingIndexes);
        self::assertSame([6 => true], $reachability[0]->blockedBySupport);
        // Every side neighbour is itself reachable except the -z one (index 5),
        // which the centre item still rests on.
        self::assertSame([true, true, true, true, false, true], array_map(
            static fn(Reachability $entry): bool => $entry->reachable,
            array_slice($reachability, 1),
        ));
        self::assertSame([0 => true], $reachability[5]->blockedBySupport);
    }

    public static function testAChildStillPresentBlocksReachabilityEvenWithAClearSweep(): void
    {
        $container = Dimensions::mm(10, 10, 20);
        $bottom = self::box(0, 0, 0, 10, 10, 10);
        $top = self::box(0, 0, 10, 10, 10, 10);
        $reachability = UnloadingDependencyGraph::placementReachability([$bottom, $top], $container);
        self::assertFalse($reachability[0]->reachable);
        self::assertSame([1 => true], $reachability[0]->blockedBySupport);
        self::assertSame([], $reachability[0]->blockedByNeighbors);
        self::assertTrue($reachability[1]->reachable);
    }

    public static function testRouteOrderMakesAnOtherwiseClearItemUnreachable(): void
    {
        // `near` has a clear geometric exit, but it is due at stop 1 while `far`
        // (due at the earlier stop 0) is still present -- the route
        // forbids removing it out of order.
        $container = Dimensions::mm(20, 10, 10);
        $near = self::box(0, 0, 0, 10, 10, 10);
        $far = self::box(10, 0, 0, 10, 10, 10);
        $reachability = UnloadingDependencyGraph::placementReachability([$near, $far], $container, [1, 0], ['-x', '+x']);
        self::assertFalse($reachability[0]->reachable);
        self::assertSame([1 => true], $reachability[0]->blockedByRoute);
        self::assertSame([], $reachability[0]->blockedByNeighbors);
    }

    public static function testNoStopsLeavesRouteBlockingEntirelyUnaffected(): void
    {
        $container = Dimensions::mm(20, 10, 10);
        $near = self::box(0, 0, 0, 10, 10, 10);
        $far = self::box(10, 0, 0, 10, 10, 10);
        $reachability = UnloadingDependencyGraph::placementReachability([$near, $far], $container, null, ['-x']);
        foreach ($reachability as $entry) {
            self::assertSame([], $entry->blockedByRoute);
        }
    }

    public static function testItemsAtTheSameStopNeverBlockEachOtherByRoute(): void
    {
        $container = Dimensions::mm(20, 10, 10);
        $near = self::box(0, 0, 0, 10, 10, 10);
        $far = self::box(10, 0, 0, 10, 10, 10);
        $reachability = UnloadingDependencyGraph::placementReachability([$near, $far], $container, [0, 0], ['-x', '+x']);
        foreach ($reachability as $entry) {
            self::assertSame([], $entry->blockedByRoute);
        }
    }

    public static function testAnEmptyDirectionSetLeavesEveryPlacementUnreachableWithNoNamedNeighbor(): void
    {
        // No allowed direction at all is a total prohibition, not a specific
        // collision -- `blockedByNeighbors` names nothing because there is no
        // direction to check a sweep against.
        $container = Dimensions::mm(10, 10, 10);
        $reachability = UnloadingDependencyGraph::placementReachability([self::box(0, 0, 0, 10, 10, 10)], $container, null, []);
        self::assertFalse($reachability[0]->reachable);
        self::assertSame([], $reachability[0]->blockedByNeighbors);
    }

    public static function testReachabilityIsReportedForEveryPlacementInInputOrder(): void
    {
        $container = Dimensions::mm(30, 10, 10);
        $boxes = [self::box(0, 0, 0, 10, 10, 10), self::box(10, 0, 0, 10, 10, 10), self::box(20, 0, 0, 10, 10, 10)];
        $reachability = UnloadingDependencyGraph::placementReachability($boxes, $container);
        self::assertSame([0, 1, 2], array_map(static fn(Reachability $entry): int => $entry->index, $reachability));
    }

    public static function testReachabilityEvidenceSerializesInStableIndexOrder(): void
    {
        $entry = new Reachability(
            7,
            false,
            [9 => true, 2 => true],
            [5 => true, 1 => true],
            [8 => true, 3 => true],
        );

        self::assertSame([
            'index' => 7,
            'reachable' => false,
            'blocked_by_support' => [2, 9],
            'blocked_by_neighbors' => [1, 5],
            'blocked_by_route' => [3, 8],
        ], $entry->toArray());
    }

    // -------------------------------------------------------------------- loading

    public static function testAStackedPairRequiresTheBottomLoadedFirst(): void
    {
        $container = Dimensions::mm(20, 20, 20);
        $bottom = self::box(0, 0, 0, 10, 10, 10);
        $top = self::box(0, 0, 10, 10, 10, 10);
        $graph = LoadingDependencyGraph::build([$bottom, $top]);
        self::assertSame([0 => [], 1 => [0 => true]], $graph->dependsOn());
        self::assertSame([0, 1], LoadingDependencyGraph::safeLoadingOrder([$bottom, $top], $container));
    }

    public static function testTwoUnstackedItemsHaveNoLoadingDependency(): void
    {
        $container = Dimensions::mm(20, 10, 10);
        $a = self::box(0, 0, 0, 10, 10, 10);
        $b = self::box(10, 0, 0, 10, 10, 10);
        $graph = LoadingDependencyGraph::build([$a, $b]);
        self::assertSame([0 => [], 1 => []], $graph->dependsOn());
    }

    public static function testAThreeLevelStackMustLoadBottomToTop(): void
    {
        $container = Dimensions::mm(10, 10, 30);
        $stack = [self::box(0, 0, 0, 10, 10, 10), self::box(0, 0, 10, 10, 10, 10), self::box(0, 0, 20, 10, 10, 10)];
        self::assertSame([0, 1, 2], LoadingDependencyGraph::safeLoadingOrder($stack, $container));
    }

    public static function testLoadingAndUnloadingAStackAreExactOpposites(): void
    {
        // Not a coincidence this module relies on: unloading a stack top-to-bottom
        // and loading it bottom-to-top are the same claim about the same geometry,
        // seen from opposite ends of the same replay.
        $container = Dimensions::mm(10, 10, 40);
        $stack = [
            self::box(0, 0, 0, 10, 10, 10), self::box(0, 0, 10, 10, 10, 10),
            self::box(0, 0, 20, 10, 10, 10), self::box(0, 0, 30, 10, 10, 10),
        ];
        self::assertSame(
            array_reverse(UnloadingDependencyGraph::safeRemovalOrder($stack, $container)),
            LoadingDependencyGraph::safeLoadingOrder($stack, $container),
        );
    }

    public static function testASingleAllowedDirectionOrdersLoadingByDistanceFromTheDoor(): void
    {
        // The loading counterpart of the unloading LIFO-shape test -- and the reverse
        // of it, not a repeat: with only '-x' allowed, 'far' must be loaded first,
        // because once 'near' (flush against the '-x' wall) is placed, nothing can
        // ever enter through '-x' again to reach 'far's position. A naive "place
        // whichever is available now" greedy loader gets this backwards -- 'near'
        // looks immediately loadable too, and taking it first permanently traps
        // 'far' (measured directly while building this module). safeLoadingOrder
        // avoids the trap by construction; see its own docblock.
        $container = Dimensions::mm(20, 10, 10);
        $near = self::box(0, 0, 0, 10, 10, 10);
        $far = self::box(10, 0, 0, 10, 10, 10);
        self::assertSame([1, 0], LoadingDependencyGraph::safeLoadingOrder([$near, $far], $container, ['-x']));
        self::assertSame([0, 1], LoadingDependencyGraph::safeLoadingOrder([$near, $far], $container, ['+x']));
    }

    public static function testAnEmptyDirectionSetIsACleanLoadingDeadlock(): void
    {
        $container = Dimensions::mm(20, 20, 20);
        try {
            LoadingDependencyGraph::safeLoadingOrder([self::box(0, 0, 0, 10, 10, 10)], $container, []);
            self::assertTrue(false, 'expected SequenceError');
        } catch (SequenceError $error) {
            self::assertSame([0], $error->stuck);
        }
    }

    public static function testTheLoadingOrderIsDeterministicAcrossTies(): void
    {
        $container = Dimensions::mm(30, 10, 10);
        $boxes = [self::box(0, 0, 0, 10, 10, 10), self::box(10, 0, 0, 10, 10, 10), self::box(20, 0, 0, 10, 10, 10)];
        $first = LoadingDependencyGraph::safeLoadingOrder($boxes, $container);
        for ($i = 0; $i < 5; $i++) {
            self::assertSame($first, LoadingDependencyGraph::safeLoadingOrder($boxes, $container));
        }
    }

    // -------------------------------------------------------------------- both

    public static function testSupportEdgesAreProvablyAcyclicAcrossRandomisedStacks(): void
    {
        for ($seed = 0; $seed < 20; $seed++) {
            mt_srand($seed);
            $boxes = [];
            $columns = 1 + mt_rand(0, 3);
            for ($column = 0; $column < $columns; $column++) {
                $x = $column * 10;
                $z = 0;
                $levels = 1 + mt_rand(0, 3);
                for ($level = 0; $level < $levels; $level++) {
                    $boxes[] = self::box($x, 0, $z, 10, 10, 10);
                    $z += 10;
                }
            }
            self::assertTrue(UnloadingDependencyGraph::build($boxes)->isAcyclic(), "seed {$seed}");
            self::assertTrue(LoadingDependencyGraph::build($boxes)->isAcyclic(), "seed {$seed}");
        }
    }

    public static function testASyntheticUnloadingCycleIsDetected(): void
    {
        // Not derived from geometry -- a direct graph-level cycle, to prove the
        // detector is correct independently of whether real geometry can ever
        // produce one (it provably cannot for support edges alone; see the class
        // docblock).
        $graph = new UnloadingDependencyGraph([0 => [1 => true], 1 => [0 => true]]);
        self::assertFalse($graph->isAcyclic());
    }

    public static function testASyntheticLoadingCycleIsDetected(): void
    {
        $graph = new LoadingDependencyGraph([0 => [1 => true], 1 => [0 => true]]);
        self::assertFalse($graph->isAcyclic());
    }

    public static function testAnUnknownDirectionIsRejectedNotTreatedAsMinusZ(): void
    {
        // The concrete bug the audit reopened this over: an unrecognised direction
        // string used to fall through to '-z' silently. Checked against both graphs
        // and both the generator and replay entry points.
        $container = Dimensions::mm(20, 20, 20);
        $single = [self::box(0, 0, 0, 10, 10, 10)];
        $calls = [
            static fn() => UnloadingDependencyGraph::safeRemovalOrder($single, $container, ['sideways']),
            static fn() => LoadingDependencyGraph::safeLoadingOrder($single, $container, ['sideways']),
            static fn() => UnloadingDependencyGraph::replayRemovalOrder($single, $container, [0], ['sideways']),
            static fn() => LoadingDependencyGraph::replayLoadingOrder($single, $container, [0], ['sideways']),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::assertTrue(false, 'expected InvalidDirectionError');
            } catch (InvalidDirectionError $error) {
                self::assertSame('sideways', $error->direction);
            }
        }
    }

    // ------------------------------------------------------------- replay: unloading

    // ReplayRemovalOrder/replayLoadingOrder are second, independent code
    // paths over the same geometric primitives -- neither calls its matching
    // generator, so a bug in the generator's own search/tie-break logic cannot hide
    // from either.

    public static function testReplayingAGeneratedRemovalOrderRaisesNothing(): void
    {
        $container = Dimensions::mm(10, 10, 30);
        $stack = [self::box(0, 0, 0, 10, 10, 10), self::box(0, 0, 10, 10, 10, 10), self::box(0, 0, 20, 10, 10, 10)];
        $order = UnloadingDependencyGraph::safeRemovalOrder($stack, $container);
        UnloadingDependencyGraph::replayRemovalOrder($stack, $container, $order); // must not throw
        self::assertTrue(true);
    }

    public static function testReplayingARealPackedContainersOrderRaisesNothing(): void
    {
        // The same shape of real, solver-produced packing used to confirm the dependency graph's
        // unreachability proof empirically -- here confirming the independent replay
        // agrees with the generator on every one of its steps, not only on toy examples.
        mt_srand(0);
        $items = [];
        for ($i = 0; $i < 100; $i++) {
            $items[] = new Item(
                "sku-{$i}",
                Dimensions::mm(mt_rand(5, 40), mt_rand(5, 40), mt_rand(5, 40)),
            );
        }
        $containers = [new Container('c', Dimensions::mm(200, 200, 200))];
        $result = (new Packer(new PackingConfig()))->pack($items, $containers);
        $placed = $result->containers[0];
        $boxes = array_map(static fn($placement) => $placement->box(), $placed->placements);
        $order = UnloadingDependencyGraph::safeRemovalOrder($boxes, $placed->container->innerDimensions);
        self::assertSame(count($boxes), count($order));
        UnloadingDependencyGraph::replayRemovalOrder($boxes, $placed->container->innerDimensions, $order);
        self::assertTrue(true);
        $loadingOrder = LoadingDependencyGraph::safeLoadingOrder($boxes, $placed->container->innerDimensions);
        self::assertSame(count($boxes), count($loadingOrder));
        LoadingDependencyGraph::replayLoadingOrder($boxes, $placed->container->innerDimensions, $loadingOrder);
        self::assertTrue(true);
    }

    public static function testReplayingAReversedStackRemovalOrderIsCaughtAsBlockedBySupport(): void
    {
        $container = Dimensions::mm(20, 20, 20);
        $bottom = self::box(0, 0, 0, 10, 10, 10);
        $top = self::box(0, 0, 10, 10, 10, 10);
        try {
            UnloadingDependencyGraph::replayRemovalOrder([$bottom, $top], $container, [0, 1]); // bottom first is wrong
            self::assertTrue(false, 'expected SequenceReplayError');
        } catch (SequenceReplayError $error) {
            self::assertSame(0, $error->index);
            self::assertSame(0, $error->step);
        }
    }

    public static function testReplayingTheWrongRemovalExitDirectionIsCaughtAsBlockedByGeometry(): void
    {
        $container = Dimensions::mm(20, 10, 10);
        $near = self::box(0, 0, 0, 10, 10, 10);
        $far = self::box(10, 0, 0, 10, 10, 10);
        try {
            // far first blocks on near, with only '-x' allowed
            UnloadingDependencyGraph::replayRemovalOrder([$near, $far], $container, [1, 0], ['-x']);
            self::assertTrue(false, 'expected SequenceReplayError');
        } catch (SequenceReplayError $error) {
            self::assertSame(1, $error->index);
            self::assertSame(0, $error->step);
        }
    }

    public static function testReplayingAMalformedRemovalOrderIsRejectedBeforeAnyGeometryCheck(): void
    {
        $container = Dimensions::mm(20, 20, 20);
        $boxes = [self::box(0, 0, 0, 10, 10, 10), self::box(10, 0, 0, 10, 10, 10)];
        self::assertThrows(SequenceReplayError::class, static fn() =>
            UnloadingDependencyGraph::replayRemovalOrder($boxes, $container, [0, 0])); // duplicate, missing index 1
        self::assertThrows(SequenceReplayError::class, static fn() =>
            UnloadingDependencyGraph::replayRemovalOrder($boxes, $container, [0])); // too short
    }

    // --------------------------------------------------------------- replay: loading

    public static function testReplayingAGeneratedLoadingOrderRaisesNothing(): void
    {
        $container = Dimensions::mm(10, 10, 30);
        $stack = [self::box(0, 0, 0, 10, 10, 10), self::box(0, 0, 10, 10, 10, 10), self::box(0, 0, 20, 10, 10, 10)];
        $order = LoadingDependencyGraph::safeLoadingOrder($stack, $container);
        LoadingDependencyGraph::replayLoadingOrder($stack, $container, $order); // must not throw
        self::assertTrue(true);
    }

    public static function testReplayingATopFirstLoadingOrderIsCaughtAsBlockedBySupport(): void
    {
        $container = Dimensions::mm(20, 20, 20);
        $bottom = self::box(0, 0, 0, 10, 10, 10);
        $top = self::box(0, 0, 10, 10, 10, 10);
        try {
            LoadingDependencyGraph::replayLoadingOrder([$bottom, $top], $container, [1, 0]); // top first: no supporter yet
            self::assertTrue(false, 'expected SequenceReplayError');
        } catch (SequenceReplayError $error) {
            self::assertSame(1, $error->index);
            self::assertSame(0, $error->step);
        }
    }

    public static function testReplayingTheWrongLoadingEntryDirectionIsCaughtAsBlockedByGeometry(): void
    {
        $container = Dimensions::mm(20, 10, 10);
        $near = self::box(0, 0, 0, 10, 10, 10);
        $far = self::box(10, 0, 0, 10, 10, 10);
        try {
            LoadingDependencyGraph::replayLoadingOrder([$near, $far], $container, [0, 1], ['-x']); // near first permanently blocks far
            self::assertTrue(false, 'expected SequenceReplayError');
        } catch (SequenceReplayError $error) {
            self::assertSame(1, $error->index);
            self::assertSame(1, $error->step);
        }
    }

    public static function testReplayingAMalformedLoadingOrderIsRejectedBeforeAnyGeometryCheck(): void
    {
        $container = Dimensions::mm(20, 20, 20);
        $boxes = [self::box(0, 0, 0, 10, 10, 10), self::box(10, 0, 0, 10, 10, 10)];
        self::assertThrows(SequenceReplayError::class, static fn() =>
            LoadingDependencyGraph::replayLoadingOrder($boxes, $container, [0, 0]));
        self::assertThrows(SequenceReplayError::class, static fn() =>
            LoadingDependencyGraph::replayLoadingOrder($boxes, $container, [0]));
    }

    public static function testReplayRejectsOutsideAndCollidingGeometry(): void
    {
        $container = Dimensions::mm(20, 20, 20);
        self::assertThrows(
            SequenceReplayError::class,
            static fn() => LoadingDependencyGraph::replayLoadingOrder(
                [self::box(15, 0, 0, 10, 10, 10)],
                $container,
                [0],
            ),
        );
        self::assertThrows(
            SequenceReplayError::class,
            static fn() => LoadingDependencyGraph::replayLoadingOrder(
                [self::box(0, 0, 0, 10, 10, 10), self::box(5, 0, 0, 10, 10, 10)],
                $container,
                [0, 1],
            ),
        );
    }

    // ------------------------------------------------------------------- evidence

    public static function testRemovalEvidenceNamesTheSupportDependencyAndDirectionUsed(): void
    {
        $container = Dimensions::mm(20, 20, 20);
        $bottom = self::box(0, 0, 0, 10, 10, 10);
        $top = self::box(0, 0, 10, 10, 10, 10);
        $steps = UnloadingDependencyGraph::safeRemovalOrderWithEvidence([$bottom, $top], $container);
        self::assertSame([1, 0], array_map(static fn($s) => $s->index, $steps));
        [$topStep, $bottomStep] = $steps;
        self::assertSame([], $topStep->dependsOn); // nothing rests on top
        self::assertSame('+x', $topStep->direction); // first tried direction with nothing else present
        self::assertSame([1], array_keys($bottomStep->dependsOn)); // top structurally rests on bottom
    }

    public static function testLoadingEvidenceNamesTheSupportDependencyAndDirectionUsed(): void
    {
        $container = Dimensions::mm(20, 20, 20);
        $bottom = self::box(0, 0, 0, 10, 10, 10);
        $top = self::box(0, 0, 10, 10, 10, 10);
        $steps = LoadingDependencyGraph::safeLoadingOrderWithEvidence([$bottom, $top], $container);
        self::assertSame([0, 1], array_map(static fn($s) => $s->index, $steps));
        [$bottomStep, $topStep] = $steps;
        self::assertSame([], $bottomStep->dependsOn);
        self::assertSame([0], array_keys($topStep->dependsOn)); // top depends on bottom being loaded first
    }

    public static function testEvidenceOrderingMatchesThePlainIndexOrders(): void
    {
        $container = Dimensions::mm(10, 10, 30);
        $stack = [self::box(0, 0, 0, 10, 10, 10), self::box(0, 0, 10, 10, 10, 10), self::box(0, 0, 20, 10, 10, 10)];
        $removalIndexes = array_map(
            static fn($s) => $s->index,
            UnloadingDependencyGraph::safeRemovalOrderWithEvidence($stack, $container),
        );
        self::assertSame(UnloadingDependencyGraph::safeRemovalOrder($stack, $container), $removalIndexes);
        $loadingIndexes = array_map(
            static fn($s) => $s->index,
            LoadingDependencyGraph::safeLoadingOrderWithEvidence($stack, $container),
        );
        self::assertSame(LoadingDependencyGraph::safeLoadingOrder($stack, $container), $loadingIndexes);
    }


    /**
     * A scene corpus shared with the other implementations of this library, kept one
     * level above this package. A published copy of the package does not carry it, so
     * the tests that read it skip rather than fail.
     */
    private const SHARED_SCENES = __DIR__ . '/../../conformance/scene/sequence-fixtures.json';

    private static function sharedScenes(): array
    {
        if (!is_file(self::SHARED_SCENES)) {
            self::skip('the shared cross-language scene corpus is not part of this package');
        }

        return json_decode((string) file_get_contents(self::SHARED_SCENES), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function testSharedFourLanguageSequenceFixtures(): void
    {
        $payload = self::sharedScenes();
        foreach ($payload['scenes'] as $scene) {
            $container = new Dimensions(
                new Length($scene['container']['length']),
                new Length($scene['container']['width']),
                new Length($scene['container']['height']),
            );
            $boxes = array_map(
                static fn(array $raw): AxisAlignedBox => new AxisAlignedBox(
                    new Point($raw['origin']['x'], $raw['origin']['y'], $raw['origin']['z']),
                    new Dimensions(
                        new Length($raw['dimensions']['length']),
                        new Length($raw['dimensions']['width']),
                        new Length($raw['dimensions']['height']),
                    ),
                ),
                $scene['boxes'],
            );
            $loadingGraph = array_map(
                static fn(array $dependencies): array => array_map('intval', array_keys($dependencies)),
                LoadingDependencyGraph::build($boxes)->dependsOn(),
            );
            $unloadingGraph = array_map(
                static fn(array $dependencies): array => array_map('intval', array_keys($dependencies)),
                UnloadingDependencyGraph::build($boxes)->dependsOn(),
            );
            self::assertSame($scene['loading_graph'], $loadingGraph, $scene['id']);
            self::assertSame($scene['unloading_graph'], $unloadingGraph, $scene['id']);
            if (isset($scene['reachability'])) {
                // Each `blocked_by_*` set is evidence, not an order -- sort before
                // comparing, the same way the Python suite compares `sorted()`
                // frozensets.
                $sortedIndexes = static function (array $indexed): array {
                    $indexes = array_map('intval', array_keys($indexed));
                    sort($indexes);
                    return $indexes;
                };
                $reachabilityArray = static function (Reachability $entry) use ($sortedIndexes): array {
                    return [
                        'index' => $entry->index,
                        'reachable' => $entry->reachable,
                        'blocked_by_support' => $sortedIndexes($entry->blockedBySupport),
                        'blocked_by_neighbors' => $sortedIndexes($entry->blockedByNeighbors),
                        'blocked_by_route' => $sortedIndexes($entry->blockedByRoute),
                    ];
                };
                self::assertSame(
                    $scene['reachability'],
                    array_map(
                        $reachabilityArray,
                        UnloadingDependencyGraph::placementReachability($boxes, $container, $scene['stops'] ?? null, $scene['directions']),
                    ),
                    $scene['id'],
                );
            }
            if (isset($scene['expected_error'])) {
                try {
                    LoadingDependencyGraph::safeLoadingOrder($boxes, $container, $scene['directions']);
                    throw new \RuntimeException("{$scene['id']}: expected SequenceError");
                } catch (SequenceError $error) {
                    self::assertSame($scene['expected_error'], $error->toArray());
                }
                continue;
            }
            if (!isset($scene['loading_steps'])) {
                continue;
            }
            $stepArray = static fn(SequenceStep $step): array => $step->toArray();
            self::assertSame(
                $scene['loading_steps'],
                array_map($stepArray, LoadingDependencyGraph::safeLoadingOrderWithEvidence($boxes, $container, $scene['directions'])),
                $scene['id'],
            );
            self::assertSame(
                $scene['unloading_steps'],
                array_map($stepArray, UnloadingDependencyGraph::safeRemovalOrderWithEvidence($boxes, $container, $scene['directions'])),
                $scene['id'],
            );
        }
    }

    // ------------------------------------ business-rule prefix replay

    public static function testALoadingPrefixThatOverloadsAFragileSupporterIsCaughtAtItsStep(): void
    {
        $fragile = Item::create('fragile', Dimensions::mm(10, 10, 5), weight: '1kg', maxTopLoad: '500g');
        $heavy = Item::create('heavy', Dimensions::mm(10, 10, 5), weight: '5kg');
        $placements = [self::stackedPlacement($fragile, 0, 0, 0), self::stackedPlacement($heavy, 0, 0, 5)];
        $container = Container::create('c', Dimensions::mm(10, 10, 20));
        try {
            LoadingDependencyGraph::verifyLoadingPrefixBusinessRules($placements, [0, 1], $container);
            self::assertTrue(false, 'expected SequenceReplayError');
        } catch (SequenceReplayError $error) {
            self::assertSame(1, $error->step);
            self::assertTrue(str_contains($error->reason, 'top_load_exceeded'), $error->reason);
        }
    }

    public static function testALoadingPrefixThatExceedsAStackedItemLimitIsCaughtAtItsStep(): void
    {
        $base = Item::create('base', Dimensions::mm(10, 10, 5), maxStackedItems: 1);
        $filler = Item::create('filler', Dimensions::mm(10, 10, 5), quantity: 2);
        [$first, $second] = $filler->instances();
        $d = $first->item->dimensions;
        $placements = [
            self::stackedPlacement($base, 0, 0, 0),
            new Placement($first, new Point(0, 0, 5 * self::MM), Rotation::LWH, $d, new Point(0, 0, 5 * self::MM), $d),
            new Placement($second, new Point(0, 0, 10 * self::MM), Rotation::LWH, $d, new Point(0, 0, 10 * self::MM), $d),
        ];
        $container = Container::create('c', Dimensions::mm(10, 10, 20));
        try {
            LoadingDependencyGraph::verifyLoadingPrefixBusinessRules($placements, [0, 1, 2], $container);
            self::assertTrue(false, 'expected SequenceReplayError');
        } catch (SequenceReplayError $error) {
            self::assertSame(2, $error->step);
            self::assertTrue(str_contains($error->reason, 'stacked_item_limit_exceeded'), $error->reason);
        }
    }

    public static function testALoadingPrefixThatCrushesAContainersFloorDensityLimitIsCaught(): void
    {
        $heavy = Item::create('heavy', Dimensions::mm(10, 10, 5), weight: '10kg');
        $container = Container::create('c', Dimensions::mm(10, 10, 10), maxStackDensity: '1kg');
        try {
            LoadingDependencyGraph::verifyLoadingPrefixBusinessRules([self::stackedPlacement($heavy, 0, 0, 0)], [0], $container);
            self::assertTrue(false, 'expected SequenceReplayError');
        } catch (SequenceReplayError $error) {
            self::assertSame(0, $error->step);
            self::assertTrue(str_contains($error->reason, 'stack_density_exceeded'), $error->reason);
        }
    }

    public static function testALoadingPrefixThatStacksOntoANonStackableItemIsCaught(): void
    {
        $base = Item::create('base', Dimensions::mm(10, 10, 5), stackable: false);
        $rider = Item::create('rider', Dimensions::mm(10, 10, 5));
        $placements = [self::stackedPlacement($base, 0, 0, 0), self::stackedPlacement($rider, 0, 0, 5)];
        $container = Container::create('c', Dimensions::mm(10, 10, 20));
        try {
            LoadingDependencyGraph::verifyLoadingPrefixBusinessRules($placements, [0, 1], $container);
            self::assertTrue(false, 'expected SequenceReplayError');
        } catch (SequenceReplayError $error) {
            self::assertSame(1, $error->step);
            self::assertTrue(str_contains($error->reason, 'non_stackable_item_has_load'), $error->reason);
        }
    }

    public static function testALoadingPrefixThatViolatesAGroundContactRuleIsCaught(): void
    {
        // "single" requires resting on exactly one supporter; two half-width bases
        // side by side under one full-width rider violate it.
        $left = Item::create('left', Dimensions::mm(5, 5, 5));
        $right = Item::create('right', Dimensions::mm(5, 5, 5));
        $rider = Item::create('rider', Dimensions::mm(10, 5, 5), groundContactRule: 'single');
        $placements = [
            self::stackedPlacement($left, 0, 0, 0),
            self::stackedPlacement($right, 5, 0, 0),
            self::stackedPlacement($rider, 0, 0, 5),
        ];
        $container = Container::create('c', Dimensions::mm(10, 10, 20));
        try {
            LoadingDependencyGraph::verifyLoadingPrefixBusinessRules($placements, [0, 1, 2], $container);
            self::assertTrue(false, 'expected SequenceReplayError');
        } catch (SequenceReplayError $error) {
            self::assertSame(2, $error->step);
            self::assertTrue(str_contains($error->reason, 'ground_contact_violation'), $error->reason);
        }
    }

    public static function testALoadingPrefixTreatsEachNestedPredecessorAsOneSupporter(): void
    {
        $crate = Item::create(
            'crate', Dimensions::mm(10, 10, 10), quantity: 3,
            groundContactRule: 'single', nestingHeight: Length::mm(5),
        );
        $placements = [];
        foreach ($crate->instances() as $index => $instance) {
            $z = $index * 5 * self::MM;
            $point = new Point(0, 0, $z);
            $placements[] = new Placement(
                $instance, $point, Rotation::LWH, $instance->dimensions(),
                $point, $instance->dimensions(),
            );
        }
        $container = Container::create('c', Dimensions::mm(10, 10, 30));

        LoadingDependencyGraph::verifyLoadingPrefixBusinessRules($placements, [0, 1, 2], $container);
        self::assertTrue(true);
    }

    public static function testALoadingPrefixThatRespectsEveryBusinessRuleRaisesNothing(): void
    {
        $fragile = Item::create('fragile', Dimensions::mm(10, 10, 5), weight: '1kg', maxTopLoad: '5kg', maxStackedItems: 2, stackable: true);
        $light = Item::create('light', Dimensions::mm(10, 10, 5), weight: '1kg');
        // No maxStackDensity set: this scenario's tiny 100mm^2 footprint under even a
        // light load is already a very high kg/m^2 figure, and this test's purpose is
        // the other three rules -- stack_density_exceeded has its own test above.
        $container = Container::create('c', Dimensions::mm(10, 10, 20));
        $placements = [self::stackedPlacement($fragile, 0, 0, 0), self::stackedPlacement($light, 0, 0, 5)];
        LoadingDependencyGraph::verifyLoadingPrefixBusinessRules($placements, [0, 1], $container); // must not throw
        self::assertTrue(true);
    }

    public static function testComposedSafeLoadingApiCannotReturnAnOverloadedOrder(): void
    {
        $fragile = Item::create('fragile', Dimensions::mm(10, 10, 5), weight: '1kg', maxTopLoad: '500g');
        $heavy = Item::create('heavy', Dimensions::mm(10, 10, 5), weight: '5kg');
        $placements = [self::stackedPlacement($fragile, 0, 0, 0), self::stackedPlacement($heavy, 0, 0, 5)];
        $container = Container::create('c', Dimensions::mm(10, 10, 20));
        self::assertThrows(SequenceReplayError::class, static function () use ($placements, $container) {
            LoadingDependencyGraph::safeLoadingOrderForPlacements($placements, $container);
        });
    }

    public static function testAMalformedBusinessRuleOrderIsRejectedBeforeAnyRuleCheck(): void
    {
        $item = Item::create('a', Dimensions::mm(10, 10, 5));
        $placements = [self::stackedPlacement($item, 0, 0, 0)];
        $container = Container::create('c', Dimensions::mm(10, 10, 10));
        self::assertThrows(SequenceReplayError::class, static function () use ($placements, $container) {
            LoadingDependencyGraph::verifyLoadingPrefixBusinessRules($placements, [0, 0], $container);
        });
    }

    // ---------------------------------------------------- canonical DTOs

    public static function testSequenceStepToArrayMatchesTheCrossLanguageShape(): void
    {
        $step = new SequenceStep(1, '+x', [2 => true, 0 => true]);
        self::assertSame(
            ['index' => 1, 'direction' => '+x', 'depends_on' => [0, 2]],
            $step->toArray(),
        );
    }

    public static function testSequenceWarningMatchesTheSharedCrossLanguageShape(): void
    {
        $payload = self::sharedScenes();
        $warning = new SequenceWarning('sequence_advisory', 1, 'sequence.advisory', ['unit' => 'mm', 'clearance' => '2']);
        self::assertSame($payload['dto_contract']['sequence_warning'], $warning->toArray());
    }

    public static function testInvalidDirectionErrorToArrayMatchesTheCrossLanguageShape(): void
    {
        $error = new InvalidDirectionError('sideways');
        self::assertSame(
            ['code' => 'invalid_direction', 'direction' => 'sideways'],
            $error->toArray(),
        );
    }

    public static function testSequenceErrorToArrayMatchesTheCrossLanguageShape(): void
    {
        $error = new SequenceError([2, 0]);
        self::assertSame(
            ['code' => 'sequence_stuck', 'stuck' => [0, 2]],
            $error->toArray(),
        );
    }

    public static function testSequenceReplayErrorToArrayMatchesTheCrossLanguageShape(): void
    {
        $error = new SequenceReplayError(3, 1, 'no allowed direction is clear of the remaining placements');
        self::assertSame(
            [
                'code' => 'sequence_replay',
                'index' => 3,
                'step' => 1,
                'reason' => 'no allowed direction is clear of the remaining placements',
            ],
            $error->toArray(),
        );
    }
}
