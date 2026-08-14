<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Domain\Axle;
use Packvium\Domain\AxisAlignedBox;
use Packvium\Domain\Container;
use Packvium\Domain\Dimensions;
use Packvium\Domain\Item;
use Packvium\Domain\ItemInstance;
use Packvium\Domain\Nesting;
use Packvium\Domain\Obstacle;
use Packvium\Domain\PackedContainer;
use Packvium\Domain\PackingRequest;
use Packvium\Domain\Placement;
use Packvium\Domain\Point;
use Packvium\Domain\Rotation;
use Packvium\Domain\UnpackedItem;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;
use Packvium\Validation\IndependentSolutionValidator;
use ReflectionMethod;

/**
 * The independent validator, tested by handing it deliberately broken solutions.
 *
 * It shares no state with the solvers and re-derives every guarantee from the
 * placements alone, which is only worth anything if it actually catches a fault. Each
 * case injects one specific corruption and asserts the matching code comes back. The
 * codes are part of the cross-language contract — see docs/VALIDATION-CONTRACT.md.
 */
final class ValidationTest extends TestCase
{
    private const MM = 16_000;

    /** @param array<string,mixed> $options */
    private static function item(string $id, int $l = 100, int $w = 100, int $h = 100, array $options = []): Item
    {
        return Item::create($id, Dimensions::mm($l, $w, $h), ...$options);
    }

    private static function place(ItemInstance $instance, int $x = 0, int $y = 0, int $z = 0,
                                  Rotation $rotation = Rotation::LWH, ?Dimensions $dimensions = null,
                                  int $clearance = 0): Placement
    {
        $dims = $dimensions ?? $instance->item->dimensions->rotated($rotation);
        $envelope = $clearance !== 0 ? $dims->expand(new Length($clearance)) : $dims;
        return new Placement($instance, new Point($x + $clearance, $y + $clearance, $z + $clearance),
            $rotation, $dims, new Point($x, $y, $z), $envelope);
    }

    /** @param array<string,mixed> $options */
    private static function shelf(int $l = 200, int $w = 100, int $h = 100, array $options = []): Container
    {
        return Container::create('shelf', Dimensions::mm($l, $w, $h), ...$options);
    }

    /**
     * Exercises `IndependentSolutionValidator::collisionPairs` directly, bypassing
     * the private visibility that's otherwise appropriate for an internal sweep --
     * the algorithm's own pairing behaviour is what these tests check, not just
     * whether `validate()` eventually reports a `collision` code.
     *
     * @param list<Placement> $placements @return list<array{0:int,1:int}>
     */
    private static function collisionPairs(array $placements): array
    {
        $method = new ReflectionMethod(IndependentSolutionValidator::class, 'collisionPairs');
        $method->setAccessible(true);
        return $method->invoke(null, $placements);
    }

    /**
     * Independent, deliberately naive all-pairs check sharing no code with
     * `collisionPairs`'s z-bucketed sweep -- silently dropping or inventing a pair is
     * exactly the risk that sweep carries and a shared implementation could not catch.
     *
     * @param list<Placement> $placements @return list<array{0:int,1:int}>
     */
    private static function bruteForceCollisionPairs(array $placements): array
    {
        $pairs = [];
        $count = count($placements);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($placements[$i]->envelopeBox()->intersects($placements[$j]->envelopeBox())
                    && !Nesting::isValidNesting($placements[$i], $placements[$j])) {
                    $pairs[] = [$i, $j];
                }
            }
        }
        return $pairs;
    }

    // ------------------------------------------------------------------ clean input

    public static function testASoundSolutionRaisesNothing(): void
    {
        $cubes = self::item('cube', options: ['quantity' => 2]);
        $box = self::shelf();
        [$first, $second] = $cubes->instances();
        $packed = new PackedContainer($box, 1, [self::place($first), self::place($second, 100 * self::MM)]);
        self::assertSame([], Support::issues([$cubes], [$box], [$packed]));
    }

    public static function testAnEmptySolutionRaisesNothing(): void
    {
        self::assertSame([], Support::issues([self::item('a')], [self::shelf()], []));
    }

    // ---------------------------------------------------------------------- geometry

    public static function testOverlappingPlacementsAreCaught(): void
    {
        $cubes = self::item('cube', options: ['quantity' => 2]);
        $box = self::shelf();
        [$first, $second] = $cubes->instances();
        $codes = Support::issues([$cubes], [$box],
            [new PackedContainer($box, 1, [self::place($first), self::place($second, 50 * self::MM)])]);
        self::assertContains('collision', $codes);
    }

    public static function testANestedPairOfIdenticalItemsIsNotACollision(): void
    {
        $crate = self::item('crate', 100, 100, 100, ['quantity' => 2, 'nestingHeight' => Length::mm(40)]);
        $box = self::shelf(100, 100, 300);
        [$lower, $upper] = $crate->instances();
        $packed = new PackedContainer($box, 1, [self::place($lower), self::place($upper, z: 60 * self::MM)]);
        self::assertSame([], Support::issues([$crate], [$box], [$packed]));
    }

    public static function testNestedLayersAreFullSupportToTheIndependentValidator(): void
    {
        $crate = self::item('crate', 100, 100, 100, [
            'quantity' => 3,
            'nestingHeight' => Length::mm(40),
            'minimumSupportRatio' => 1.0,
            'groundContactRule' => 'single',
        ]);
        $box = self::shelf(100, 100, 300);
        [$bottom, $middle, $top] = $crate->instances();
        $packed = new PackedContainer($box, 1, [
            self::place($bottom), self::place($middle, z: 60 * self::MM),
            self::place($top, z: 120 * self::MM),
        ]);

        self::assertSame([], Support::issues([$crate], [$box], [$packed], 1.0));
    }

    public static function testIndependentValidatorRejectsNestingOnANonStackableItem(): void
    {
        $crate = self::item('crate', 100, 100, 50, [
            'quantity' => 2,
            'nestingHeight' => Length::mm(25),
            'stackable' => false,
        ]);
        $box = self::shelf(100, 100, 100);
        [$lower, $upper] = $crate->instances();
        $packed = new PackedContainer($box, 1, [
            self::place($lower), self::place($upper, z: 25 * self::MM),
        ]);

        self::assertContains('non_stackable', Support::issues([$crate], [$box], [$packed]));
    }

    public static function testAnOverlapDeeperThanTheDeclaredNestingIsStillACollision(): void
    {
        $crate = self::item('crate', 100, 100, 100, ['quantity' => 2, 'nestingHeight' => Length::mm(40)]);
        $box = self::shelf(100, 100, 300);
        [$lower, $upper] = $crate->instances();
        // 50mm overlap, not the declared 40mm.
        $packed = new PackedContainer($box, 1, [self::place($lower), self::place($upper, z: 50 * self::MM)]);
        self::assertContains('collision', Support::issues([$crate], [$box], [$packed]));
    }

    public static function testTwoDifferentItemTypesNeverGetANestingExemption(): void
    {
        $a = self::item('a', 100, 100, 100, ['nestingHeight' => Length::mm(40)]);
        $b = self::item('b', 100, 100, 100, ['nestingHeight' => Length::mm(40)]);
        $box = self::shelf(100, 100, 300);
        [$aInstance] = $a->instances();
        [$bInstance] = $b->instances();
        $packed = new PackedContainer($box, 1, [self::place($aInstance), self::place($bInstance, z: 60 * self::MM)]);
        self::assertContains('collision', Support::issues([$a, $b], [$box], [$packed]));
    }

    public static function testAnOffsetFootprintCannotClaimANestingExemption(): void
    {
        $crate = self::item('crate', 100, 100, 100, ['quantity' => 2, 'nestingHeight' => Length::mm(40)]);
        $box = self::shelf(200, 100, 300);
        [$lower, $upper] = $crate->instances();
        $packed = new PackedContainer($box, 1, [self::place($lower), self::place($upper, x: 50 * self::MM, z: 60 * self::MM)]);
        self::assertContains('collision', Support::issues([$crate], [$box], [$packed]));
    }

    public static function testCollisionPairsAgreesWithBruteForceOnRandomScenes(): void
    {
        // Random placements with varied z-heights across many seeds, so the
        // z-bucketing's cell size (the max envelope-box height in the scene) varies
        // from run to run.
        for ($seed = 0; $seed < 10; $seed++) {
            mt_srand(2000 + $seed);
            $cube = self::item('cube', options: ['quantity' => 15]);
            $placements = [];
            foreach ($cube->instances() as $instance) {
                $length = mt_rand(1, 12) * 5;
                $width = mt_rand(1, 12) * 5;
                $height = mt_rand(1, 12) * 5;
                $x = mt_rand(0, 40) * 5;
                $y = mt_rand(0, 40) * 5;
                $z = mt_rand(0, 40) * 5;
                $dims = new Dimensions(new Length($length), new Length($width), new Length($height));
                $placements[] = self::place($instance, $x, $y, $z, dimensions: $dims);
            }
            self::assertSame(
                self::bruteForceCollisionPairs($placements),
                self::collisionPairs($placements),
                "seed {$seed}",
            );
        }
    }

    public static function testCollisionPairsAgreesWithBruteForceOnADenseMultiLayerLattice(): void
    {
        // `collisionPairs`'s x-sweep only pruned its active set by x-overlap, so a
        // lattice with many z-levels sharing similar x ranges kept that active set
        // close to O(n) -- this rebuilds that shape directly: several
        // z-levels, each a dense non-overlapping grid sharing the same x/y footprint,
        // plus a handful of random extra placements that can collide with the grid or
        // each other.
        $cube = self::item('cube', 10, 10, 10, ['quantity' => 700]);
        $instances = $cube->instances();
        $next = 0;
        $placements = [];
        for ($level = 0; $level < 6; $level++) {
            $z = $level * 10 * self::MM;
            for ($gx = 0; $gx < 10; $gx++) {
                for ($gy = 0; $gy < 10; $gy++) {
                    $placements[] = self::place($instances[$next++], $gx * 10 * self::MM, $gy * 10 * self::MM, $z);
                }
            }
        }
        mt_srand(42);
        for ($i = 0; $i < 40; $i++) {
            $x = mt_rand(0, 19) * 5 * self::MM;
            $y = mt_rand(0, 19) * 5 * self::MM;
            $z = mt_rand(0, 19) * 5 * self::MM;
            $placements[] = self::place($instances[$next++], $x, $y, $z);
        }
        self::assertSame(self::bruteForceCollisionPairs($placements), self::collisionPairs($placements));
    }

    public static function testAPlacementReachingPastAWallIsCaught(): void
    {
        $cube = self::item('cube');
        $box = self::shelf();
        [$instance] = $cube->instances();
        $codes = Support::issues([$cube], [$box],
            [new PackedContainer($box, 1, [self::place($instance, 150 * self::MM)])]);
        self::assertContains('outside_container', $codes);
    }

    public static function testAPlacementInsideAnObstacleIsCaught(): void
    {
        $post = new Obstacle('post', new AxisAlignedBox(new Point(0, 0, 0), Dimensions::mm(50, 50, 100)));
        $box = self::shelf(options: ['obstacles' => [$post]]);
        $cube = self::item('cube', 40, 40, 40);
        [$instance] = $cube->instances();
        $codes = Support::issues([$cube], [$box], [new PackedContainer($box, 1, [self::place($instance)])]);
        self::assertContains('obstacle_collision', $codes);
    }

    public static function testAPlacementInsideTheSecondBoxOfAUnionObstacleIsCaught(): void
    {
        // The union's second box must be checked, not only its first.
        $near = new AxisAlignedBox(new Point(0, 0, 0), Dimensions::mm(20, 100, 100));
        $far = new AxisAlignedBox(new Point(80 * self::MM, 0, 0), Dimensions::mm(20, 100, 100));
        $arch = new Obstacle('arch', $near, [$far]);
        $box = self::shelf(100, 100, 100, ['obstacles' => [$arch]]);
        $cube = self::item('cube', 20, 20, 20);
        [$instance] = $cube->instances();
        $codes = Support::issues([$cube], [$box], [new PackedContainer($box, 1, [self::place($instance, 85 * self::MM)])]);
        self::assertContains('obstacle_collision', $codes);
    }

    // -------------------------------------------------------------------- bookkeeping

    public static function testTheSameInstanceReportedTwiceIsCaught(): void
    {
        $cube = self::item('cube');
        $box = self::shelf();
        [$instance] = $cube->instances();
        $codes = Support::issues([$cube], [$box],
            [new PackedContainer($box, 1, [self::place($instance), self::place($instance, 100 * self::MM)])]);
        self::assertContains('duplicate_item', $codes);
    }

    public static function testAnItemThatWasNeverRequestedIsCaught(): void
    {
        $requested = self::item('requested');
        $smuggled = self::item('smuggled');
        $box = self::shelf();
        [$instance] = $smuggled->instances();
        $codes = Support::issues([$requested], [$box], [new PackedContainer($box, 1, [self::place($instance)])]);
        self::assertContains('unknown_item', $codes);
    }

    public static function testAFullSolutionThatLosesAnItemIsCaught(): void
    {
        $cube = self::item('cube');
        self::assertSame(['missing_item'], Support::issues([$cube], [self::shelf()], [], unpacked: []));
    }

    public static function testAnInstanceCannotBeBothPackedAndUnpacked(): void
    {
        $cube = self::item('cube');
        $box = self::shelf();
        [$instance] = $cube->instances();
        $packed = new PackedContainer($box, 1, [self::place($instance)]);
        $codes = Support::issues([$cube], [$box], [$packed], unpacked: [
            new UnpackedItem($instance, 'no_feasible_placement'),
        ]);
        self::assertContains('duplicate_item', $codes);
    }

    public static function testEveryUnpackedItemRequiresAReason(): void
    {
        $cube = self::item('cube');
        [$instance] = $cube->instances();
        $codes = Support::issues([$cube], [self::shelf()], [], unpacked: [new UnpackedItem($instance, '')]);
        self::assertContains('missing_reason', $codes);
    }

    public static function testAGroupCannotBePartlyPackedAndPartlyUnpacked(): void
    {
        $kit = self::item('kit', options: ['quantity' => 2, 'group' => 'kit']);
        $box = self::shelf();
        [$first, $second] = $kit->instances();
        $packed = new PackedContainer($box, 1, [self::place($first)]);
        $codes = Support::issues([$kit], [$box], [$packed], unpacked: [
            new UnpackedItem($second, 'group_cannot_fit_together'),
        ]);
        self::assertContains('group_partial', $codes);
    }

    public static function testUsingMoreContainersThanExistIsCaught(): void
    {
        $cubes = self::item('cube', options: ['quantity' => 2]);
        $box = self::shelf(options: ['quantity' => 1]);
        [$first, $second] = $cubes->instances();
        $codes = Support::issues([$cubes], [$box], [
            new PackedContainer($box, 1, [self::place($first)]),
            new PackedContainer($box, 2, [self::place($second)]),
        ]);
        self::assertContains('container_inventory_exceeded', $codes);
    }

    public static function testExceedingTheItemCeilingIsCaught(): void
    {
        $cubes = self::item('cube', options: ['quantity' => 2]);
        $box = self::shelf(options: ['maxItems' => 1]);
        [$first, $second] = $cubes->instances();
        $codes = Support::issues([$cubes], [$box],
            [new PackedContainer($box, 1, [self::place($first), self::place($second, 100 * self::MM)])]);
        self::assertContains('max_items_exceeded', $codes);
    }

    public static function testExceedingThePayloadCeilingIsCaught(): void
    {
        $heavy = self::item('heavy', options: ['quantity' => 2, 'weight' => '1 kg']);
        $box = self::shelf(options: ['maxPayload' => '1.5 kg']);
        [$first, $second] = $heavy->instances();
        $codes = Support::issues([$heavy], [$box],
            [new PackedContainer($box, 1, [self::place($first), self::place($second, 100 * self::MM)])]);
        self::assertContains('payload_exceeded', $codes);
    }

    public static function testAGroupSpreadAcrossContainersIsCaught(): void
    {
        // All-or-nothing is the whole meaning of a group; half of one in each of two
        // cartons is a worse answer than leaving both out.
        $kit = self::item('kit', options: ['quantity' => 2, 'group' => 'kit']);
        $box = self::shelf(options: ['quantity' => 2]);
        [$first, $second] = $kit->instances();
        $codes = Support::issues([$kit], [$box], [
            new PackedContainer($box, 1, [self::place($first)]),
            new PackedContainer($box, 2, [self::place($second)]),
        ]);
        self::assertContains('group_split', $codes);
    }

    // ------------------------------------------------------------- reported geometry

    public static function testARotationTheItemForbidsIsCaught(): void
    {
        $upright = self::item('upright', 100, 50, 50, ['allowedRotations' => [Rotation::LWH]]);
        $box = self::shelf();
        [$instance] = $upright->instances();
        $codes = Support::issues([$upright], [$box],
            [new PackedContainer($box, 1, [self::place($instance, rotation: Rotation::WLH)])]);
        self::assertContains('forbidden_rotation', $codes);
    }

    public static function testDimensionsThatDoNotMatchTheReportedRotationAreCaught(): void
    {
        // A solver could otherwise claim a box occupies a smaller footprint than it does
        // and every collision check downstream would agree with the lie.
        $brick = self::item('brick', 100, 50, 50);
        $box = self::shelf();
        [$instance] = $brick->instances();
        $lying = self::place($instance, rotation: Rotation::LWH,
            dimensions: $brick->dimensions->rotated(Rotation::WLH));
        $codes = Support::issues([$brick], [$box], [new PackedContainer($box, 1, [$lying])]);
        self::assertContains('dimension_mismatch', $codes);
    }

    public static function testAnEnvelopeThatIgnoresTheConfiguredClearanceIsCaught(): void
    {
        $cube = self::item('cube', 50, 50, 50);
        $box = self::shelf();
        [$instance] = $cube->instances();

        $withoutGap = new PackedContainer($box, 1, [self::place($instance)]);
        self::assertSame(['clearance_mismatch'], Support::issues([$cube], [$box], [$withoutGap], 0.0, Length::mm(2)));

        $withGap = new PackedContainer($box, 1, [self::place($instance, clearance: Length::mm(2)->ticks)]);
        self::assertSame([], Support::issues([$cube], [$box], [$withGap], 0.0, Length::mm(2)));
    }

    // ----------------------------------------------------------------------- physics

    public static function testAFloatingPlacementIsCaughtWhenSupportIsRequired(): void
    {
        $cube = self::item('cube', 50, 50, 50);
        $box = self::shelf();
        [$instance] = $cube->instances();
        $floating = new PackedContainer($box, 1, [self::place($instance, z: 50 * self::MM)]);
        self::assertSame(['insufficient_support'], Support::issues([$cube], [$box], [$floating], 0.5));
    }

    public static function testATippingPlacementIsCaughtEvenThoughTheAreaRatioIsMet(): void
    {
        // Area ratio alone is not stability: a 40% overlap on one side
        // clears a 0.3 requirement while leaving the candidate's own centroid
        // unsupported.
        $base = self::item('base', 40, 100, 10);
        $top = self::item('top', 100, 100, 10, ['minimumSupportRatio' => 0.3]);
        $box = self::shelf();
        [$baseInstance] = $base->instances();
        [$topInstance] = $top->instances();
        $packed = new PackedContainer($box, 1, [self::place($baseInstance), self::place($topInstance, z: 10 * self::MM)]);
        self::assertContains('centre_of_gravity_unsupported', Support::issues([$base, $top], [$box], [$packed]));
    }

    public static function testAnAxleOverloadIsCaught(): void
    {
        $box = self::shelf(1000, 100, 100, [
            'axles' => [new Axle(Length::mm(100), Weight::of(399, 'kg')), new Axle(Length::mm(900), Weight::of(500, 'kg'))],
        ]);
        $heavy = self::item('heavy', 1000, 100, 100, ['weight' => '800 kg']);
        [$instance] = $heavy->instances();
        $packed = new PackedContainer($box, 1, [self::place($instance)]);
        self::assertContains('axle_overloaded', Support::issues([$heavy], [$box], [$packed]));
    }

    public static function testAFloorOnlyItemLiftedOffTheFloorIsCaught(): void
    {
        $grounded = self::item('grounded', 50, 50, 50, ['mustBeOnFloor' => true]);
        $box = self::shelf();
        [$instance] = $grounded->instances();
        $codes = Support::issues([$grounded], [$box],
            [new PackedContainer($box, 1, [self::place($instance, z: 50 * self::MM)])]);
        self::assertContains('must_be_on_floor', $codes);
    }

    public static function testACrushedBaseIsCaught(): void
    {
        $base = self::item('base', 100, 100, 50, ['weight' => '1 kg', 'maxTopLoad' => '1 kg']);
        $load = self::item('load', 100, 100, 50, ['weight' => '5 kg']);
        $box = self::shelf();
        [$baseInstance] = $base->instances();
        [$loadInstance] = $load->instances();
        $packed = new PackedContainer($box, 1, [self::place($baseInstance), self::place($loadInstance, z: 50 * self::MM)]);
        self::assertContains('top_load_exceeded', Support::issues([$base, $load], [$box], [$packed]));
    }

    public static function testACrushingFloorLoadIsCaughtByDensityEvenWithinAFlatTopLoad(): void
    {
        // A flat maxTopLoad alone cannot express this: 550 kg is comfortably under a
        // 1000 kg absolute limit, but crushing once concentrated onto a 1 square
        // metre footprint below the container's 500 kg/m^2 floor loading.
        // The Python suite carries this same test already (test_validation.py); PHP
        // had no equivalent through the validator at all until now.
        $denseShelf = self::shelf(1000, 1000, 200, ['maxStackDensity' => '500 kg']);
        $base = self::item('base', 1000, 1000, 50, ['weight' => '400 kg', 'maxTopLoad' => '1000 kg']);
        $load = self::item('load', 1000, 1000, 50, ['weight' => '150 kg']);
        [$baseInstance] = $base->instances();
        [$loadInstance] = $load->instances();
        $packed = new PackedContainer($denseShelf, 1, [self::place($baseInstance), self::place($loadInstance, z: 50 * self::MM)]);
        self::assertContains('stack_density_exceeded', Support::issues([$base, $load], [$denseShelf], [$packed]));
    }

    public static function testAFloorLoadWithinTheDensityLimitIsNotCaught(): void
    {
        $denseShelf = self::shelf(1000, 1000, 200, ['maxStackDensity' => '500 kg']);
        $base = self::item('base', 1000, 1000, 50, ['weight' => '400 kg']);
        $load = self::item('load', 1000, 1000, 50, ['weight' => '100 kg']);
        [$baseInstance] = $base->instances();
        [$loadInstance] = $load->instances();
        $packed = new PackedContainer($denseShelf, 1, [self::place($baseInstance), self::place($loadInstance, z: 50 * self::MM)]);
        self::assertSame([], Support::issues([$base, $load], [$denseShelf], [$packed]));
    }

    public static function testATransitiveThreeHighStackLimitViolationIsCaught(): void
    {
        // ConstraintTest's testAThreeHighColumnCountsTransitivelyNotJustTheNeighbour
        // already proves stackedCounts/stackLimitExceeded count the full supported
        // column, not just the direct neighbour, when driven directly; this proves
        // the independent validator does too, reconstructing the column from real
        // PackedContainer placements -- a naive direct-neighbour count
        // would wrongly allow this (base only directly touches middle, count 1,
        // meeting its own limit of 1), while the correct transitive count of 2
        // (middle and top, both resting somewhere above base) exceeds it.
        $base = self::item('base', 100, 100, 10, ['maxStackedItems' => 1]);
        $middle = self::item('middle', 100, 100, 10);
        $top = self::item('top', 100, 100, 10);
        $box = self::shelf(100, 100, 40);
        [$baseInstance] = $base->instances();
        [$middleInstance] = $middle->instances();
        [$topInstance] = $top->instances();
        $packed = new PackedContainer($box, 1, [
            self::place($baseInstance), self::place($middleInstance, 0, 0, 10 * self::MM),
            self::place($topInstance, 0, 0, 20 * self::MM),
        ]);
        self::assertContains('stacked_item_limit_exceeded', Support::issues([$base, $middle, $top], [$box], [$packed]));
    }

    public static function testAStackLimitMetByTheDirectNeighbourAloneIsNotCaught(): void
    {
        // The same geometry as above with base's limit merely unset -- proves the
        // previous test's rejection comes from the stack limit specifically, not
        // from the column's geometry itself.
        $base = self::item('base', 100, 100, 10);
        $middle = self::item('middle', 100, 100, 10);
        $top = self::item('top', 100, 100, 10);
        $box = self::shelf(100, 100, 40);
        [$baseInstance] = $base->instances();
        [$middleInstance] = $middle->instances();
        [$topInstance] = $top->instances();
        $packed = new PackedContainer($box, 1, [
            self::place($baseInstance), self::place($middleInstance, 0, 0, 10 * self::MM),
            self::place($topInstance, 0, 0, 20 * self::MM),
        ]);
        self::assertSame([], Support::issues([$base, $middle, $top], [$box], [$packed]));
    }

    public static function testStackingOntoANonStackableItemIsCaught(): void
    {
        $base = self::item('base', 100, 100, 50, ['stackable' => false]);
        $load = self::item('load', 100, 100, 50);
        $box = self::shelf();
        [$baseInstance] = $base->instances();
        [$loadInstance] = $load->instances();
        $packed = new PackedContainer($box, 1, [self::place($baseInstance), self::place($loadInstance, z: 50 * self::MM)]);
        self::assertContains('non_stackable', Support::issues([$base, $load], [$box], [$packed]));
    }

    public static function testTheStackingRuleIsCheckedAgainstLaterPlacementsToo(): void
    {
        // Support and stacking are geometric facts. Judging a placement only against the
        // ones reported before it let a solver hide a violation by ordering its output.
        $base = self::item('base', 100, 100, 50, ['stackable' => false]);
        $load = self::item('load', 100, 100, 50);
        $box = self::shelf();
        [$baseInstance] = $base->instances();
        [$loadInstance] = $load->instances();
        $reversed = new PackedContainer($box, 1, [self::place($loadInstance, z: 50 * self::MM), self::place($baseInstance)]);
        self::assertContains('non_stackable', Support::issues([$base, $load], [$box], [$reversed]));
    }

    // --------------------------------------------------------- ground contact

    public static function testARatioThatWouldPassIsStillCaughtByTheCornerRule(): void
    {
        // A ratio cannot express the corner rule: 64% coverage concentrated in the
        // middle clears a 50% requirement but touches none of the four base
        // corners. ConstraintTest's equivalent test already proves SupportConstraint
        // itself catches this when driven directly; this proves the independent
        // validator does too, reconstructing supporters from real PackedContainer
        // placements rather than a hand-built ConstraintContext (the constraint layer's own
        // "reconstruct supporters independently from placements").
        $plate = self::item('plate', 80, 80, 10);
        $lid = self::item('lid', 100, 100, 10, ['minimumSupportRatio' => 0.5, 'groundContactRule' => 'covered']);
        $box = self::shelf(100, 100, 30);
        [$plateInstance] = $plate->instances();
        [$lidInstance] = $lid->instances();
        $packed = new PackedContainer($box, 1, [
            self::place($plateInstance, 10 * self::MM, 10 * self::MM), self::place($lidInstance, 0, 0, 10 * self::MM),
        ]);
        self::assertContains('ground_contact_violation', Support::issues([$plate, $lid], [$box], [$packed]));
    }

    public static function testTheSamePlacementRaisesNothingWithoutTheCornerRule(): void
    {
        // The exact same geometry as above, groundContactRule merely unset -- proves
        // the previous test's rejection comes from the corner rule specifically, not
        // from the ratio, an unrelated bookkeeping check, or the plate/lid geometry
        // itself.
        $plate = self::item('plate', 80, 80, 10);
        $lid = self::item('lid', 100, 100, 10, ['minimumSupportRatio' => 0.5]);
        $box = self::shelf(100, 100, 30);
        [$plateInstance] = $plate->instances();
        [$lidInstance] = $lid->instances();
        $packed = new PackedContainer($box, 1, [
            self::place($plateInstance, 10 * self::MM, 10 * self::MM), self::place($lidInstance, 0, 0, 10 * self::MM),
        ]);
        self::assertSame([], Support::issues([$plate, $lid], [$box], [$packed]));
    }

    public static function testSingleAndMultipleRulesAreAlsoCaughtThroughTheValidator(): void
    {
        // covered above exercises the corner check specifically; single and
        // multiple share the same dispatch path in IndependentSolutionValidator and
        // are checked here too so the whole rule vocabulary is proven through the
        // validator, not just one member of it.
        $left = self::item('left', 50, 100, 10);
        $right = self::item('right', 50, 100, 10);
        $box = self::shelf(100, 100, 30);
        [$leftInstance] = $left->instances();
        [$rightInstance] = $right->instances();

        $splitCandidate = self::item('split', 100, 100, 10, ['groundContactRule' => 'single']);
        [$splitInstance] = $splitCandidate->instances();
        $splitPacked = new PackedContainer($box, 1, [
            self::place($leftInstance, 0), self::place($rightInstance, 800_000), self::place($splitInstance, 0, 0, 10 * self::MM),
        ]);
        self::assertContains('ground_contact_violation', Support::issues([$left, $right, $splitCandidate], [$box], [$splitPacked]));

        $singleSupport = self::item('base', 100, 100, 10);
        $multipleCandidate = self::item('bridge', 100, 100, 10, ['groundContactRule' => 'multiple']);
        [$singleSupportInstance] = $singleSupport->instances();
        [$multipleInstance] = $multipleCandidate->instances();
        $singlePacked = new PackedContainer($box, 1, [
            self::place($singleSupportInstance), self::place($multipleInstance, 0, 0, 10 * self::MM),
        ]);
        self::assertContains('ground_contact_violation', Support::issues([$singleSupport, $multipleCandidate], [$box], [$singlePacked]));
    }

    public static function testFreeAndFloorPlacementsRaiseNothingThroughTheValidator(): void
    {
        // free never checks contact at all, and a floor placement (z=0) satisfies
        // every rule by definition -- both need to stay true through the
        // validator's own dispatch, not only in SupportConstraint's own unit tests.
        $base = self::item('base', 100, 100, 10);
        $box = self::shelf(100, 100, 30);
        foreach (['free', 'covered', 'single', 'multiple'] as $rule) {
            $floorItem = self::item("floor-{$rule}", 100, 100, 10, ['groundContactRule' => $rule]);
            [$floorInstance] = $floorItem->instances();
            $packed = new PackedContainer($box, 1, [self::place($floorInstance)]);
            self::assertSame([], Support::issues([$floorItem], [$box], [$packed]), $rule);
        }

        $airborne = self::item('airborne', 50, 50, 10, ['groundContactRule' => 'free']);
        [$airborneInstance] = $airborne->instances();
        [$baseInstance] = $base->instances();
        $packed = new PackedContainer($box, 1, [
            self::place($baseInstance, 0, 0), self::place($airborneInstance, 0, 0, 10 * self::MM),
        ]);
        self::assertSame([], Support::issues([$base, $airborne], [$box], [$packed]));
    }

    // ------------------------------------------------------- container eligibility

    public static function testAPlacementInAnIneligibleContainerIsCaught(): void
    {
        // ConstraintTest's testAnIneligibleContainerIsRefused already proves
        // ContainerEligibilityConstraint itself rejects this when driven directly
        // (and the solver's own default constraint set includes it, so an
        // eligible-tag mismatch is refused before a placement is ever attempted, not
        // merely reported after the fact); this proves the independent validator
        // catches the same violation reconstructed from real PackedContainer
        // placements, in case a solver bug or a hand-built scene ever
        // produced one anyway.
        $perishable = self::item('perishable', 50, 50, 50, ['eligibleContainerTags' => ['refrigerated']]);
        $ordinary = self::shelf(options: ['tags' => []]);
        [$perishableInstance] = $perishable->instances();
        $packed = new PackedContainer($ordinary, 1, [self::place($perishableInstance)]);
        self::assertContains('container_ineligible', Support::issues([$perishable], [$ordinary], [$packed]));
    }

    public static function testAPlacementInAnEligibleContainerIsNotCaught(): void
    {
        $perishable = self::item('perishable', 50, 50, 50, ['eligibleContainerTags' => ['refrigerated']]);
        $refrigerated = self::shelf(options: ['tags' => ['refrigerated']]);
        [$perishableInstance] = $perishable->instances();
        $packed = new PackedContainer($refrigerated, 1, [self::place($perishableInstance)]);
        self::assertSame([], Support::issues([$perishable], [$refrigerated], [$packed]));
    }

    public static function testAnItemWithNoEligibilityTagsMayGoInAnyContainer(): void
    {
        $ordinary = self::item('box', 50, 50, 50);
        $box = self::shelf(options: ['tags' => []]);
        [$instance] = $ordinary->instances();
        $packed = new PackedContainer($box, 1, [self::place($instance)]);
        self::assertSame([], Support::issues([$ordinary], [$box], [$packed]));
    }

    // -------------------------------------------------------------- tag counts

    public static function testATagLimitExceededByAThirdItemIsCaught(): void
    {
        // ConstraintTest's testAThirdItemOfALimitedTagIsRefused already proves
        // TagCountConstraint itself rejects this when driven directly; this proves
        // the independent validator catches the same violation reconstructed from
        // real PackedContainer placements. Every one of the three placements is
        // checked against the other two (the same "later placements too" discipline
        // the stacking rule already gets), so all three are reported.
        $a = self::item('a', 50, 50, 50, ['tags' => ['hazmat']]);
        $b = self::item('b', 50, 50, 50, ['tags' => ['hazmat']]);
        $c = self::item('c', 50, 50, 50, ['tags' => ['hazmat']]);
        $box = self::shelf(200, 100, 100, ['tagLimits' => ['hazmat' => 2]]);
        [$aInstance] = $a->instances();
        [$bInstance] = $b->instances();
        [$cInstance] = $c->instances();
        $packed = new PackedContainer($box, 1, [
            self::place($aInstance, 0), self::place($bInstance, 50 * self::MM), self::place($cInstance, 100 * self::MM),
        ]);
        $codes = Support::issues([$a, $b, $c], [$box], [$packed]);
        self::assertSame(3, count(array_filter($codes, static fn($code) => $code === 'tag_count_exceeded')));
    }

    public static function testATagCountAtExactlyTheLimitIsNotCaught(): void
    {
        $a = self::item('a', 50, 50, 50, ['tags' => ['hazmat']]);
        $b = self::item('b', 50, 50, 50, ['tags' => ['hazmat']]);
        $box = self::shelf(200, 100, 100, ['tagLimits' => ['hazmat' => 2]]);
        [$aInstance] = $a->instances();
        [$bInstance] = $b->instances();
        $packed = new PackedContainer($box, 1, [self::place($aInstance, 0), self::place($bInstance, 50 * self::MM)]);
        self::assertSame([], Support::issues([$a, $b], [$box], [$packed]));
    }

    public static function testAnUntaggedItemIsNeverLimitedThroughTheValidator(): void
    {
        $a = self::item('a', 50, 50, 50, ['tags' => ['hazmat']]);
        $plain = self::item('plain', 50, 50, 50);
        $box = self::shelf(200, 100, 100, ['tagLimits' => ['hazmat' => 1]]);
        [$aInstance] = $a->instances();
        [$plainInstance] = $plain->instances();
        $packed = new PackedContainer($box, 1, [self::place($aInstance, 0), self::place($plainInstance, 50 * self::MM)]);
        self::assertSame([], Support::issues([$a, $plain], [$box], [$packed]));
    }

    public static function testIncompatibleNeighboursAreCaught(): void
    {
        $food = self::item('food', 50, 50, 50, ['tags' => ['food']]);
        $chemical = self::item('chem', 50, 50, 50, ['incompatibleTags' => ['food']]);
        $box = self::shelf();
        [$foodInstance] = $food->instances();
        [$chemicalInstance] = $chemical->instances();
        $packed = new PackedContainer($box, 1, [self::place($foodInstance), self::place($chemicalInstance, 50 * self::MM)]);
        self::assertContains('incompatible_items', Support::issues([$food, $chemical], [$box], [$packed]));
    }

    // --------------------------------------------------------- unloading order

    public static function testARouteOrderThatAgreesWithTheStackingOrderRaisesNothing(): void
    {
        // `top` (the contact graph: it must come off before `bottom` no matter
        // what any route says) is also due at the earlier stop here, so nothing
        // conflicts.
        $bottom = self::item('bottom', 100, 100, 100, ['stopIndex' => 1]);
        $top = self::item('top', 100, 100, 100, ['stopIndex' => 0]);
        $box = self::shelf(100, 100, 200);
        [$bottomInstance] = $bottom->instances();
        [$topInstance] = $top->instances();
        $packed = new PackedContainer($box, 1, [self::place($bottomInstance), self::place($topInstance, 0, 0, 100 * self::MM)]);
        self::assertSame([], Support::issues([$bottom, $top], [$box], [$packed]));
    }

    public static function testAStopIndexThatCannotBeUnloadedInRouteOrderIsCaught(): void
    {
        // `bottom` is due at the earlier stop, but `top` -- structurally required
        // off first regardless of any route, since it rests on `bottom` -- is not
        // due until later. No order can satisfy both, so this must come back as its
        // own, distinct reason code, not get folded into an unrelated one.
        $bottom = self::item('bottom', 100, 100, 100, ['stopIndex' => 0]);
        $top = self::item('top', 100, 100, 100, ['stopIndex' => 1]);
        $box = self::shelf(100, 100, 200);
        [$bottomInstance] = $bottom->instances();
        [$topInstance] = $top->instances();
        $packed = new PackedContainer($box, 1, [self::place($bottomInstance), self::place($topInstance, 0, 0, 100 * self::MM)]);
        self::assertContains('unloading_order_violation', Support::issues([$bottom, $top], [$box], [$packed]));
    }

    public static function testItemsWithoutAStopIndexAreNotRouteCheckedAtAll(): void
    {
        // Existing single-stop callers never set stopIndex -- the exact same
        // structurally-conflicting stack as the violation case above must raise
        // nothing at all when neither item is on a route, not merely avoid the new
        // code.
        $a = self::item('a', 100, 100, 100);
        $b = self::item('b', 100, 100, 100);
        $box = self::shelf(100, 100, 200);
        [$aInstance] = $a->instances();
        [$bInstance] = $b->instances();
        $packed = new PackedContainer($box, 1, [self::place($aInstance), self::place($bInstance, 0, 0, 100 * self::MM)]);
        self::assertSame([], Support::issues([$a, $b], [$box], [$packed]));
    }

    // --------------------------------------------------------------------- reporting

    public static function testTheReportNamesTheOffendingItem(): void
    {
        $cubes = self::item('cube', options: ['quantity' => 2]);
        $box = self::shelf();
        [$first, $second] = $cubes->instances();
        $report = (new IndependentSolutionValidator())->validate(
            new PackingRequest([$cubes], [$box]),
            [new PackedContainer($box, 1, [self::place($first), self::place($second, 50 * self::MM)])],
        );

        self::assertFalse($report->valid);
        $named = false;
        foreach ($report->issues as $issue) {
            $named = $named || (str_contains($issue->detail, 'cube#1') && str_contains($issue->detail, 'cube#2'));
        }
        self::assertTrue($named, 'the report must name both sides of a collision');
    }
}
