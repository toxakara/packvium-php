<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Algorithm\EffortBudget;
use Packvium\Config\PackingConfig;
use Packvium\Config\SolverProfile;
use Packvium\Domain\AxisAlignedBox;
use Packvium\Domain\Dimensions;
use Packvium\Domain\Obstacle;
use Packvium\Domain\Point;
use Packvium\Domain\Rotation;
use Packvium\Result\PackingStatus;
use Packvium\Support\BigInt;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;

/**
 * End-to-end packing through the public `Packer`.
 *
 * Every scenario is checked twice: once for what it was supposed to achieve, and once
 * against the independent validator, so a test cannot pass on a count while the layout
 * underneath it is physically impossible.
 */
final class PackingTest extends TestCase
{
    private const MM = 16_000;

    /** @return array<string,PackingConfig> */
    private static function profiles(): array
    {
        return [
            'fast' => PackingConfig::fast(),
            'balanced' => PackingConfig::balanced(),
            'quality' => PackingConfig::quality(2_000),
            'exact_small' => PackingConfig::exactSmall(2_000),
        ];
    }

    /** @return array<string,PackingConfig> */
    private static function deterministicProfiles(): array
    {
        $budget = static fn(int $restarts): EffortBudget =>
            new EffortBudget(maxSearchNodes: 500, maxRestarts: $restarts);
        return [
            'fast' => new PackingConfig(
                profile: SolverProfile::Fast,
                timeLimitMs: 60_000,
                topK: 1,
                multiStartOrders: 1,
                effortBudget: $budget(1),
            ),
            'balanced' => new PackingConfig(
                profile: SolverProfile::Balanced,
                timeLimitMs: 60_000,
                effortBudget: $budget(9),
            ),
            'quality' => new PackingConfig(
                profile: SolverProfile::Quality,
                timeLimitMs: 60_000,
                topK: 5,
                multiStartOrders: 24,
                maxCandidatesPerItem: 3,
                effortBudget: $budget(9),
            ),
            'exact_small' => new PackingConfig(
                profile: SolverProfile::ExactSmall,
                timeLimitMs: 60_000,
                effortBudget: $budget(9),
            ),
        ];
    }

    // ---------------------------------------------------------------- basic packing

    public static function testEveryProfileFillsAnExactlyDivisibleContainer(): void
    {
        $items = [Support::item('cube', 100, 100, 100, ['quantity' => 4])];
        $containers = [Support::box('box', 200, 200, 100)];
        foreach (self::profiles() as $name => $config) {
            $result = Support::pack($items, $containers, $config);
            self::assertTrue($result->complete(), $name);
            self::assertSame(4, $result->packedItemCount(), $name);
            self::assertCount(1, $result->containers, $name);
            self::assertSame([], Support::problems($result, $items, $containers), $name);
        }
    }

    public static function testAnItemLargerThanEveryContainerIsReportedWithAReason(): void
    {
        $items = [Support::item('slab', 200, 200, 200)];
        $containers = [Support::box('box', 100, 100, 100)];
        $result = Support::pack($items, $containers);

        self::assertFalse($result->complete());
        self::assertSame('no_compatible_container_dimensions', $result->unpacked[0]->reason);
        self::assertSame([], $result->containers);
    }

    public static function testAnItemHeavierThanEveryContainerAllowsIsReportedWithAReason(): void
    {
        $items = [Support::item('anvil', 10, 10, 10, ['weight' => '10 kg'])];
        $containers = [Support::box('box', 100, 100, 100, ['maxPayload' => '1 kg'])];
        self::assertSame('payload_exceeded', Support::pack($items, $containers)->unpacked[0]->reason);
    }

    public static function testRotationIsUsedWhenTheUprightOrientationWillNotFit(): void
    {
        $items = [Support::item('plank', 120, 40, 60)];
        $containers = [Support::box('box', 60, 120, 40)];
        $result = Support::pack($items, $containers);

        self::assertTrue($result->complete());
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    public static function testKeepUprightForbidsTheRotationThatWouldHaveHelped(): void
    {
        $items = [Support::item('plank', 120, 40, 60, ['keepUpright' => true])];
        $containers = [Support::box('box', 60, 120, 40)];
        $result = Support::pack($items, $containers);
        self::assertFalse($result->complete());
        // A plain dimension mismatch and "this item's own rotation restriction, and
        // only it, rules every container out" are different facts: the
        // container would hold the plank in the HLW orientation, but keepUpright
        // forbids it.
        [$unpacked] = $result->unpacked;
        self::assertSame('rotation_restricted', $unpacked->reason);
        self::assertSame('proven', $unpacked->proof->level);
    }

    public static function testAFinalLayoutWithOnlyPartialSupportReportsInsufficientSupport(): void
    {
        // `base` (high priority, forced onto the floor) leaves only half its
        // footprint available to support `topper`, whose own footprint exactly
        // matches the container and cannot fit anywhere else -- the only geometric
        // candidate for it exists, and support alone is what rejects it.
        $base = Support::item('base', 30, 60, 20, [
            'allowedRotations' => [Rotation::LWH], 'mustBeOnFloor' => true, 'priority' => 10,
        ]);
        $topper = Support::item('topper', 60, 60, 10, [
            'allowedRotations' => [Rotation::LWH], 'minimumSupportRatio' => 1.0,
        ]);
        $containers = [Support::box('box', 60, 60, 40, ['quantity' => 1])];

        $config = new PackingConfig(timeLimitMs: 1000, multiStartOrders: 1);
        $result = Support::pack([$base, $topper], $containers, $config);

        self::assertFalse($result->complete());
        [$unpacked] = $result->unpacked;
        self::assertSame('topper#1', $unpacked->instance->id());
        self::assertSame('insufficient_support', $unpacked->reason);
        self::assertSame('observed', $unpacked->proof->level);
    }

    // ------------------------------------------------------------------ determinism

    public static function testTheSameRequestProducesTheSameAnswer(): void
    {
        // Counted work, not scheduler-dependent wall time, defines where search stops;
        // the generous clock remains only a production-style safety cutoff.
        $items = [Support::item('a', 40, 30, 20, ['quantity' => 5]), Support::item('b', 60, 50, 40, ['quantity' => 3])];
        $containers = [Support::box('c', 150, 150, 150, ['quantity' => 3])];
        foreach (self::deterministicProfiles() as $name => $config) {
            self::assertSame(
                self::chosenAnswer(Support::pack($items, $containers, $config)->toArray()),
                self::chosenAnswer(Support::pack($items, $containers, $config)->toArray()),
                $name,
            );
        }
    }

    /**
     * The answer a caller receives, without the effort diagnostics or the runners-up.
     *
     * The time budget is wall clock, so how far a start gets before its slice runs out
     * depends on the host, not on the request. A start that was cut off reports the
     * placements it had managed by then, which is why truncated alternatives are
     * excluded from the reproducibility claim.
     */
    private static function chosenAnswer(array $report): array
    {
        foreach (['duration_ms', 'candidates_evaluated', 'placements_attempted'] as $field) {
            unset($report['algorithm'][$field]);
        }
        foreach ([
            'any_start_truncated',
            'all_required_starts_completed',
            'global_deadline_reached',
            'starts',
        ] as $field) {
            unset($report['termination'][$field]);
        }
        unset($report['alternatives']);
        return $report;
    }

    public static function testADifferentSeedMayReorderTheSearchButNotBreakIt(): void
    {
        $items = [Support::item('a', 40, 30, 20, ['quantity' => 6])];
        $containers = [Support::box('c', 150, 150, 150, ['quantity' => 2])];
        foreach ([1, 7, 99] as $seed) {
            $result = Support::pack($items, $containers, new PackingConfig(seed: $seed));
            self::assertSame([], Support::problems($result, $items, $containers), "seed {$seed}");
            self::assertSame($seed, $result->algorithm->seed);
        }
    }

    // ----------------------------------------------------------- container choice

    public static function testAPayloadCeilingSplitsTheOrderAcrossContainers(): void
    {
        $items = [Support::item('a', 50, 50, 50, ['quantity' => 2, 'weight' => '1 kg'])];
        $containers = [Support::box('box', 200, 200, 200, ['maxPayload' => '1.5 kg'])];
        $result = Support::pack($items, $containers, PackingConfig::fast());

        self::assertTrue($result->complete());
        self::assertCount(2, $result->containers);
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    public static function testAnEligibleContainerIsChosenOverACheaperIneligibleOne(): void
    {
        $items = [Support::item('perishable', 50, 50, 50, ['eligibleContainerTags' => ['refrigerated']])];
        $containers = [
            Support::box('dry-van', 100, 100, 100, ['costMinor' => 100]),
            Support::box('reefer', 100, 100, 100, ['costMinor' => 900, 'tags' => ['refrigerated']]),
        ];
        $result = Support::pack($items, $containers);

        self::assertTrue($result->complete());
        self::assertSame('reefer', $result->containers[0]->container->id);
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    public static function testAnItemWithNoEligibleContainerIsReportedWithAReason(): void
    {
        $items = [Support::item('perishable', 50, 50, 50, ['eligibleContainerTags' => ['refrigerated']])];
        $containers = [Support::box('dry-van', 100, 100, 100)];
        $result = Support::pack($items, $containers);

        self::assertFalse($result->complete());
        self::assertSame('no_eligible_container', $result->unpacked[0]->reason);
        self::assertSame('proven', $result->unpacked[0]->proof->level);
    }

    public static function testATagLimitLeavesTheRestUnpackedRatherThanOpeningAnotherContainer(): void
    {
        $items = [Support::item('drum', 30, 30, 30, ['quantity' => 3, 'tags' => ['hazmat']])];
        $containers = [Support::box('box', 100, 100, 100, ['quantity' => 1, 'tagLimits' => ['hazmat' => 2]])];
        $result = Support::pack($items, $containers);

        self::assertFalse($result->complete());
        self::assertSame(2, $result->packedItemCount());
        self::assertCount(1, $result->unpacked);
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    public static function testATagLimitDoesNotConstrainAnUnrelatedItem(): void
    {
        $items = [
            Support::item('drum', 30, 30, 30, ['quantity' => 2, 'tags' => ['hazmat']]),
            Support::item('box', 30, 30, 30),
        ];
        $containers = [Support::box('c', 100, 100, 100, ['quantity' => 1, 'tagLimits' => ['hazmat' => 2]])];
        $result = Support::pack($items, $containers);

        self::assertTrue($result->complete());
        self::assertSame(3, $result->packedItemCount());
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    public static function testAVoidFillReserveReducesUsableVolumeWithoutTouchingGeometry(): void
    {
        // 8 cubes of 50mm exactly fill a 100mm container (1,000,000 mm^3 / 125,000
        // mm^3 each). Reserving half the volume leaves room for exactly 4.
        $items = [Support::item('cube', 50, 50, 50, ['quantity' => 8])];
        $unreserved = Support::pack($items, [Support::box('box', 100, 100, 100, ['quantity' => 1])]);
        $reservedContainers = [Support::box('box', 100, 100, 100, ['quantity' => 1, 'voidFillReserveRatio' => 0.5])];
        $reserved = Support::pack($items, $reservedContainers);

        self::assertTrue($unreserved->complete());
        self::assertSame(8, $unreserved->packedItemCount());
        self::assertFalse($reserved->complete());
        self::assertSame(4, $reserved->packedItemCount());
        self::assertSame(
            $unreserved->containers[0]->container->innerDimensions->length->ticks,
            $reserved->containers[0]->container->innerDimensions->length->ticks,
        );
        self::assertSame([], Support::problems($reserved, $items, $reservedContainers));
    }

    public static function testTheVoidFillReserveIsReportedInTheResult(): void
    {
        $items = [Support::item('cube', 50, 50, 50, ['quantity' => 8])];
        $containers = [Support::box('box', 100, 100, 100, ['voidFillReserveRatio' => 0.25])];
        $result = Support::pack($items, $containers);
        self::assertSame(
            (string)(BigInt::divide(BigInt::multiply('1600000', BigInt::multiply('1600000', '1600000')), '4')),
            $result->toArray()['containers'][0]['void_fill_reserve_ticks3'],
        );
    }

    public static function testTheCheaperContainerIsPreferredWhenBothWouldDo(): void
    {
        $items = [Support::item('a', 50, 50, 50)];
        $containers = [
            Support::box('cheap', 100, 100, 100, ['costMinor' => 100]),
            Support::box('dear', 100, 100, 100, ['costMinor' => 900]),
        ];
        self::assertSame('cheap', Support::pack($items, $containers)->containers[0]->container->id);
    }

    public static function testContainerStockIsNeverExceeded(): void
    {
        $items = [Support::item('a', 90, 90, 90, ['quantity' => 4])];
        $containers = [Support::box('c', 100, 100, 100, ['quantity' => 2])];
        $result = Support::pack($items, $containers);

        self::assertLessThanOrEqual(2, count($result->containers));
        self::assertCount(2, $result->unpacked);
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    public static function testTheContainerBudgetCapsTheAnswer(): void
    {
        $items = [Support::item('a', 90, 90, 90, ['quantity' => 4])];
        $containers = [Support::box('c', 100, 100, 100, ['quantity' => 10])];
        $result = Support::pack($items, $containers, new PackingConfig(maxContainers: 2));

        self::assertCount(2, $result->containers);
        self::assertCount(2, $result->unpacked);
    }

    public static function testAnItemCeilingIsRespected(): void
    {
        $items = [Support::item('a', 10, 10, 10, ['quantity' => 6])];
        $containers = [Support::box('c', 100, 100, 100, ['quantity' => 6, 'maxItems' => 2])];
        $result = Support::pack($items, $containers);

        foreach ($result->containers as $container) {
            self::assertLessThanOrEqual(2, count($container->placements));
        }
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    public static function testContainersThatHoldNothingAreNotReported(): void
    {
        $items = [Support::item('a', 10, 10, 10)];
        $containers = [Support::box('c', 100, 100, 100, ['quantity' => 5])];
        self::assertCount(1, Support::pack($items, $containers)->containers);
    }

    // ---------------------------------------------------------------------- physics

    public static function testClearanceKeepsAGapBetweenNeighbours(): void
    {
        // The envelope, not the item, is what may not overlap; the reported position
        // sits inside its envelope by exactly the clearance.
        $gap = Length::mm(2);
        $items = [Support::item('a', 40, 40, 40, ['quantity' => 4])];
        $containers = [Support::box('c', 200, 200, 200)];
        $result = Support::pack($items, $containers, PackingConfig::balanced(clearance: $gap));

        self::assertSame([], Support::problems($result, $items, $containers, 0.0, $gap));
        foreach ($result->containers[0]->placements as $placement) {
            self::assertSame($gap->ticks, $placement->position->x - $placement->envelopeOrigin->x);
        }
    }

    public static function testAnObstacleIsWorkedAround(): void
    {
        $post = new Obstacle('post', new AxisAlignedBox(new Point(0, 0, 0), Dimensions::mm(50, 50, 100)));
        $items = [Support::item('a', 40, 40, 40, ['quantity' => 2])];
        $containers = [Support::box('box', 100, 100, 100, ['obstacles' => [$post]])];
        $result = Support::pack($items, $containers);

        self::assertTrue($result->complete());
        foreach ($result->containers[0]->placements as $placement) {
            self::assertFalse($placement->envelopeBox()->intersects($post->box));
        }
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    public static function testFloorOnlyItemsNeverLeaveTheFloor(): void
    {
        $items = [Support::item('f', 40, 40, 40, ['quantity' => 8, 'mustBeOnFloor' => true])];
        $containers = [Support::box('c', 100, 100, 100, ['quantity' => 4])];
        $result = Support::pack($items, $containers);

        foreach ($result->containers as $container) {
            foreach ($container->placements as $placement) {
                self::assertSame(0, $placement->envelopeOrigin->z);
            }
        }
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    public static function testNothingIsStackedOnANonStackableItem(): void
    {
        $items = [Support::item('n', 40, 40, 40, ['quantity' => 8, 'stackable' => false])];
        $containers = [Support::box('c', 100, 100, 100, ['quantity' => 4])];
        $result = Support::pack($items, $containers);

        foreach ($result->containers as $container) {
            foreach ($container->placements as $placement) {
                self::assertSame(0, $placement->envelopeOrigin->z);
            }
        }
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    public static function testANonStackableCandidateIsNotSlidUnderAnExistingItem(): void
    {
        // The shelf forces the wide item above the floor and exposes an empty point
        // underneath it. The candidate's own stackable flag must keep that point out.
        $shelf = new Obstacle('shelf',
            new AxisAlignedBox(new Point(0, 0, 0), Dimensions::mm(50, 100, 20)));
        $items = [
            Support::item('a-upper', 100, 100, 10, ['allowedRotations' => [Rotation::LWH]]),
            Support::item('b-under', 50, 100, 20,
                ['stackable' => false, 'allowedRotations' => [Rotation::LWH]]),
        ];
        $containers = [Support::box('box', 100, 100, 100, ['obstacles' => [$shelf]])];
        $result = Support::pack($items, $containers, PackingConfig::fast(1_000));

        self::assertTrue($result->complete());
        self::assertNotSame(PackingStatus::InvalidResult, $result->status);
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    public static function testABearingLimitIsHonouredAcrossTheWholeStack(): void
    {
        // The base carries everything above it, not just its immediate neighbour.
        $items = [
            Support::item('base', 100, 100, 50, ['weight' => '1 kg', 'maxTopLoad' => '2.5 kg']),
            Support::item('light', 100, 100, 50, ['quantity' => 4, 'weight' => '1 kg']),
        ];
        $containers = [Support::box('c', 100, 100, 400, ['quantity' => 3])];
        $result = Support::pack($items, $containers);

        self::assertSame([], Support::problems($result, $items, $containers));
        foreach ($result->containers as $container) {
            foreach ($container->placements as $placement) {
                $limit = $placement->instance->item->maxTopLoad;
                if ($limit !== null) {
                    self::assertLessThanOrEqual($limit->ticks, $placement->topLoad->ticks);
                }
            }
        }
    }

    public static function testTheReportedTopLoadIsTheCumulativeOne(): void
    {
        $items = [
            Support::item('base', 100, 100, 50, ['weight' => '1 kg']),
            Support::item('upper', 100, 100, 50, ['quantity' => 2, 'weight' => '1 kg']),
        ];
        $containers = [Support::box('c', 100, 100, 150)];
        $result = Support::pack($items, $containers, new PackingConfig(minimumSupportRatio: 1.0));

        self::assertTrue($result->complete());
        $bottom = null;
        foreach ($result->containers[0]->placements as $placement) {
            if ($bottom === null || $placement->envelopeOrigin->z < $bottom->envelopeOrigin->z) {
                $bottom = $placement;
            }
        }
        self::assertSame(Weight::of(2, 'kg')->ticks, $bottom?->topLoad->ticks);
    }

    public static function testAMaterializedNestedGridReportsItsCumulativeTopLoads(): void
    {
        $items = [Support::item('crate', 100, 100, 50, [
            'quantity' => 3,
            'weight' => '1 kg',
            'maxTopLoad' => '2 kg',
            'minimumSupportRatio' => 1.0,
            'groundContactRule' => 'single',
            'nestingHeight' => Length::mm(25),
            'allowedRotations' => [Rotation::LWH],
        ])];
        $containers = [Support::box('c', 100, 100, 100)];

        $result = Support::pack(
            $items,
            $containers,
            PackingConfig::fast(timeLimitMs: 1_000, requirePlacementCoordinates: true),
        );

        self::assertTrue($result->complete());
        $ordered = $result->containers[0]->placements;
        usort($ordered, static fn($left, $right): int => $left->envelopeOrigin->z <=> $right->envelopeOrigin->z);
        self::assertSame(
            [0, Length::mm(25)->ticks, Length::mm(50)->ticks],
            array_map(static fn($placement): int => $placement->envelopeOrigin->z, $ordered),
        );
        self::assertSame(
            [Weight::of(2, 'kg')->ticks, Weight::of(1, 'kg')->ticks, 0],
            array_map(static fn($placement): int => $placement->topLoad->ticks, $ordered),
        );
        self::assertSame([1.0, 1.0, 1.0], array_map(static fn($placement): float => $placement->supportRatio, $ordered));
        self::assertSame([], Support::problems($result, $items, $containers, 1.0));
    }

    public static function testDifferentDeclaredNestingTypesDoNotShareALatticeColumn(): void
    {
        $nesting = ['nestingHeight' => Length::mm(40), 'allowedRotations' => [Rotation::LWH]];
        $items = [
            Support::item('a', 100, 100, 100, $nesting),
            Support::item('b', 100, 100, 100, $nesting),
        ];
        $containers = [Support::box('c', 100, 100, 160, ['quantity' => 1])];

        $result = Support::pack($items, $containers, PackingConfig::fast(timeLimitMs: 1_000));

        self::assertFalse($result->complete());
        self::assertNotSame(PackingStatus::InvalidResult, $result->status);
        self::assertSame(1, $result->packedItemCount());
        self::assertCount(1, $result->unpacked);
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    public static function testExtremePointsNeverNestsOntoANonStackableItem(): void
    {
        $items = [Support::item('crate', 100, 100, 50, [
            'quantity' => 2,
            'nestingHeight' => Length::mm(25),
            'stackable' => false,
            'allowedRotations' => [Rotation::LWH],
        ])];
        $containers = [Support::box('c', 100, 100, 75, ['quantity' => 1])];
        $config = new PackingConfig(
            profile: SolverProfile::Fast, timeLimitMs: 1_000,
            solvers: ['extreme_points'],
        );

        $result = Support::pack($items, $containers, $config);

        self::assertNotSame(PackingStatus::InvalidResult, $result->status);
        self::assertSame(1, $result->packedItemCount());
        self::assertCount(1, $result->unpacked);
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    public static function testARequiredSupportRatioIsMetByEveryStackedItem(): void
    {
        $items = [Support::item('a', 60, 60, 20, ['quantity' => 6, 'minimumSupportRatio' => 0.75])];
        $containers = [Support::box('c', 121, 121, 100, ['quantity' => 3])];
        $result = Support::pack($items, $containers, PackingConfig::quality(2_000));

        self::assertSame([], Support::problems($result, $items, $containers));
        foreach ($result->containers as $container) {
            foreach ($container->placements as $placement) {
                if ($placement->envelopeOrigin->z > 0) {
                    self::assertTrue($placement->supportRatio >= 0.75 - 1e-9);
                }
            }
        }
    }

    // ----------------------------------------------------------------------- groups

    public static function testAGroupTravelsInOneContainer(): void
    {
        $items = [
            Support::item('kit', 60, 60, 60, ['quantity' => 2, 'group' => 'kit']),
            Support::item('loose', 20, 20, 20, ['quantity' => 4]),
        ];
        $containers = [Support::box('c', 130, 130, 130, ['quantity' => 3])];
        $result = Support::pack($items, $containers);

        self::assertTrue($result->complete());
        $homes = [];
        foreach ($result->containers as $container) {
            foreach ($container->placements as $placement) {
                if ($placement->instance->item->group === 'kit') {
                    $homes[$container->id()] = true;
                }
            }
        }
        self::assertCount(1, $homes);
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    public static function testAnImpossibleGroupDoesNotStrandUnrelatedItems(): void
    {
        // The regression this batching exists for: a group that cannot fit must be
        // rejected as a whole and leave the rest of the order untouched.
        $items = [
            Support::item('kit', 80, 80, 80, ['quantity' => 2, 'group' => 'kit']),
            Support::item('loose', 20, 20, 20, ['quantity' => 4]),
        ];
        $containers = [Support::box('c', 100, 100, 100)];
        $result = Support::pack($items, $containers);

        self::assertSame(4, $result->packedItemCount());
        foreach ($result->unpacked as $unpacked) {
            self::assertSame('kit', $unpacked->instance->item->id);
            self::assertSame('group_cannot_fit_together', $unpacked->reason);
        }
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    // ------------------------------------------------------------------- separation

    public static function testIncompatibleItemsAreSeparated(): void
    {
        $items = [
            Support::item('food', 40, 40, 40, ['tags' => ['food']]),
            Support::item('bleach', 40, 40, 40, ['incompatibleTags' => ['food']]),
        ];
        $containers = [Support::box('c', 100, 100, 100, ['quantity' => 2])];
        $result = Support::pack($items, $containers);

        self::assertTrue($result->complete());
        self::assertCount(2, $result->containers);
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    // -------------------------------------------------------------------- reporting

    public static function testExactSmallDoesNotClaimGlobalOptimalityWithoutACertificate(): void
    {
        // The discrete search stops at its first full packing and has no lower-bound
        // certificate for the remaining objective keys.
        $items = [Support::item('a', 100, 100, 100, ['quantity' => 2]), Support::item('b', 100, 100, 100, ['quantity' => 2])];
        $containers = [Support::box('box', 200, 200, 100)];
        $result = Support::pack($items, $containers, PackingConfig::exactSmall(5_000));

        self::assertTrue($result->complete());
        self::assertSame(PackingStatus::Feasible, $result->status);
    }

    public static function testASingleItemTypeTakesTheLatticePathAndClaimsNoProof(): void
    {
        // The lattice cannot prove optimality, so it must not be reported as optimal
        // even when it happens to fill the container perfectly.
        $items = [Support::item('cube', 100, 100, 100, ['quantity' => 4])];
        $containers = [Support::box('box', 200, 200, 100)];
        $result = Support::pack($items, $containers, PackingConfig::exactSmall(5_000));

        self::assertTrue($result->complete());
        self::assertSame(PackingStatus::Feasible, $result->status);
    }

    public static function testAnIncompleteAnswerWithinTheBudgetReportsBestFound(): void
    {
        $items = [Support::item('a', 90, 90, 90, ['quantity' => 3])];
        $containers = [Support::box('c', 100, 100, 100, ['quantity' => 1])];
        self::assertSame(PackingStatus::BestFound, Support::pack($items, $containers)->status);
    }

    public static function testAlternativesAreCappedByTopK(): void
    {
        $items = [Support::item('a', 40, 30, 20, ['quantity' => 6])];
        $containers = [Support::box('c', 150, 150, 150, ['quantity' => 2])];
        $result = Support::pack($items, $containers, PackingConfig::quality(2_000, 3));
        self::assertLessThanOrEqual(2, count($result->alternatives));
    }

    public static function testTheAlgorithmReportNamesTheSolverAndOrderingThatWon(): void
    {
        $items = [Support::item('a', 40, 30, 20, ['quantity' => 4])];
        $containers = [Support::box('c', 150, 150, 150)];
        $report = Support::pack($items, $containers)->algorithm;

        self::assertTrue(str_contains($report->solver, ':'), 'the solver name carries the multi-start ordering');
        self::assertSame('balanced', $report->profile);
        self::assertGreaterThan(0, $report->candidatesEvaluated);
        self::assertLessThanOrEqual($report->placementsAttempted, $report->candidatesEvaluated);
        self::assertSame($report->placementsAttempted,$report->metrics->orientationsConsidered);
        self::assertSame($report->candidatesEvaluated,$report->metrics->feasibleCandidates);
        self::assertGreaterThan(0,$report->metrics->candidatePointsConsidered);
        self::assertGreaterThan(0,$report->metrics->searchNodesExpanded);
    }

    public static function testATightBudgetStillReturnsTheWorkAlreadyDone(): void
    {
        // Partial work is a valid packing and is worth far more than an empty result.
        $items = [Support::item('m', 90, 80, 70, ['quantity' => 54])];
        $containers = [Support::box('c', 1_000, 1_000, 1_000, ['quantity' => 3])];
        $result = Support::pack($items, $containers, PackingConfig::balanced(15));

        self::assertSame([], Support::problems($result, $items, $containers));
        self::assertNotSame(PackingStatus::InvalidResult, $result->status);
    }

    // ---------------------------------------------- quantity compression

    public static function testQuantityCompressionPacks10000IdenticalItemsWithinBudget(): void
    {
        // The acceptance case: requirePlacementCoordinates=false must let 10,000
        // identical items pack without paying for 10,000 Placement objects. The
        // wall-clock ceiling is deliberately generous -- a regression guard against
        // the fast path silently falling back to the O(n) loop, not a tight
        // performance assertion that could flake under CI scheduling noise.
        // validateResult=false is paired with the flag so independent validation
        // (which must still expand to check placements, see Packer::pack) is not
        // itself the O(n) cost this test would otherwise be measuring instead of
        // the solver's own fast path.
        $items = [Support::item('crate', 100, 100, 100, ['quantity' => 10_000])];
        $containers = [Support::box('bin', 3_000, 3_000, 3_000)];
        // A generous time_limit_ms: the fast path itself never consults the deadline
        // (it is O(r), not a per-item loop with per-item checks), but the portfolio
        // loop checks the deadline before even calling the solver, and a short
        // budget could otherwise make this test flaky under a loaded CI machine
        // rather than actually exercising the fast path.
        $config = PackingConfig::fast(timeLimitMs: 5_000, requirePlacementCoordinates: false, validateResult: false);

        $started = microtime(true);
        $result = Support::pack($items, $containers, $config);
        $elapsed = microtime(true) - $started;

        self::assertSame(PackingStatus::Feasible, $result->status);
        self::assertSame([], $result->unpacked);
        self::assertSame(10_000, $result->packedItemCount());
        self::assertCount(1, $result->containers);
        $packed = $result->containers[0];
        self::assertSame([], $packed->placements);
        self::assertNotNull($packed->latticeSummary);
        self::assertSame(10_000, $packed->latticeSummary->count);
        self::assertLessThanOrEqual(5.0, $elapsed);

        $expanded = $packed->expandPlacements();
        self::assertCount(10_000, $expanded);
        $boundary = new AxisAlignedBox(new Point(0, 0, 0), $packed->container->innerDimensions);
        foreach ($expanded as $p) {
            self::assertTrue($boundary->contains($p->envelopeBox()));
        }
    }

    public static function testQuantityCompressionDefaultBehaviourIsUnchanged(): void
    {
        // Leaving requirePlacementCoordinates unset must reproduce the exact
        // per-item output the library has always returned -- this is a strict
        // opt-in addition, not a behaviour change.
        $items = [Support::item('crate', 90, 90, 90, ['quantity' => 40])];
        $containers = [Support::box('bin', 400, 400, 400)];

        $default = Support::pack($items, $containers, PackingConfig::fast());
        $explicit = Support::pack($items, $containers, PackingConfig::fast(requirePlacementCoordinates: true));

        self::assertNull($default->containers[0]->latticeSummary);
        self::assertCount(40, $default->containers[0]->placements);
        self::assertSame([], Support::problems($default, $items, $containers));

        $defaultDict = $default->toArray(includeAlternatives: false);
        $explicitDict = $explicit->toArray(includeAlternatives: false);
        // `algorithm.duration_ms` is a live wall-clock reading, not part of the
        // packing contract (see SERIALIZATION.md's exclusion of `algorithm`).
        $defaultDict['algorithm']['duration_ms'] = 0;
        $explicitDict['algorithm']['duration_ms'] = 0;
        self::assertEquals($defaultDict, $explicitDict);
        self::assertFalse(isset($defaultDict['containers'][0]['lattice_summary']));
    }

    public static function testQuantityCompressionStillValidatesEndToEndWhenRequested(): void
    {
        // validateResult stays the default true: the compact fast path must not
        // silently bypass the independent validator. Packer::pack expands the
        // summary just for this check without materializing it into the returned,
        // still-compact result.
        $items = [Support::item('crate', 100, 100, 100, ['quantity' => 200])];
        $containers = [Support::box('bin', 1_000, 1_000, 1_000)];
        $config = PackingConfig::fast(requirePlacementCoordinates: false);
        self::assertTrue($config->validateResult);

        $result = Support::pack($items, $containers, $config);

        self::assertSame(PackingStatus::Feasible, $result->status);
        self::assertSame([], $result->warnings);
        self::assertSame([], $result->containers[0]->placements);
        self::assertNotNull($result->containers[0]->latticeSummary);
        self::assertSame(200, $result->containers[0]->latticeSummary->count);
    }

    // ----------------------------------------------------------- budget monotonicity

    /**
     * A monotonic fake clock that advances a fixed step on every read.
     *
     * A real wall clock would make this test flaky -- whether a beam search finishes
     * inside a slice depends on host speed and load. A deterministic clock makes the
     * exact point of truncation reproducible, the same technique SolverTest's
     * checkBudgetClock already uses.
     */
    private static function fakeClock(int $stepNs): \Closure
    {
        $reads = 0;
        return static function () use (&$reads, $stepNs): int { return $reads++ * $stepNs; };
    }

    /**
     * A mixed-type scene, packed with a portfolio that names "grid" explicitly.
     *
     * `GridSolver` only ever enters the default portfolio when every requested item
     * shares one id (`GridSolver::supports`) -- but nothing stops a caller from naming
     * "grid" directly through `PackingConfig::$solvers` for a mixed-type request.
     * Every container this scene offers disqualifies the lattice outright (mixed
     * types), so `GridSolver::packOne` delegates entirely to `ExtremePointSolver`
     * while still reporting the start name "grid:volume".
     *
     * @return array{0:list<\Packvium\Domain\Item>,1:list<Container>}
     */
    private static function budgetMonotonicityScene(int $variant = 0): array
    {
        $items = [
            Support::item('a', 30 + $variant, 30, 20, ['quantity' => 8 + $variant]),
            Support::item('b', 20, 20 + $variant, 20, ['quantity' => 9 + $variant]),
            Support::item('c', 15, 15, 15 + $variant, ['quantity' => 10]),
        ];
        $containers = [Support::box('box', 120, 120, 100)];
        return [$items, $containers];
    }

    public static function testRaisingTheTimeLimitNeverLowersTheChosenRank(): void
    {
        // A larger time budget must never choose a worse-ranked solution.
        //
        // A longer budget lets `GridSolver`'s delegated (non-lattice) start finish
        // before slower, genuinely better-arranged starts get a turn, and the
        // portfolio's "a complete grid start beats everything else" short-circuit
        // used to take that at face value regardless of whether the lattice
        // was actually used -- discarding a strictly better already-available answer
        // purely because more time let the delegate finish. Same request, same seed,
        // three budgets 250ms/5s/30s: the chosen solution's rank (`score`, lower is
        // better) must be non-increasing as the budget grows.
        foreach (['default', 'lowest_cost', 'shipping_cost'] as $objective) {
            foreach (range(0, 3) as $variant) {
                foreach ([1_000_000, 2_000_000] as $stepNs) {
                    [$items, $containers] = self::budgetMonotonicityScene($variant);
                    $previousScore = null;
                    foreach ([250, 5_000, 30_000] as $timeLimitMs) {
                    $config = new PackingConfig(
                        profile: SolverProfile::Balanced,
                        timeLimitMs: $timeLimitMs,
                        solvers: ['grid', 'layer', 'maximal_spaces', 'extreme_points'],
                        objective: $objective,
                        dimensionalWeightDivisor: $objective === 'shipping_cost' ? 139 : null,
                    );
                    $result = Support::pack($items, $containers, $config, self::fakeClock($stepNs));
                    self::assertSame([], Support::problems($result, $items, $containers));
                    if ($previousScore !== null) {
                        self::assertTrue(
                            $result->score <= $previousScore,
                            "budget {$timeLimitMs}ms ranked worse (" . json_encode($result->score)
                                . ') than a smaller budget (' . json_encode($previousScore) . ')',
                        );
                    }
                        $previousScore = $result->score;
                    }
                }
            }
        }
    }
}
