<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Constraint\AxleLoadConstraint;
use Packvium\Constraint\CompatibilityConstraint;
use Packvium\Constraint\ConstraintContext;
use Packvium\Constraint\ContactGraph;
use Packvium\Constraint\ContainerEligibilityConstraint;
use Packvium\Constraint\FloorConstraint;
use Packvium\Constraint\LoadCalculator;
use Packvium\Constraint\Internal\{LoadAnalysis,LoadSupportGraph};
use Packvium\Constraint\LoadUnit;
use Packvium\Constraint\RouteOrderConstraint;
use Packvium\Constraint\StopAccessibilityConstraint;
use Packvium\Constraint\SupportConstraint;
use Packvium\Constraint\TagCountConstraint;
use Packvium\Constraint\TopLoadConstraint;
use Packvium\Domain\Axle;
use Packvium\Domain\AxisAlignedBox;
use Packvium\Domain\Container;
use Packvium\Domain\Dimensions;
use Packvium\Domain\Item;
use Packvium\Domain\ItemInstance;
use Packvium\Domain\Placement;
use Packvium\Domain\Point;
use Packvium\Domain\Rotation;
use Packvium\Domain\SweptRegion;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;

/**
 * Physical placement rules: floor, compatibility, support area and bearing load.
 *
 * These decide feasibility, so every comparison is exact integer arithmetic even
 * though the public API accepts support ratios as floats. The expected values here
 * are the same ones the Python suite asserts — that is the point of having both.
 */
final class ConstraintTest extends TestCase
{
    private const MM = 16_000;

    private static function box(): Container
    {
        return Container::create('box', Dimensions::mm(100, 100, 100));
    }

    private static function unit(int $x, int $y, int $z, int $l, int $w, int $h,
                                 int $weight = 0, ?int $limit = null, ?int $stackLimit = null, string $label = 'u',
                                 ?string $nestingItemId = null, ?int $nestingHeightTicks = null): LoadUnit
    {
        $box = new AxisAlignedBox(new Point($x, $y, $z), new Dimensions(new Length($l), new Length($w), new Length($h)));
        return new LoadUnit($box, $weight, $limit, $stackLimit, $label, $nestingItemId, $nestingHeightTicks);
    }

    /** @param array<string,mixed> $options */
    private static function instance(string $id, int $l = 10, int $w = 10, int $h = 10, array $options = []): ItemInstance
    {
        [$instance] = Item::create($id, Dimensions::mm($l, $w, $h), ...$options)->instances();
        return $instance;
    }

    private static function placed(ItemInstance $instance, int $x = 0, int $y = 0, int $z = 0): Placement
    {
        $dims = $instance->item->dimensions;
        return new Placement($instance, new Point($x, $y, $z), Rotation::LWH, $dims, new Point($x, $y, $z), $dims);
    }

    /** @param list<Placement> $placements */
    private static function context(ItemInstance $instance, int $z = 0, array $placements = [],
                                    bool $stackSensitive = true, ?Container $container = null,
                                    int $x = 0, bool $routeSensitive = true): ConstraintContext
    {
        $dims = $instance->item->dimensions;
        return new ConstraintContext($container ?? self::box(), $placements, $instance, new Point($x, 0, $z),
            Rotation::LWH, $dims, $dims, $stackSensitive, $routeSensitive);
    }

    // ------------------------------------------- stop accessibility
    //
    // The worked examples in docs/STOP-ACCESSIBILITY.md live in
    // `conformance/scene/stop-accessibility-fixtures.json` and are read from there below,
    // so this suite and the other three assert one table rather than four transcriptions.
    // The helpers here serve the direction-canonicalisation test, which has no
    // cross-language counterpart because each engine canonicalises in its own idiom.

    private const DOOR_AT_MINUS_X = ['-x'];

    /** A full-width, full-height box, so a corridor it stands in is completely filled. */
    private static function wide(string $id, int $length, ?int $stop): ItemInstance
    {
        return Item::create($id, Dimensions::mm($length, 100, 100), stopIndex: $stop)->instances()[0];
    }

    /** @param list<string> $directions */
    private static function scene(ItemInstance $placedItem, int $placedX, ItemInstance $candidate,
                                  int $candidateX, array $directions = self::DOOR_AT_MINUS_X)
    {
        return (new StopAccessibilityConstraint($directions))->evaluate(self::context(
            $candidate, placements: [self::placed($placedItem, $placedX * self::MM)],
            x: $candidateX * self::MM,
        ));
    }

    /**
     * The worked examples, held once for all four engines instead of transcribed into each.
     *
     * A published copy of the package does not carry the corpus, so the test that reads it
     * skips rather than failing for everyone who installed the package.
     */
    private const STOP_SCENES = __DIR__ . '/../../conformance/scene/stop-accessibility-fixtures.json';

    /**
     * The corpus is in ticks, so it is read straight rather than through `Dimensions::mm`:
     * all four engines assert the same integers instead of each scaling by its own factor.
     *
     * @param array{length:int,width:int,height:int} $raw
     */
    private static function fixtureDimensions(array $raw): Dimensions
    {
        return new Dimensions(new Length($raw['length']), new Length($raw['width']), new Length($raw['height']));
    }

    /** @param array<string,mixed> $raw */
    private static function fixtureInstance(array $raw): ItemInstance
    {
        return Item::create($raw['id'], self::fixtureDimensions($raw['dimensions']),
            stopIndex: $raw['stop_index'])->instances()[0];
    }

    /**
     * Every scene in the shared corpus, with the verdict every engine must reach.
     *
     * `accessible` is asserted by all four engines. `code` is asserted here and in Python,
     * whose constraint returns a reason rather than a boolean, and `route_order_allowed`
     * here, in Python and in Rust -- the corpus records that asymmetry so it is not
     * rediscovered.
     */
    public static function testSharedFourLanguageStopAccessibilityScenes(): void
    {
        if (!is_file(self::STOP_SCENES)) {
            self::skip('the shared cross-language scene corpus is not part of this package');
        }
        $payload = json_decode((string) file_get_contents(self::STOP_SCENES), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotSame([], $payload['scenes'],
            'an empty corpus would pass this loop without asserting anything');

        foreach ($payload['scenes'] as $scene) {
            $raw = $scene['candidate'];
            $placements = array_map(
                static fn(array $each): Placement => new Placement(
                    self::fixtureInstance($each),
                    new Point($each['origin']['x'], $each['origin']['y'], $each['origin']['z']),
                    Rotation::LWH,
                    self::fixtureDimensions($each['dimensions']),
                    new Point($each['origin']['x'], $each['origin']['y'], $each['origin']['z']),
                    self::fixtureDimensions($each['dimensions']),
                ),
                $scene['placements'],
            );
            $candidate = self::fixtureInstance($raw);
            $dimensions = self::fixtureDimensions($raw['dimensions']);
            $evaluated = new ConstraintContext(
                Container::create('fixture', self::fixtureDimensions($scene['container'])),
                $placements,
                $candidate,
                new Point($raw['origin']['x'], $raw['origin']['y'], $raw['origin']['z']),
                Rotation::LWH,
                $dimensions,
                $dimensions,
                true,
                true,
            );
            $result = (new StopAccessibilityConstraint($scene['directions']))->evaluate($evaluated);

            self::assertSame($scene['accessible'], $result->allowed, $scene['id']);
            if (isset($scene['code'])) {
                self::assertSame($scene['code'], $result->code, $scene['id']);
            }
            if (array_key_exists('route_order_allowed', $scene)) {
                self::assertSame($scene['route_order_allowed'],
                    (new RouteOrderConstraint())->evaluate($evaluated)->allowed, $scene['id']);
            }
        }
    }

    public static function testDirectionsAreCanonicalisedSoTwoCallersSearchIdentically(): void
    {
        // Order and duplicates must not reach the search: which door is tried first decides
        // which of several legal answers comes back.
        $one = self::scene(self::wide('early', 40, 0), 60, self::wide('late', 60, 1), 0, ['+x', '-x', '-x']);
        $two = self::scene(self::wide('early', 40, 0), 60, self::wide('late', 60, 1), 0, ['-x', '+x']);
        self::assertSame($one->allowed, $two->allowed);
    }

    public static function testSweptVolumeRefusesAnUnknownDirectionAtThePrimitive(): void
    {
        // The constraint validates its doors at construction, but the primitive is public
        // and `Packvium\Sequence` calls it directly, so it owes the same refusal. Python's
        // `swept_volume` is held to this too.
        $box = new AxisAlignedBox(new Point(0, 0, 0), Dimensions::mm(10, 10, 10));
        try {
            SweptRegion::volume($box, Dimensions::mm(100, 100, 100), 'sideways');
            self::fail('an unknown direction must be refused, not treated as one of the six');
        } catch (\InvalidArgumentException $error) {
            self::assertTrue(str_contains($error->getMessage(), 'sideways'),
                'the refusal must name the direction it refused');
        }
    }

    public static function testAPlacedFixtureNeedsNoDoorOfItsOwn(): void
    {
        // The other side of worked example D. There the permanent item was the *candidate*
        // and took someone's last door; here it is already placed, and the corridor base
        // must not spend a sweep looking for an exit it will never use -- an item that
        // never leaves needs no door, it only ever blocks.
        $fixture = self::wide('fixture', 40, null);
        $late = self::wide('late', 60, 1);
        $result = self::scene($fixture, 60, $late, 0);
        // Nothing is due before the candidate, so the corridor rule has no complaint.
        self::assertTrue($result->allowed);
    }

    public static function testAnUnknownDirectionIsRefusedRatherThanGuessed(): void
    {
        try {
            new StopAccessibilityConstraint(['north']);
            self::fail('an unknown direction must be refused, not silently treated as one of the six');
        } catch (\InvalidArgumentException $error) {
            self::assertTrue(str_contains($error->getMessage(), 'north'),
                'the refusal must name the direction it refused');
        }
    }

    // --------------------------------------------------- scaled ratio arithmetic

    public static function testRatiosScaleToPartsPerMillion(): void
    {
        self::assertSame(0, SupportConstraint::scaled(0.0));
        self::assertSame(750_000, SupportConstraint::scaled(0.75));
        self::assertSame(1_000_000, SupportConstraint::scaled(1.0));
        self::assertSame(333_333, SupportConstraint::scaled(1 / 3));
    }

    public static function testRequiredAreaIsAnExactFloorDivision(): void
    {
        // Computed without ever forming baseArea * ratio, which would overflow a 64-bit
        // integer for a container-sized footprint.
        foreach ([0, 1, 999_999, 1_000_000, 2_560_000_000_000] as $baseArea) {
            foreach ([0, 1, 333_333, 500_000, 750_000, 1_000_000] as $ratio) {
                $expected = (int)\Packvium\Support\BigInt::divide(
                    \Packvium\Support\BigInt::multiply((string)$baseArea, (string)$ratio), '1000000');
                self::assertSame($expected, SupportConstraint::requiredArea($baseArea, $ratio),
                    "baseArea={$baseArea} ratio={$ratio}");
            }
        }
    }

    public static function testFullSupportRequiresTheWholeFootprint(): void
    {
        self::assertSame(2_560_000_000_000, SupportConstraint::requiredArea(2_560_000_000_000, 1_000_000));
    }

    // ------------------------------------------------------------ load propagation

    public static function testATowerPushesItsWholeWeightOntoTheBase(): void
    {
        // Not just the box directly underneath: a stack of light items must be able to
        // crush a base rated for less than their sum.
        $tower = [
            self::unit(0, 0, 0, 10, 10, 10, weight: 100, label: 'bottom'),
            self::unit(0, 0, 10, 10, 10, 10, weight: 100, label: 'middle'),
            self::unit(0, 0, 20, 10, 10, 10, weight: 100, label: 'top'),
        ];
        self::assertSame([200, 100, 0], LoadCalculator::topLoads($tower));
    }

    public static function testAThreeLayerNestedColumnPropagatesLoadAndStackedCountDirectly(): void
    {
        $nested = [
            self::unit(0, 0, 0, 10, 10, 10, weight: 100, limit: 200, label: 'bottom', nestingItemId: 'crate', nestingHeightTicks: 5),
            self::unit(0, 0, 5, 10, 10, 10, weight: 100, limit: 75, label: 'middle', nestingItemId: 'crate', nestingHeightTicks: 5),
            self::unit(0, 0, 10, 10, 10, 10, weight: 100, label: 'top', nestingItemId: 'crate', nestingHeightTicks: 5),
        ];

        self::assertSame([200, 100, 0], LoadCalculator::topLoads($nested));
        self::assertSame([2, 1, 0], LoadCalculator::stackedCounts($nested));
        self::assertSame(['top_load_exceeded', 'middle'], LoadCalculator::overloaded($nested));
    }

    public static function testNonStackableRejectsNestedCandidatesAboveAndBelow(): void
    {
        $crate = Item::create(
            'crate', Dimensions::mm(100, 100, 50), quantity: 2,
            stackable: false, nestingHeight: Length::mm(25),
        );
        [$lower, $upper] = $crate->instances();
        $constraint = new TopLoadConstraint();

        $above = $constraint->evaluate(self::context(
            $upper, 25 * self::MM, [self::placed($lower)],
        ));
        $below = $constraint->evaluate(self::context(
            $lower, 0, [self::placed($upper, z: 25 * self::MM)],
        ));

        self::assertFalse($above->allowed);
        self::assertSame('non_stackable', $above->code);
        self::assertFalse($below->allowed);
        self::assertSame('non_stackable', $below->code);
    }

    public static function testANestedTopLoadBoundaryRejectsOneTickBelowTheExactLoad(): void
    {
        $nested = [
            self::unit(0, 0, 0, 10, 10, 10, weight: 100, limit: 199, label: 'bottom', nestingItemId: 'crate', nestingHeightTicks: 4),
            self::unit(0, 0, 6, 10, 10, 10, weight: 100, label: 'middle', nestingItemId: 'crate', nestingHeightTicks: 4),
            self::unit(0, 0, 12, 10, 10, 10, weight: 100, label: 'top', nestingItemId: 'crate', nestingHeightTicks: 4),
        ];

        self::assertSame(['top_load_exceeded', 'bottom'], LoadCalculator::overloaded($nested));
    }

    public static function testNestedSupportIsOneFullAreaPredecessorWithoutAShadowFace(): void
    {
        foreach ([['single', true], ['covered', true], ['multiple', false]] as [$rule, $allowed]) {
            $crate = Item::create(
                'crate', Dimensions::mm(10, 10, 10), quantity: 3,
                minimumSupportRatio: 1.0, groundContactRule: $rule,
                nestingHeight: Length::mm(5),
            );
            [$bottom, $middle, $top] = $crate->instances();
            $placements = [self::placed($bottom), self::placed($middle, z: 5 * self::MM)];
            $candidate = self::context($top, 10 * self::MM, $placements);

            $support = LoadSupportGraph::candidateView($placements, $top, $candidate->envelopeBox());
            self::assertSame([1], $support->supporterIndexes, $rule);
            self::assertSame($top->dimensions()->baseAreaTicks(), $support->supportingArea, $rule);
            $result = (new SupportConstraint())->evaluate($candidate);
            self::assertSame($allowed, $result->allowed, $rule);
            if (!$allowed) self::assertSame('ground_contact_violation', $result->code);
        }
    }

    public static function testEqualItemIdsWithDifferentNestingDepthsDoNotShareSupport(): void
    {
        $lower = self::instance('crate', options: ['nestingHeight' => Length::mm(4)]);
        $upper = self::instance('crate', options: [
            'nestingHeight' => Length::mm(5), 'minimumSupportRatio' => 1.0,
        ]);
        $result = (new SupportConstraint())->evaluate(
            self::context($upper, 6 * self::MM, [self::placed($lower)]),
        );
        self::assertFalse($result->allowed);
        self::assertSame('insufficient_support', $result->code);

        $units = [
            self::unit(0, 0, 0, 10, 10, 10, nestingItemId: 'crate', nestingHeightTicks: 4),
            self::unit(0, 0, 6, 10, 10, 10, weight: 100,
                nestingItemId: 'crate', nestingHeightTicks: 5),
        ];
        self::assertSame([0, 0], LoadCalculator::topLoads($units));
    }

    public static function testOnlyTheAdjacentNestingLayerCanBeADirectSupporter(): void
    {
        $crate = Item::create(
            'crate', Dimensions::mm(10, 10, 10), quantity: 3,
            minimumSupportRatio: 1.0, nestingHeight: Length::mm(5),
        );
        [$lower, $intervening, $candidate] = $crate->instances();
        $placements = [self::placed($lower), self::placed($intervening, z: 2 * self::MM)];

        $result = (new SupportConstraint())->evaluate(
            self::context($candidate, 5 * self::MM, $placements),
        );

        self::assertFalse($result->allowed);
        self::assertSame('insufficient_support', $result->code);
    }

    public static function testNestedSupportReplacementRestoresCanonicalIndexOrder(): void
    {
        $supporters = [
            self::unit(0, 0, 0, 10, 10, 10, nestingItemId: 'crate', nestingHeightTicks: 5),
            self::unit(0, 0, 5, 10, 10, 10, nestingItemId: 'crate', nestingHeightTicks: 5),
            self::unit(0, 0, 0, 5, 10, 5),
        ];
        $graph = new LoadSupportGraph($supporters);
        self::assertSame(
            [0, 2],
            array_map(static fn($edge): int => $edge->index, $graph->supporters(1)),
        );

        $children = [
            self::unit(0, 0, 5, 10, 10, 10, nestingItemId: 'crate', nestingHeightTicks: 5),
            self::unit(0, 0, 10, 5, 10, 5),
            self::unit(0, 0, 0, 10, 10, 10, nestingItemId: 'crate', nestingHeightTicks: 5),
        ];
        $graph = new LoadSupportGraph($children);
        self::assertSame([0, 1], $graph->children(2));
    }

    public static function testLoadSplitsByContactArea(): void
    {
        $units = [
            self::unit(0, 0, 0, 10, 10, 10, label: 'narrow'),
            self::unit(10, 0, 0, 30, 10, 10, label: 'wide'),
            self::unit(0, 0, 10, 40, 10, 10, weight: 1_000, label: 'beam'),
        ];
        self::assertSame([250, 750, 0], LoadCalculator::topLoads($units));
    }

    public static function testAnIndivisibleLoadIsConservedExactly(): void
    {
        // The integer remainder goes to the last supporter rather than being dropped.
        $units = [
            self::unit(0, 0, 0, 1, 10, 10, label: 'a'),
            self::unit(1, 0, 0, 2, 10, 10, label: 'b'),
            self::unit(0, 0, 10, 3, 10, 10, weight: 1_000, label: 'beam'),
        ];
        $loads = LoadCalculator::topLoads($units);
        self::assertSame([333, 667, 0], $loads);
        self::assertSame(1_000, array_sum($loads));
    }

    public static function testBoxesThatOnlyTouchSidewaysDoNotBearEachOther(): void
    {
        $units = [
            self::unit(0, 0, 0, 10, 10, 10, label: 'left'),
            self::unit(10, 0, 0, 10, 10, 10, weight: 500, label: 'right'),
        ];
        self::assertSame([0, 0], LoadCalculator::topLoads($units));
    }

    public static function testAnEmptyContainerHasNoLoads(): void
    {
        self::assertSame([], LoadCalculator::topLoads([]));
    }

    public static function testNoBearingLimitsMeansNoCheckToRun(): void
    {
        $units = [
            self::unit(0, 0, 0, 10, 10, 10, weight: 1_000_000_000, label: 'a'),
            self::unit(0, 0, 10, 10, 10, 10, weight: 1_000_000_000, label: 'b'),
        ];
        self::assertNull(LoadCalculator::overloaded($units));
    }

    public static function testTheFirstCrushedUnitIsReportedByName(): void
    {
        $units = [
            self::unit(0, 0, 0, 10, 10, 10, weight: 1, limit: 150, label: 'base'),
            self::unit(0, 0, 10, 10, 10, 10, weight: 100, label: 'middle'),
            self::unit(0, 0, 20, 10, 10, 10, weight: 100, label: 'top'),
        ];
        self::assertSame(['top_load_exceeded', 'base'], LoadCalculator::overloaded($units));
    }

    public static function testALimitMetExactlyIsNotExceeded(): void
    {
        $units = [
            self::unit(0, 0, 0, 10, 10, 10, limit: 100, label: 'base'),
            self::unit(0, 0, 10, 10, 10, 10, weight: 100, label: 'top'),
        ];
        self::assertNull(LoadCalculator::overloaded($units));
    }

    public static function testLoadUnitsAreBuiltFromPlacements(): void
    {
        $base = self::placed(self::instance('base', options: ['weight' => '1 kg', 'maxTopLoad' => '2 kg']));
        $units = LoadCalculator::units([$base]);
        self::assertSame('base#1', $units[0]->label);
        self::assertSame(Weight::of(1, 'kg')->ticks, $units[0]->weightTicks);
        self::assertSame(Weight::of(2, 'kg')->ticks, $units[0]->maxTopLoadTicks);
    }

    // --------------------------------------------------------------- contact graph

    private static function randomBox(): AxisAlignedBox
    {
        $l = mt_rand(1, 12) * 5; $w = mt_rand(1, 12) * 5; $h = mt_rand(1, 12) * 5;
        $x = mt_rand(0, 40) * 5; $y = mt_rand(0, 40) * 5; $z = mt_rand(0, 40) * 5;
        return new AxisAlignedBox(new Point($x, $y, $z), new Dimensions(new Length($l), new Length($w), new Length($h)));
    }

    /**
     * Independent, deliberately naive all-pairs contact check sharing no code with
     * ContactGraph's by-plane index -- silently dropping or inventing an edge is
     * exactly the risk that index carries and a shared implementation could not catch.
     *
     * @param list<AxisAlignedBox> $boxes @return array<string,int>
     */
    private static function bruteForceSupporters(array $boxes, int $index): array
    {
        $target = $boxes[$index];
        $result = [];
        foreach ($boxes as $otherIndex => $other) {
            if ($otherIndex === $index) { continue; }
            if ($other->z2() !== $target->origin->z) { continue; }
            $area = $other->overlapAreaXY($target);
            if ($area > 0) { $result[$otherIndex] = $area; }
        }
        return $result;
    }

    /** @param list<AxisAlignedBox> $boxes @return list<int> */
    private static function bruteForceChildren(array $boxes, int $index): array
    {
        $target = $boxes[$index];
        $result = [];
        foreach ($boxes as $otherIndex => $other) {
            if ($otherIndex === $index) { continue; }
            if ($other->origin->z !== $target->z2()) { continue; }
            if ($target->overlapAreaXY($other) > 0) { $result[] = $otherIndex; }
        }
        return $result;
    }

    public static function testContactGraphAgreesWithABruteForcePairwiseScan(): void
    {
        for ($seed = 0; $seed < 40; $seed++) {
            mt_srand($seed);
            $boxes = [];
            for ($i = 0, $n = mt_rand(2, 10); $i < $n; $i++) { $boxes[] = self::randomBox(); }
            $graph = new ContactGraph($boxes);
            foreach ($boxes as $index => $box) {
                $supporters = [];
                foreach ($graph->supporters($index) as $edge) { $supporters[$edge->index] = $edge->area; }
                self::assertSame(self::bruteForceSupporters($boxes, $index), $supporters, "seed {$seed}, box {$index}");
                $children = $graph->children($index);
                sort($children);
                $expectedChildren = self::bruteForceChildren($boxes, $index);
                sort($expectedChildren);
                self::assertSame($expectedChildren, $children, "seed {$seed}, box {$index}");
            }
        }
    }

    public static function testABoxWithNoNeighboursHasNoSupportersOrChildren(): void
    {
        $graph = new ContactGraph([new AxisAlignedBox(new Point(0, 0, 0), new Dimensions(new Length(10), new Length(10), new Length(10)))]);
        self::assertSame([], $graph->supporters(0));
        self::assertSame([], $graph->children(0));
    }

    public static function testContactGraphAgreesWithBruteForceOnADenseSharedLevel(): void
    {
        // The 2-10 box property test above never puts more than a handful of boxes
        // on one z-level, so it cannot exercise ContactLevelIndex's
        // multi-cell-per-box path at any real density. A regular lattice
        // (GridSolver) routinely puts hundreds of boxes on one shared level
        // -- this rebuilds that shape directly: a uniform grid on one
        // level (guaranteeing many boxes share exactly one spatial-hash cell) plus a
        // handful of random extra boxes at the same and other levels.
        for ($seed = 1000; $seed < 1010; $seed++) {
            mt_srand($seed);
            $boxes = [];
            for ($gx = 0; $gx < 12; $gx++) {
                for ($gy = 0; $gy < 12; $gy++) {
                    $boxes[] = new AxisAlignedBox(
                        new Point($gx * 10, $gy * 10, 0),
                        new Dimensions(new Length(10), new Length(10), new Length(10)),
                    );
                }
            }
            for ($i = 0; $i < 30; $i++) { $boxes[] = self::randomBox(); }
            $graph = new ContactGraph($boxes);
            foreach ($boxes as $index => $box) {
                $supporters = [];
                foreach ($graph->supporters($index) as $edge) { $supporters[$edge->index] = $edge->area; }
                self::assertSame(self::bruteForceSupporters($boxes, $index), $supporters, "seed {$seed}, box {$index}");
                $children = $graph->children($index);
                sort($children);
                $expectedChildren = self::bruteForceChildren($boxes, $index);
                sort($expectedChildren);
                self::assertSame($expectedChildren, $children, "seed {$seed}, box {$index}");
            }
        }
    }

    public static function testSupportersStayOrderedByIndexForTheRemainderSplit(): void
    {
        // topLoads (LoadCalculator) hands the rounding remainder to whichever edge is
        // *last* in supporters($index) -- this must stay the same ascending-index
        // order the original all-pairs scan produced regardless of which
        // spatial-hash cells the optimized lookup happens to visit first.
        $beam = new AxisAlignedBox(new Point(0, 0, 10), new Dimensions(new Length(3), new Length(10), new Length(10)));
        $below = [
            new AxisAlignedBox(new Point(2, 0, 0), new Dimensions(new Length(1), new Length(10), new Length(10))),
            new AxisAlignedBox(new Point(1, 0, 0), new Dimensions(new Length(1), new Length(10), new Length(10))),
            new AxisAlignedBox(new Point(0, 0, 0), new Dimensions(new Length(1), new Length(10), new Length(10))),
        ];
        $graph = new ContactGraph([$beam, ...$below]);
        $indexes = array_map(static fn($edge) => $edge->index, $graph->supporters(0));
        self::assertSame([1, 2, 3], $indexes);
    }

    // ------------------------------------------------- incremental append

    /**
     * A scene whose boxes actually touch each other.
     *
     * `randomBox` draws from a range wide enough that most of its scenes have no contact
     * at all, which is fine for the brute-force agreement property above -- an empty edge
     * set is still an edge set both implementations must agree on. It is not fine here:
     * what is under test is that a delta reproduces edges, so a corpus where most scenes
     * have no edges would pass with the delta returning nothing. Snapping every
     * coordinate and extent to one coarse lattice makes shared planes the norm.
     *
     * @return list<AxisAlignedBox>
     */
    private static function touchingScene(int $count): array
    {
        $extents = [10, 20, 30];
        $levels = [0, 10, 20, 30];
        $boxes = [];
        for ($i = 0; $i < $count; $i++) {
            $boxes[] = new AxisAlignedBox(
                new Point(mt_rand(0, 5) * 10, mt_rand(0, 5) * 10, $levels[mt_rand(0, 3)]),
                new Dimensions(
                    new Length($extents[mt_rand(0, 2)]),
                    new Length($extents[mt_rand(0, 2)]),
                    new Length($extents[mt_rand(0, 2)]),
                ),
            );
        }
        return $boxes;
    }

    /** @param list<AxisAlignedBox> $boxes */
    private static function widestFootprint(array $boxes): int
    {
        $widest = 1;
        foreach ($boxes as $box) {
            $widest = max($widest, $box->x2() - $box->origin->x, $box->y2() - $box->origin->y);
        }
        return $widest;
    }

    /**
     * Both edge directions as ordered lists.
     *
     * Compared as sequences, never as sets: `topLoads` hands the integer rounding
     * remainder to whichever supporter is *last*, so two graphs holding the same edges in
     * a different order are two different answers.
     *
     * @param ContactGraph|LoadSupportGraph $graph
     * @return array{0:list<list<array{0:int,1:int}>>,1:list<list<int>>}
     */
    private static function edges(object $graph, int $count): array
    {
        $supporters = [];
        $children = [];
        for ($index = 0; $index < $count; $index++) {
            $own = [];
            foreach ($graph->supporters($index) as $edge) { $own[] = [$edge->index, $edge->area]; }
            $supporters[] = $own;
            $children[] = $graph->children($index);
        }
        return [$supporters, $children];
    }

    public static function testAppendingABoxMatchesBuildingTheWholeSceneAtOnce(): void
    {
        // The base is built with the widest footprint in the scene as its hint, which is
        // what a solver knows before it starts placing: the candidate about to be
        // appended may be larger than anything already placed, and sizing the spatial
        // hash from the placed boxes alone would send every append into the fallback.
        for ($seed = 0; $seed < 40; $seed++) {
            mt_srand(2000 + $seed);
            $boxes = self::touchingScene(mt_rand(2, 14));
            $split = max(1, intdiv(count($boxes), 2));
            $hint = self::widestFootprint($boxes);
            $graph = new ContactGraph(array_slice($boxes, 0, $split), $hint);
            foreach (array_slice($boxes, $split) as $box) { $graph = $graph->withBox($box); }
            self::assertSame(
                self::edges(new ContactGraph($boxes), count($boxes)),
                self::edges($graph, count($boxes)),
                "seed {$seed}",
            );
        }
    }

    public static function testAppendingReusesTheBaseEdgesRatherThanRebuildingThem(): void
    {
        // Equality alone is satisfied by a `withBox` that quietly rebuilds everything --
        // correct, and none of the point. The delta shares the base's edge objects, and
        // a rebuild cannot: it constructs its own. So identity is what separates the two.
        $stack = [
            new AxisAlignedBox(new Point(0, 0, 0), new Dimensions(new Length(10), new Length(10), new Length(10))),
            new AxisAlignedBox(new Point(0, 0, 10), new Dimensions(new Length(10), new Length(10), new Length(10))),
        ];
        $base = new ContactGraph($stack, 10);
        $appended = $base->withBox(
            new AxisAlignedBox(new Point(40, 40, 0), new Dimensions(new Length(10), new Length(10), new Length(10))),
        );
        self::assertSame($base->supporters(1)[0], $appended->supporters(1)[0]);
    }

    public static function testLoadCalculationsUseTheSuppliedIncrementalGraph(): void
    {
        // A mismatched graph is not a supported production input, but it is a precise
        // Dependency-injection probe: if the internal analysis quietly rebuilt from `$stack`,
        // all three results below would describe a two-box tower. Zero/zero/null prove
        // that the supplied graph is the graph each calculation actually traversed.
        $stack = [
            self::unit(0, 0, 0, 10, 10, 10, 100, 50, 0, 'base'),
            self::unit(0, 0, 10, 10, 10, 10, 100, null, null, 'top'),
        ];
        $sideBySide = [
            self::unit(0, 0, 0, 10, 10, 10, 100, 50, 0, 'base'),
            self::unit(10, 0, 0, 10, 10, 10, 100, null, null, 'top'),
        ];
        $graph = new LoadSupportGraph($sideBySide);

        $analysis = new LoadAnalysis($stack, $graph);
        self::assertSame([0, 0], $analysis->topLoads());
        self::assertNull($analysis->overloaded());
        self::assertNull($analysis->stackLimitExceeded());
    }

    /**
     * The delta matches a rebuild across scene shapes the property test above excludes.
     *
     * That test asserts the base's ContactEdge objects are reused, which proves the delta
     * ran rather than quietly rebuilding -- and therefore never exercises a run where the
     * fallback and the delta interleave. A cell hint of one produces exactly that, several
     * times per scene.
     *
     * Three axes vary independently. Tight coordinates make shared planes and zero-area
     * edge contacts the norm; coordinates at 10^9 push the spatial hash's cell arithmetic
     * somewhere a lattice never goes; a huge hint collapses every box into one cell, which
     * is the degenerate case the hash exists to avoid and therefore the one most likely to
     * be wrong.
     */
    public static function testTheDeltaMatchesARebuildAcrossSceneShapes(): void
    {
        $tight = [0, 1, 2, 5, 10];
        $wide = [0, 10, 100, 1000000000];
        $tiny = [1, 2, 3];
        $mixed = [1, 5, 10, 40];
        $shapes = [
            'tight/tiny/exact' => [$tight, $tiny, 'exact'],
            'tight/tiny/one' => [$tight, $tiny, 'one'],
            'tight/mixed/one' => [$tight, $mixed, 'one'],
            'tight/mixed/huge' => [$tight, $mixed, 'huge'],
            'wide/mixed/exact' => [$wide, $mixed, 'exact'],
            'wide/mixed/one' => [$wide, $mixed, 'one'],
            'wide/tiny/huge' => [$wide, $tiny, 'huge'],
        ];

        foreach ($shapes as $name => [$coordinates, $extents, $hintMode]) {
            mt_srand(crc32($name));
            for ($trial = 0; $trial < 60; $trial++) {
                $count = mt_rand(1, 10);
                $boxes = [];
                for ($index = 0; $index < $count; $index++) {
                    $boxes[] = new AxisAlignedBox(
                        new Point(
                            $coordinates[mt_rand(0, count($coordinates) - 1)],
                            $coordinates[mt_rand(0, count($coordinates) - 1)],
                            $coordinates[mt_rand(0, count($coordinates) - 1)],
                        ),
                        new Dimensions(
                            new Length($extents[mt_rand(0, count($extents) - 1)]),
                            new Length($extents[mt_rand(0, count($extents) - 1)]),
                            new Length($extents[mt_rand(0, count($extents) - 1)]),
                        ),
                    );
                }
                $widest = self::widestFootprint($boxes);
                $hint = $hintMode === 'exact' ? $widest : ($hintMode === 'one' ? 1 : $widest * 100);
                $split = max(1, intdiv($count, 2));
                $graph = new ContactGraph(array_slice($boxes, 0, $split), $hint);
                foreach (array_slice($boxes, $split) as $box) {
                    $graph = $graph->withBox($box);
                }
                self::assertSame(
                    self::edges(new ContactGraph($boxes), $count),
                    self::edges($graph, $count),
                    "{$name} trial {$trial}",
                );
            }
        }
    }

    public static function testABoxWiderThanTheHintRebuildsAndIsStillCorrect(): void
    {
        // The hint is an optimisation; being wrong about it may cost time, never an
        // answer. ContactLevelIndex is only sound while its cell is at least the largest
        // footprint it indexes or is queried with, so a box exceeding the cell has to be
        // met with a rebuild -- this asserts both halves: that the rebuild happens, and
        // that the result is the one the full build gives.
        $small = [
            new AxisAlignedBox(new Point(0, 0, 0), new Dimensions(new Length(10), new Length(10), new Length(10))),
            new AxisAlignedBox(new Point(10, 0, 0), new Dimensions(new Length(10), new Length(10), new Length(10))),
        ];
        $wide = new AxisAlignedBox(new Point(0, 0, 10), new Dimensions(new Length(40), new Length(10), new Length(10)));
        $base = new ContactGraph($small);
        $graph = $base->withBox($wide);
        $indexes = array_map(static fn($edge) => $edge->index, $graph->supporters(2));
        self::assertSame([0, 1], $indexes);
        $whole = $small;
        $whole[] = $wide;
        self::assertSame(self::edges(new ContactGraph($whole), 3), self::edges($graph, 3));
    }

    public static function testAnAppendedBoxLandsLastInTheListsItJoins(): void
    {
        // The new box always takes the highest index, so appending it to an existing
        // supporter list keeps that list ascending -- but only because it is appended and
        // not inserted, which is the kind of detail a from-scratch comparison on random
        // scenes can miss when no scene happens to produce the collision.
        $scene = [
            new AxisAlignedBox(new Point(0, 0, 0), new Dimensions(new Length(10), new Length(10), new Length(10))),
            new AxisAlignedBox(new Point(0, 0, 10), new Dimensions(new Length(20), new Length(10), new Length(10))),
            new AxisAlignedBox(new Point(10, 0, 0), new Dimensions(new Length(10), new Length(10), new Length(10))),
        ];
        $graph = (new ContactGraph($scene, 20))->withBox(
            new AxisAlignedBox(new Point(0, 0, 20), new Dimensions(new Length(10), new Length(10), new Length(10))),
        );
        $indexes = array_map(static fn($edge) => $edge->index, $graph->supporters(1));
        self::assertSame([0, 2], $indexes);
        self::assertSame([3], $graph->children(1));
    }

    public static function testAppendingAUnitMatchesBuildingTheSupportGraphAtOnce(): void
    {
        for ($seed = 0; $seed < 40; $seed++) {
            mt_srand(4000 + $seed);
            $boxes = self::touchingScene(mt_rand(2, 12));
            $units = [];
            foreach ($boxes as $i => $box) { $units[] = new LoadUnit($box, 100, null, null, "u{$i}"); }
            $hint = self::widestFootprint($boxes);
            $graph = new LoadSupportGraph(array_slice($units, 0, 1), $hint);
            foreach (array_slice($units, 1) as $unit) { $graph = $graph->withUnit($unit, $hint); }
            self::assertSame(
                self::edges(new LoadSupportGraph($units), count($units)),
                self::edges($graph, count($units)),
                "seed {$seed}",
            );
        }
    }

    public static function testANestingUnitIsMetWithAFullRebuild(): void
    {
        // Nesting is deliberately excluded from the delta, and the exclusion is
        // load-bearing: a nesting predecessor replaces the face edges of its whole
        // column, so one new unit can rewrite edges arbitrarily far from itself and the
        // locality the delta rests on is simply not there. The rebuild is the correct
        // answer, so assert it is taken -- fresh edge objects, not the base's.
        $lower = self::unit(0, 0, 0, 10, 10, 10, label: 'a', nestingItemId: 'tray', nestingHeightTicks: 4);
        $upper = self::unit(0, 0, 6, 10, 10, 10, label: 'b', nestingItemId: 'tray', nestingHeightTicks: 4);
        $arriving = self::unit(0, 0, 12, 10, 10, 10, label: 'c', nestingItemId: 'tray', nestingHeightTicks: 4);
        $base = new LoadSupportGraph([$lower, $upper]);
        $graph = $base->withUnit($arriving);
        self::assertNotSame($base->supporters(1)[0], $graph->supporters(1)[0]);
        self::assertSame(
            self::edges(new LoadSupportGraph([$lower, $upper, $arriving]), 3),
            self::edges($graph, 3),
        );
    }

    // --------------------------------------------------------- stacked-item counting

    public static function testAThreeHighColumnCountsTransitivelyNotJustTheNeighbour(): void
    {
        $column = [
            self::unit(0, 0, 0, 10, 10, 10, label: 'bottom'),
            self::unit(0, 0, 10, 10, 10, 10, label: 'middle'),
            self::unit(0, 0, 20, 10, 10, 10, label: 'top'),
        ];
        self::assertSame([2, 1, 0], LoadCalculator::stackedCounts($column));
    }

    public static function testItemsSideBySideDoNotCountTowardEachOther(): void
    {
        $sideBySide = [
            self::unit(0, 0, 0, 10, 10, 10, label: 'left'),
            self::unit(10, 0, 0, 10, 10, 10, label: 'right'),
        ];
        self::assertSame([0, 0], LoadCalculator::stackedCounts($sideBySide));
    }

    public static function testAnItemRestingOnTwoSupportersCountsOnceForEach(): void
    {
        $supportersAndLoad = [
            self::unit(0, 0, 0, 10, 10, 10, label: 'narrow'),
            self::unit(10, 0, 0, 30, 10, 10, label: 'wide'),
            self::unit(0, 0, 10, 40, 10, 10, label: 'beam'),
        ];
        self::assertSame([1, 1, 0], LoadCalculator::stackedCounts($supportersAndLoad));
    }

    public static function testNoStackLimitsMeansNoCheckToRun(): void
    {
        $column = [
            self::unit(0, 0, 0, 10, 10, 10, label: 'a'),
            self::unit(0, 0, 10, 10, 10, 10, label: 'b'),
        ];
        self::assertNull(LoadCalculator::stackLimitExceeded($column));
    }

    public static function testTheFirstUnitOverItsStackLimitIsReportedByName(): void
    {
        $column = [
            self::unit(0, 0, 0, 10, 10, 10, stackLimit: 1, label: 'base'),
            self::unit(0, 0, 10, 10, 10, 10, label: 'middle'),
            self::unit(0, 0, 20, 10, 10, 10, label: 'top'),
        ];
        self::assertSame(['stacked_item_limit_exceeded', 'base'], LoadCalculator::stackLimitExceeded($column));
    }

    public static function testAStackLimitMetExactlyIsNotExceeded(): void
    {
        $column = [
            self::unit(0, 0, 0, 10, 10, 10, stackLimit: 2, label: 'base'),
            self::unit(0, 0, 10, 10, 10, 10, label: 'middle'),
            self::unit(0, 0, 20, 10, 10, 10, label: 'top'),
        ];
        self::assertNull(LoadCalculator::stackLimitExceeded($column));
    }

    public static function testAThirdItemOverTheStackLimitIsRefused(): void
    {
        $base = self::placed(self::instance('base', options: ['maxStackedItems' => 1]));
        $middle = self::placed(self::instance('middle'), z: 160_000);
        $top = self::instance('top');
        $result = (new TopLoadConstraint())->evaluate(self::context($top, z: 320_000, placements: [$base, $middle]));
        self::assertFalse($result->allowed);
        self::assertSame('stacked_item_limit_exceeded', $result->code);
    }

    public static function testWithinTheStackLimitIsAllowed(): void
    {
        $base = self::placed(self::instance('base', options: ['maxStackedItems' => 2]));
        $middle = self::placed(self::instance('middle'), z: 160_000);
        $top = self::instance('top');
        self::assertTrue((new TopLoadConstraint())->evaluate(
            self::context($top, z: 320_000, placements: [$base, $middle]),
        )->allowed);
    }

    // --------------------------------------------------- stack density

    private const ONE_METRE_TICKS = 1000 * self::MM;

    public static function testSquareMetreTicksIsDerivedFromTheLengthScale(): void
    {
        self::assertSame(self::ONE_METRE_TICKS ** 2, LoadCalculator::SQUARE_METRE_TICKS);
    }

    public static function testNoDensityLimitMeansNoCheckToRun(): void
    {
        $column = [self::unit(0, 0, 0, 10, 10, 10, weight: 10 ** 9, label: 'a')];
        self::assertNull(LoadCalculator::stackDensityExceeded($column, null));
    }

    public static function testAUnitBearingExactlyItsDensityLimitIsNotExceeded(): void
    {
        // A one-square-metre footprint collapses the cross-multiplication to a plain
        // integer comparison, keeping the boundary tick easy to state and verify.
        $floor = self::unit(0, 0, 0, self::ONE_METRE_TICKS, self::ONE_METRE_TICKS, 10, weight: 500, label: 'floor');
        self::assertNull(LoadCalculator::stackDensityExceeded([$floor], 500));
    }

    public static function testAUnitOneTickOverItsDensityLimitIsReportedByName(): void
    {
        $floor = self::unit(0, 0, 0, self::ONE_METRE_TICKS, self::ONE_METRE_TICKS, 10, weight: 501, label: 'floor');
        self::assertSame(['stack_density_exceeded', 'floor'], LoadCalculator::stackDensityExceeded([$floor], 500));
    }

    public static function testStackDensityIsCheckedCumulativelyNotJustTheDirectNeighbour(): void
    {
        $base = self::unit(0, 0, 0, self::ONE_METRE_TICKS, self::ONE_METRE_TICKS, 10, weight: 100, label: 'base');
        $top = self::unit(0, 0, 10, self::ONE_METRE_TICKS, self::ONE_METRE_TICKS, 10, weight: 450, label: 'top');
        self::assertSame(['stack_density_exceeded', 'base'], LoadCalculator::stackDensityExceeded([$base, $top], 500));
    }

    public static function testASmallFootprintReceivingALargeLoadFromAboveIsExceeded(): void
    {
        // The whole point of a density limit over a flat per-item one: the same
        // absolute load becomes crushing once concentrated onto less area. `leg`'s
        // own weight is negligible; it is purely `block`'s weight, transmitted
        // through a tenth of the footprint, that crushes it.
        $leg = self::unit(0, 0, 0, intdiv(self::ONE_METRE_TICKS, 10), self::ONE_METRE_TICKS, 10, weight: 1, label: 'leg');
        $block = self::unit(0, 0, 10, self::ONE_METRE_TICKS, self::ONE_METRE_TICKS, 10, weight: 100, label: 'block');
        self::assertSame(['stack_density_exceeded', 'leg'], LoadCalculator::stackDensityExceeded([$leg, $block], 500));
    }

    public static function testADensityLimitedContainerRefusesACrushingCandidate(): void
    {
        $denseBox = Container::create(
            'dense', new Dimensions(new Length(self::ONE_METRE_TICKS), new Length(self::ONE_METRE_TICKS), new Length(self::ONE_METRE_TICKS)),
            maxStackDensity: new Weight(500),
        );
        $base = self::placed(self::instance('base', 1000, 1000, 10, ['weight' => new Weight(400)]));
        $heavy = self::instance('heavy', 1000, 1000, 10, ['weight' => new Weight(150)]);
        $result = (new TopLoadConstraint())->evaluate(self::context($heavy, z: 160_000, placements: [$base], container: $denseBox));
        self::assertFalse($result->allowed);
        self::assertSame('stack_density_exceeded', $result->code);
    }

    public static function testADensityLimitedContainerAllowsACandidateWithinTheLimit(): void
    {
        $denseBox = Container::create(
            'dense', new Dimensions(new Length(self::ONE_METRE_TICKS), new Length(self::ONE_METRE_TICKS), new Length(self::ONE_METRE_TICKS)),
            maxStackDensity: new Weight(500),
        );
        $base = self::placed(self::instance('base', 1000, 1000, 10, ['weight' => new Weight(400)]));
        $light = self::instance('light', 1000, 1000, 10, ['weight' => new Weight(100)]);
        $result = (new TopLoadConstraint())->evaluate(self::context($light, z: 160_000, placements: [$base], container: $denseBox));
        self::assertTrue($result->allowed);
    }

    // ------------------------------------------------------------------------ floor

    public static function testFloorOnlyItemsAreKeptOnTheFloor(): void
    {
        $grounded = self::instance('g', options: ['mustBeOnFloor' => true]);
        self::assertTrue((new FloorConstraint())->evaluate(self::context($grounded, 0))->allowed);
        $result = (new FloorConstraint())->evaluate(self::context($grounded, 10 * self::MM));
        self::assertFalse($result->allowed);
        self::assertSame('must_be_on_floor', $result->code);
    }

    public static function testOrdinaryItemsMayRestAtAnyHeight(): void
    {
        self::assertTrue((new FloorConstraint())->evaluate(self::context(self::instance('a'), 10 * self::MM))->allowed);
    }

    // ----------------------------------------------------------------- compatibility

    public static function testIncompatibilityIsRefusedInBothDirections(): void
    {
        // Declared on either side; a candidate must not have to repeat its neighbour's rule.
        $food = self::instance('food', options: ['tags' => ['food']]);
        $chemical = self::instance('chem', options: ['incompatibleTags' => ['food']]);

        $outward = (new CompatibilityConstraint())->evaluate(self::context($chemical, 0, [self::placed($food)]));
        $inward = (new CompatibilityConstraint())->evaluate(self::context($food, 0, [self::placed($chemical)]));
        self::assertFalse($outward->allowed);
        self::assertSame('incompatible_items', $outward->code);
        self::assertFalse($inward->allowed);
        self::assertSame('incompatible_items', $inward->code);
    }

    public static function testUntaggedItemsShareAContainerFreely(): void
    {
        self::assertTrue((new CompatibilityConstraint())
            ->evaluate(self::context(self::instance('a'), 0, [self::placed(self::instance('b'))]))->allowed);
    }

    public static function testUnrelatedTagsDoNotConflict(): void
    {
        $one = self::instance('a', options: ['tags' => ['dry']]);
        $other = self::instance('b', options: ['tags' => ['cold']]);
        self::assertTrue((new CompatibilityConstraint())->evaluate(self::context($one, 0, [self::placed($other)]))->allowed);
    }

    // --------------------------------------------------------------- tag counts

    public static function testNoTagLimitsMeansNoCheck(): void
    {
        $box = Container::create('box', Dimensions::mm(100, 100, 100), tagLimits: []);
        self::assertTrue((new TagCountConstraint())
            ->evaluate(self::context(self::instance('a', options: ['tags' => ['hazmat']]), container: $box))->allowed);
    }

    public static function testAThirdItemOfALimitedTagIsRefused(): void
    {
        $limited = Container::create('box', Dimensions::mm(100, 100, 100), tagLimits: ['hazmat' => 2]);
        $alreadyTwo = [
            self::placed(self::instance('a', options: ['tags' => ['hazmat']])),
            self::placed(self::instance('b', options: ['tags' => ['hazmat']])),
        ];
        $result = (new TagCountConstraint())->evaluate(
            self::context(self::instance('c', options: ['tags' => ['hazmat']]), container: $limited, placements: $alreadyTwo),
        );
        self::assertFalse($result->allowed);
        self::assertSame('tag_count_exceeded', $result->code);
    }

    public static function testTheSecondItemOfALimitOfTwoIsAllowed(): void
    {
        $limited = Container::create('box', Dimensions::mm(100, 100, 100), tagLimits: ['hazmat' => 2]);
        $oneAlready = [self::placed(self::instance('a', options: ['tags' => ['hazmat']]))];
        self::assertTrue((new TagCountConstraint())->evaluate(
            self::context(self::instance('b', options: ['tags' => ['hazmat']]), container: $limited, placements: $oneAlready),
        )->allowed);
    }

    public static function testAnUntaggedItemIsNeverLimited(): void
    {
        $limited = Container::create('box', Dimensions::mm(100, 100, 100), tagLimits: ['hazmat' => 1]);
        $alreadyOne = [self::placed(self::instance('a', options: ['tags' => ['hazmat']]))];
        self::assertTrue((new TagCountConstraint())
            ->evaluate(self::context(self::instance('b'), container: $limited, placements: $alreadyOne))->allowed);
    }

    public static function testTheLimitAppliesOnlyToTheTagItNames(): void
    {
        $limited = Container::create('box', Dimensions::mm(100, 100, 100), tagLimits: ['hazmat' => 1]);
        $alreadyOne = [self::placed(self::instance('a', options: ['tags' => ['hazmat']]))];
        self::assertTrue((new TagCountConstraint())->evaluate(
            self::context(self::instance('b', options: ['tags' => ['fragile']]), container: $limited, placements: $alreadyOne),
        )->allowed);
    }

    // ------------------------------------------------------- container eligibility

    public static function testAnItemWithNoEligibilityTagsMayGoAnywhere(): void
    {
        self::assertTrue((new ContainerEligibilityConstraint())
            ->evaluate(self::context(self::instance('a')))->allowed);
    }

    public static function testAnIneligibleContainerIsRefused(): void
    {
        $refrigerated = Container::create('reefer', Dimensions::mm(100, 100, 100), tags: ['refrigerated']);
        $ordinary = Container::create('dry-van', Dimensions::mm(100, 100, 100));
        $item = self::instance('perishable', options: ['eligibleContainerTags' => ['refrigerated']]);

        $result = (new ContainerEligibilityConstraint())->evaluate(self::context($item, container: $ordinary));
        self::assertFalse($result->allowed);
        self::assertSame('container_ineligible', $result->code);
        self::assertTrue((new ContainerEligibilityConstraint())
            ->evaluate(self::context($item, container: $refrigerated))->allowed);
    }

    // ---------------------------------------------------------------------- support

    public static function testAnythingOnTheFloorIsFullySupported(): void
    {
        self::assertTrue((new SupportConstraint(1.0))->evaluate(self::context(self::instance('a'), 0))->allowed);
    }

    public static function testNoRequiredRatioMeansNoCheck(): void
    {
        self::assertTrue((new SupportConstraint(0.0))->evaluate(self::context(self::instance('a'), 10 * self::MM))->allowed);
    }

    public static function testTheSupportBoundaryIsExactToTheTick(): void
    {
        // A footprint of 75% is enough for a 0.75 requirement; one tick less is not.
        foreach ([[1_200_000, true], [1_199_999, false]] as [$shelfWidth, $allowed]) {
            $shelf = Item::create('shelf', new Dimensions(new Length($shelfWidth), new Length(1_600_000), new Length(160_000)));
            [$shelfInstance] = $shelf->instances();
            $candidate = self::instance('c', 100, 100, 10, ['minimumSupportRatio' => 0.75]);

            $result = (new SupportConstraint(0.0))
                ->evaluate(self::context($candidate, 10 * self::MM, [self::placed($shelfInstance)]));
            self::assertSame($allowed, $result->allowed, "shelf width {$shelfWidth}");
            if (!$allowed) {
                self::assertSame('insufficient_support', $result->code);
            }
        }
    }

    public static function testTheStricterOfTheGlobalAndPerItemRatioWins(): void
    {
        [$shelf] = Item::create('shelf', Dimensions::mm(60, 100, 10))->instances();
        $candidate = self::instance('c', 100, 100, 10);
        $resting = self::context($candidate, 10 * self::MM, [self::placed($shelf)]);

        self::assertTrue((new SupportConstraint(0.5))->evaluate($resting)->allowed);
        self::assertFalse((new SupportConstraint(0.75))->evaluate($resting)->allowed);
    }

    public static function testOnlySurfacesAtTheRestingHeightCountAsSupport(): void
    {
        // A box two levels down carries the stack, but it is not what the candidate
        // rests on.
        [$low] = Item::create('low', Dimensions::mm(100, 100, 5))->instances();
        $candidate = self::instance('c', 100, 100, 10, ['minimumSupportRatio' => 0.5]);
        $result = (new SupportConstraint(0.0))->evaluate(self::context($candidate, 10 * self::MM, [self::placed($low)]));
        self::assertFalse($result->allowed);
        self::assertSame('insufficient_support', $result->code);
    }

    // --------------------------------------------------- support polygon

    public static function testACandidateThatMeetsTheRatioButOverhangsItsCentroidStillTips(): void
    {
        // Area ratio alone is not stability: a 40% overlap on one side clears a 0.3
        // ratio requirement while leaving the candidate's own centroid unsupported.
        [$shelf] = Item::create('shelf', Dimensions::mm(40, 100, 10))->instances();
        $candidate = self::instance('c', 100, 100, 10, ['minimumSupportRatio' => 0.3]);
        $result = (new SupportConstraint(0.0))->evaluate(self::context($candidate, 10 * self::MM, [self::placed($shelf)]));
        self::assertFalse($result->allowed);
        self::assertSame('centre_of_gravity_unsupported', $result->code);
    }

    public static function testTwoSupportersBracketingTheCentroidAreAllowed(): void
    {
        // Neither rail alone reaches the middle, but together their hull spans
        // across the candidate's centroid -- the union, not any single supporter,
        // is what matters.
        [$left] = Item::create('left', Dimensions::mm(10, 100, 10))->instances();
        [$right] = Item::create('right', Dimensions::mm(10, 100, 10))->instances();
        $candidate = self::instance('c', 100, 100, 10, ['minimumSupportRatio' => 0.15]);
        $result = (new SupportConstraint(0.0))->evaluate(self::context(
            $candidate, 10 * self::MM, [self::placed($left), self::placed($right, 90 * self::MM)],
        ));
        self::assertTrue($result->allowed);
    }

    public static function testNoTippingCheckRunsWhenNoSupportRatioIsRequired(): void
    {
        // No new rejection code appears for a caller who never asked for support
        // checking -- the tipping check shares the ratio check's gate, not a new one.
        [$shelf] = Item::create('shelf', Dimensions::mm(40, 100, 10))->instances();
        $candidate = self::instance('c', 100, 100, 10);
        $result = (new SupportConstraint(0.0))->evaluate(self::context($candidate, 10 * self::MM, [self::placed($shelf)]));
        self::assertTrue($result->allowed);
    }

    public static function testInactiveAndFloorSupportChecksDoNotInspectPlacementSurfaces(): void
    {
        $probe = new class {
            public int $envelopeBoxCalls = 0;
            public function envelopeBox(): AxisAlignedBox
            {
                $this->envelopeBoxCalls++;
                throw new \RuntimeException('an O(1) support verdict must not inspect placements');
            }
        };
        $inactiveItem = self::instance('inactive');
        $inactive = new SupportConstraint(0.0);
        $ordinaryVerdict = $inactive->evaluate(self::context($inactiveItem, 10 * self::MM));
        $probedVerdict = $inactive->evaluate(self::context($inactiveItem, 10 * self::MM, [$probe]));

        self::assertFalse($inactive->canReject($inactiveItem));
        self::assertSame($ordinaryVerdict->allowed, $probedVerdict->allowed);
        self::assertSame(0, $probe->envelopeBoxCalls);

        $floorItem = self::instance('floor', options: ['minimumSupportRatio' => 1.0]);
        self::assertTrue((new SupportConstraint(0.0))->canReject($floorItem));
        self::assertTrue((new SupportConstraint(0.0))->evaluate(self::context($floorItem, 0, [$probe]))->allowed);
        self::assertSame(0, $probe->envelopeBoxCalls);
    }

    public static function testCallerSuppliedGlobalSupportMinimumReportsThatItCanReject(): void
    {
        $item = self::instance('ordinary');
        self::assertFalse((new SupportConstraint(0.0))->canReject($item));
        self::assertTrue((new SupportConstraint(0.5))->canReject($item));
        self::assertTrue((new SupportConstraint(0.0))->canReject(
            self::instance('single', options: ['groundContactRule' => 'single']),
        ));
    }

    // ----------------------------------------------------- ground-contact rules

    public static function testARatioTheCornerRuleRejectsThatARatioAccepts(): void
    {
        // A ratio cannot express the corner rule: 64% coverage concentrated in the
        // middle clears a 50% requirement but touches none of the four base corners.
        [$plate] = Item::create('plate', Dimensions::mm(80, 80, 10))->instances();

        $ratioOnly = self::instance('ratio-only', 100, 100, 10, ['minimumSupportRatio' => 0.5]);
        self::assertTrue((new SupportConstraint(0.0))->evaluate(
            self::context($ratioOnly, 10 * self::MM, [self::placed($plate, 10 * self::MM, 10 * self::MM)]),
        )->allowed);

        $cornerChecked = self::instance('corner-checked', 100, 100, 10, ['minimumSupportRatio' => 0.5, 'groundContactRule' => 'covered']);
        $result = (new SupportConstraint(0.0))->evaluate(
            self::context($cornerChecked, 10 * self::MM, [self::placed($plate, 10 * self::MM, 10 * self::MM)]),
        );
        self::assertFalse($result->allowed);
        self::assertSame('ground_contact_violation', $result->code);
    }

    public static function testFreeNeverChecksGroundContact(): void
    {
        $airborne = self::instance('a', options: ['groundContactRule' => 'free']);
        self::assertTrue((new SupportConstraint(0.0))->evaluate(self::context($airborne, 10 * self::MM))->allowed);
    }

    public static function testCoveredIsSatisfiedWhenSupportersCoverAllFourCorners(): void
    {
        [$left] = Item::create('left', Dimensions::mm(50, 100, 10))->instances();
        [$right] = Item::create('right', Dimensions::mm(50, 100, 10))->instances();
        $candidate = self::instance('c', 100, 100, 10, ['groundContactRule' => 'covered']);
        $resting = self::context($candidate, 10 * self::MM, [self::placed($left, 0, 0), self::placed($right, 50 * self::MM, 0)]);
        self::assertTrue((new SupportConstraint(0.0))->evaluate($resting)->allowed);
    }

    public static function testSingleRejectsACandidateSplitAcrossTwoSupporters(): void
    {
        [$left] = Item::create('left', Dimensions::mm(50, 100, 10))->instances();
        [$right] = Item::create('right', Dimensions::mm(50, 100, 10))->instances();
        $candidate = self::instance('c', 100, 100, 10, ['groundContactRule' => 'single']);
        $resting = self::context($candidate, 10 * self::MM, [self::placed($left, 0, 0), self::placed($right, 50 * self::MM, 0)]);
        $result = (new SupportConstraint(0.0))->evaluate($resting);
        self::assertFalse($result->allowed);
        self::assertSame('ground_contact_violation', $result->code);
    }

    public static function testSingleAcceptsACandidateRestingOnExactlyOneSupporter(): void
    {
        [$base] = Item::create('base', Dimensions::mm(100, 100, 10))->instances();
        $candidate = self::instance('c', 100, 100, 10, ['groundContactRule' => 'single']);
        self::assertTrue((new SupportConstraint(0.0))
            ->evaluate(self::context($candidate, 10 * self::MM, [self::placed($base)]))->allowed);
    }

    public static function testMultipleRejectsACandidateRestingOnExactlyOneSupporter(): void
    {
        [$base] = Item::create('base', Dimensions::mm(100, 100, 10))->instances();
        $candidate = self::instance('c', 100, 100, 10, ['groundContactRule' => 'multiple']);
        $result = (new SupportConstraint(0.0))->evaluate(self::context($candidate, 10 * self::MM, [self::placed($base)]));
        self::assertFalse($result->allowed);
        self::assertSame('ground_contact_violation', $result->code);
    }

    public static function testMultipleAcceptsACandidateSplitAcrossTwoSupporters(): void
    {
        [$left] = Item::create('left', Dimensions::mm(50, 100, 10))->instances();
        [$right] = Item::create('right', Dimensions::mm(50, 100, 10))->instances();
        $candidate = self::instance('c', 100, 100, 10, ['groundContactRule' => 'multiple']);
        $resting = self::context($candidate, 10 * self::MM, [self::placed($left, 0, 0), self::placed($right, 50 * self::MM, 0)]);
        self::assertTrue((new SupportConstraint(0.0))->evaluate($resting)->allowed);
    }

    public static function testAFloorRestingItemSatisfiesEveryGroundContactRule(): void
    {
        foreach (['free', 'covered', 'single', 'multiple'] as $rule) {
            $candidate = self::instance('c', 100, 100, 10, ['groundContactRule' => $rule]);
            self::assertTrue((new SupportConstraint(0.0))->evaluate(self::context($candidate, 0))->allowed, $rule);
        }
    }

    // --------------------------------------------------------------------- bearing

    public static function testNothingMayBeStackedOnANonStackableItem(): void
    {
        $base = self::instance('base', options: ['stackable' => false]);
        $result = (new TopLoadConstraint())
            ->evaluate(self::context(self::instance('c'), 10 * self::MM, [self::placed($base)]));
        self::assertFalse($result->allowed);
        self::assertSame('non_stackable', $result->code);
    }

    public static function testANonStackableItemMayNotBeSlidUnderneathAnother(): void
    {
        // Stacking is a relation, not a direction. Rejecting only the downward case let
        // a solver reach the same forbidden arrangement by placing the two in the other
        // order.
        $above = self::placed(self::instance('above'), 0, 0, 10 * self::MM);
        $sliding = self::instance('under', options: ['stackable' => false]);
        $result = (new TopLoadConstraint())->evaluate(self::context($sliding, 0, [$above]));
        self::assertFalse($result->allowed);
        self::assertSame('non_stackable', $result->code);
    }

    public static function testANonStackableItemBesideAnotherIsFine(): void
    {
        $beside = self::placed(self::instance('beside'), 10 * self::MM);
        $candidate = self::instance('a', options: ['stackable' => false]);
        self::assertTrue((new TopLoadConstraint())->evaluate(self::context($candidate, 0, [$beside]))->allowed);
    }

    public static function testACandidateThatWouldCrushTheStackBelowIsRefused(): void
    {
        $base = self::placed(self::instance('base', options: ['weight' => '1 kg', 'maxTopLoad' => '1.5 kg']));
        $heavy = self::instance('heavy', options: ['weight' => '2 kg']);
        $result = (new TopLoadConstraint())->evaluate(self::context($heavy, 10 * self::MM, [$base]));
        self::assertFalse($result->allowed);
        self::assertSame('top_load_exceeded', $result->code);
    }

    public static function testANestedCandidateCarriesItsLoadThroughTheNestedColumn(): void
    {
        [$base, $middle, $top] = Item::create(
            'crate', Dimensions::mm(100, 100, 100), '1 kg', quantity: 3,
            maxTopLoad: Weight::of('1.5', 'kg'), nestingHeight: Length::mm(40),
        )->instances();
        $step = Length::mm(60)->ticks;
        $result = (new TopLoadConstraint())->evaluate(self::context(
            $top,
            2 * $step,
            [self::placed($base), self::placed($middle, z: $step)],
        ));

        self::assertFalse($result->allowed);
        self::assertSame('top_load_exceeded', $result->code);
    }

    public static function testACandidateWithinTheBearingLimitIsAllowed(): void
    {
        $base = self::placed(self::instance('base', options: ['weight' => '1 kg', 'maxTopLoad' => '2 kg']));
        $light = self::instance('light', options: ['weight' => '1 kg']);
        self::assertTrue((new TopLoadConstraint())->evaluate(self::context($light, 10 * self::MM, [$base]))->allowed);
    }

    public static function testTheBearingCheckIsSkippedWhenNothingCanRefuseALoad(): void
    {
        // A whole-stack walk per candidate dominated the search on large orders.
        $base = self::placed(self::instance('base', options: ['stackable' => false]));
        self::assertTrue((new TopLoadConstraint())
            ->evaluate(self::context(self::instance('c'), 10 * self::MM, [$base], stackSensitive: false))->allowed);
    }

    // ------------------------------------------------------------- axle load

    private static function axledBox(int $frontMm, int $rearMm, ?Weight $frontLimit = null, ?Weight $rearLimit = null, int $lengthMm = 1000): Container
    {
        return Container::create(
            'axled', Dimensions::mm($lengthMm, 100, 100),
            axles: [new Axle(Length::mm($frontMm), $frontLimit), new Axle(Length::mm($rearMm), $rearLimit)],
        );
    }

    public static function testNoAxlesMeansNoCheckToRun(): void
    {
        $plain = Container::create('plain', Dimensions::mm(100, 100, 100));
        self::assertTrue((new AxleLoadConstraint())
            ->evaluate(self::context(self::instance('c', options: ['weight' => '1000 kg']), container: $plain))->allowed);
    }

    public static function testACandidateCentredBetweenTheAxlesSplitsEvenly(): void
    {
        // A candidate spanning the whole container has its own centre at 500mm,
        // exactly midway between axles at 100mm and 900mm -- each axle bears half
        // of 800kg.
        $box = self::axledBox(100, 900, Weight::of(500, 'kg'), Weight::of(500, 'kg'));
        $candidate = self::instance('c', 1000, 100, 100, ['weight' => '800 kg']);
        self::assertTrue((new AxleLoadConstraint())->evaluate(self::context($candidate, container: $box))->allowed);
    }

    public static function testACandidateThatWouldOverloadTheFrontAxleIsRefused(): void
    {
        $box = self::axledBox(100, 900, Weight::of(399, 'kg'), Weight::of(500, 'kg'));
        $candidate = self::instance('c', 1000, 100, 100, ['weight' => '800 kg']);
        $result = (new AxleLoadConstraint())->evaluate(self::context($candidate, container: $box));
        self::assertFalse($result->allowed);
        self::assertSame('axle_overloaded', $result->code);
    }

    public static function testAnExistingPlacementAndANewCandidateAreBothWeighed(): void
    {
        // Two 400kg items, each spanning the whole container (so each alone splits
        // 200/200 across axles at 100mm/900mm) -- together they still split evenly,
        // and the check must see both, not only the new candidate.
        $box = self::axledBox(100, 900, Weight::of(400, 'kg'), Weight::of(400, 'kg'));
        $alreadyThere = self::placed(self::instance('base', 1000, 100, 100, ['weight' => '400 kg']));
        $candidate = self::instance('c', 1000, 100, 100, ['weight' => '400 kg']);
        self::assertTrue((new AxleLoadConstraint())
            ->evaluate(self::context($candidate, placements: [$alreadyThere], container: $box))->allowed);
    }

    // ---------------------------------------------- route unloading order

    public static function testALaterStopRestingOnAnEarlierOneIsRefused(): void
    {
        $base = self::placed(self::instance('first-stop', options: ['stopIndex' => 0]));
        $later = self::instance('last-stop', options: ['stopIndex' => 1]);
        $result = (new RouteOrderConstraint())->evaluate(self::context($later, 160_000, [$base]));
        self::assertFalse($result->allowed);
        self::assertSame('unloading_order_violation', $result->code);
    }

    public static function testAnEarlierStopRestingOnALaterOneIsAllowed(): void
    {
        // The whole point of the rule: stop 0 belongs on top so it comes off first.
        $base = self::placed(self::instance('last-stop', options: ['stopIndex' => 1]));
        $earlier = self::instance('first-stop', options: ['stopIndex' => 0]);
        self::assertTrue((new RouteOrderConstraint())->evaluate(self::context($earlier, 160_000, [$base]))->allowed);
    }

    public static function testItemsDueAtTheSameStopMayStackFreely(): void
    {
        $base = self::placed(self::instance('a', options: ['stopIndex' => 2]));
        $same = self::instance('b', options: ['stopIndex' => 2]);
        self::assertTrue((new RouteOrderConstraint())->evaluate(self::context($same, 160_000, [$base]))->allowed);
    }

    public static function testTheRuleReachesThroughAnIntermediary(): void
    {
        $base = self::placed(self::instance('first-stop', options: ['stopIndex' => 0]));
        $middle = self::placed(self::instance('same-stop', options: ['stopIndex' => 0]), z: 160_000);
        $later = self::instance('last-stop', options: ['stopIndex' => 1]);
        $result = (new RouteOrderConstraint())->evaluate(self::context($later, 320_000, [$base, $middle]));
        self::assertFalse($result->allowed);
        self::assertSame('unloading_order_violation', $result->code);
    }

    public static function testAnItemWithNoStopBlocksARoutedOneBeneathIt(): void
    {
        // A placement with no stopIndex is never scheduled for removal, so it stays put
        // for every stop -- exactly what the shared validator treats it as.
        $base = self::placed(self::instance('first-stop', options: ['stopIndex' => 0]));
        $rider = self::instance('fixture');
        $result = (new RouteOrderConstraint())->evaluate(self::context($rider, 160_000, [$base]));
        self::assertFalse($result->allowed);
        self::assertSame('unloading_order_violation', $result->code);
    }

    public static function testNothingBlocksAnItemThatRidesTheWholeRoute(): void
    {
        $base = self::placed(self::instance('fixture'));
        $routed = self::instance('last-stop', options: ['stopIndex' => 1]);
        self::assertTrue((new RouteOrderConstraint())->evaluate(self::context($routed, 160_000, [$base]))->allowed);
    }

    public static function testARequestWithNoRouteNeverPaysForTheWalk(): void
    {
        $base = self::placed(self::instance('a'));
        $other = self::instance('b');
        self::assertTrue((new RouteOrderConstraint())
            ->evaluate(self::context($other, 160_000, [$base], routeSensitive: false))->allowed);
    }

    public static function testSideBySideItemsDoNotConstrainEachOther(): void
    {
        $base = self::placed(self::instance('first-stop', options: ['stopIndex' => 0]));
        $beside = self::instance('last-stop', options: ['stopIndex' => 1]);
        self::assertTrue((new RouteOrderConstraint())->evaluate(self::context($beside, 0, [$base], x: 160_000))->allowed);
    }

    public static function testAnUnroutedColumnIsReportedAsNoViolation(): void
    {
        $column = [self::unit(0, 0, 0, 10, 10, 10, label: 'base'), self::unit(0, 0, 10, 10, 10, 10, label: 'top')];
        self::assertNull(LoadCalculator::routeOrderViolated($column, [INF, INF]));
    }

    public static function testTheBlockedItemAndItsBlockerAreBothNamed(): void
    {
        $column = [self::unit(0, 0, 0, 10, 10, 10, label: 'base'), self::unit(0, 0, 10, 10, 10, 10, label: 'top')];
        [$code, $detail] = LoadCalculator::routeOrderViolated($column, [0.0, 1.0]);
        self::assertSame('unloading_order_violation', $code);
        self::assertTrue(str_contains($detail, 'base'), 'the blocked item is named');
        self::assertTrue(str_contains($detail, 'top'), 'its blocker is named');
    }
}
