<?php
declare(strict_types=1);
namespace Packvium\Tests;

use Packvium\Domain\{Container, Dimensions, FixedPlacement, Item, ItemInstance, MadeToMeasure, PackingRequest, Point, Rotation, ShapeType};
use Packvium\Algorithm\{BoundOverflowException, ObjectiveBounds};
use Packvium\Config\PackingConfig;
use Packvium\Objective\DefaultSolutionScorer;
use Packvium\Packer;
use Packvium\Result\PackingStatus;
use Packvium\Support\BigInt;
use Packvium\Unit\{Length, Weight};

/**
 * This engine's lower bounds against Python's, on the shared corpus.
 *
 * `conformance/scene/objective-bounds.json` is written by Python from the golden fixtures
 * and carries, per case, the numbers the bound formulas consume and the vector Python
 * computes from them. Equality across 380 cases is the acceptance criterion, and it is a
 * stronger check than a handful of examples: a formula that is right on small inputs and
 * wrong on an unlimited quantity, an exhausted inventory or a zero-volume container fails
 * here and nowhere else.
 *
 * The scene deliberately supplies `shrinks` rather than the shape type, so the equality
 * check is about arithmetic alone. Whether *this* engine decides that flag correctly is
 * asserted separately below, against engine objects -- otherwise a port could pass by
 * consuming a flag it never computes.
 */
final class ObjectiveBoundsTest extends TestCase
{
    /** @return array{format:string,cases:list<array>} */
    private static function scene(): array
    {
        // A cross-language fixture kept one level above this package; a published copy
        // does not carry it.
        $path = dirname(__DIR__, 2) . '/conformance/scene/objective-bounds.json';
        if (!is_file($path)) {
            self::skip('the shared cross-language scene fixture is not part of this package');
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        self::assertTrue(is_array($decoded), "the scene at $path is not readable JSON");
        self::assertSame('packvium-objective-bounds/v1', $decoded['format']);
        return $decoded;
    }

    public static function testEveryCorpusCaseMatchesPythonExactly(): void
    {
        $scene = self::scene();
        // A scene that silently emptied itself would make every assertion below vacuous.
        self::assertGreaterThan(300, count($scene['cases']),
            'a scene that quietly emptied itself would make every case below vacuous');
        foreach ($scene['cases'] as $case) {
            $computed = ObjectiveBounds::fromNormalised($case['instances'], $case['containers']);
            self::assertSame(
                $case['bounds'],
                $computed,
                "bounds diverge from Python on {$case['fixture']}: expected "
                . json_encode($case['bounds']) . ', got ' . json_encode($computed),
            );
        }
    }

    /**
     * The five cases docs/OPTIMALITY-CERTIFICATES.md works by hand.
     *
     * Carried here as well as in the corpus scene because they are the ones a reader can
     * check without running anything, and because they pin the boundaries the corpus happens
     * not to contain -- a perfect fill, an exhausted cheap inventory, and no container at all.
     */
    public static function testTheWorkedExamplesFromTheDocument(): void
    {
        $instance = static fn(string $volume, int $weight, bool $shrinks = false): array
            => ['volume' => $volume, 'weight' => $weight, 'shrinks' => $shrinks];
        $container = static fn(string $inner, int $area, int $height, int $cost = 0,
            ?int $payload = null, ?int $maxItems = null, ?int $quantity = null): array
            => ['usable' => $inner, 'inner' => $inner, 'base_area' => $area,
                'height' => $height, 'payload' => $payload, 'max_items' => $maxItems,
                'quantity' => $quantity, 'cost_minor' => $cost];

        // Eight 5-tick cubes exactly fill one 10-tick cube: every bound is attained.
        self::assertSame([0, 1, 7, 0, 1000000], ObjectiveBounds::fromNormalised(
            array_fill(0, 8, $instance('125', 1)),
            [$container('1000', 100, 10, 7, null, null, 4)],
        ));

        // Volume says all ten fit; the payload ceiling says four do. The bound must take the
        // worse of the two resources, not the one checked first.
        self::assertSame([6, 1, 0, 996000, 100000], ObjectiveBounds::fromNormalised(
            array_fill(0, 10, $instance('1', 30)),
            [$container('1000', 100, 10, 0, 120, null, 1)],
        ));

        // Two containers are unavoidable, and the cheap one is out of stock after the first.
        self::assertSame([0, 2, 14, 799999, 1199999], ObjectiveBounds::fromNormalised(
            array_fill(0, 2, $instance('600', 1)),
            [$container('1000', 100, 10, 5, null, null, 1),
             $container('1000', 100, 10, 9, null, null, 3)],
        ));

        // Nesting drops the volume argument entirely rather than weakening it.
        self::assertSame([0, 1, 0, 0, 0], ObjectiveBounds::fromNormalised(
            array_fill(0, 5, $instance('900', 1, true)),
            [$container('1000', 100, 10, 0, null, null, 1)],
        ));

        // One item, no containers: no division by a capacity that does not exist.
        self::assertSame([1, 0, 0, 0, 0], ObjectiveBounds::fromNormalised(
            [$instance('10', 1)], [],
        ));
    }

    /**
     * The shape rule, asserted against engine objects rather than the scene's flag.
     *
     * found this omission with a soundness test: the design document dropped the
     * volume argument for `nesting_height` alone, and `convex_hull` and `compressible` --
     * added after the document was written -- occupy less than their bounding box
     * for exactly the same reason. A port that checks only `nesting_height` is unsound, and
     * the corpus scene cannot catch that because it supplies the flag ready-made.
     */
    public static function testEachShapeThatOccupiesLessThanItsBoxDropsTheVolumeArgument(): void
    {
        $dimensions = new Dimensions(new Length(10), new Length(10), new Length(10));
        $container = new Container('c', new Dimensions(new Length(10), new Length(10), new Length(10)),
            quantity: 1);

        $cuboid = self::instancesOf(self::item('a', $dimensions, ShapeType::RIGID_CUBOID), 5);
        $hull = self::instancesOf(self::hullItem('b', $dimensions), 5);
        $compressible = self::instancesOf(self::compressibleItem('c', $dimensions), 5);
        $nesting = self::instancesOf(self::nestingItem('d', $dimensions), 5);

        // Five 1000-tick boxes cannot fit a 1000-tick container, and the bound says so.
        self::assertSame(4, ObjectiveBounds::compute($cuboid, [$container])[0]);

        // Each of the other three occupies less than its box, so the volume argument is
        // dropped and the bound stops claiming anything must be left behind.
        foreach (['convex_hull' => $hull, 'compressible' => $compressible,
                  'nesting_height' => $nesting] as $label => $instances) {
            self::assertSame(0, ObjectiveBounds::compute($instances, [$container])[0],
                "$label must disable the volume argument");
        }
    }

    /** @return list<ItemInstance> */
    private static function instancesOf(Item $item, int $count): array
    {
        $out = [];
        for ($sequence = 1; $sequence <= $count; $sequence++) {
            $out[] = new ItemInstance($item, $sequence);
        }
        return $out;
    }

    private static function item(string $id, Dimensions $d, ShapeType $shape): Item
    {
        return new Item($id, $d, null, 1, Rotation::all(), false, true, false, null, 0.0,
            null, [], [], 0, [], null, [], null, null, null, null, $shape);
    }

    private static function hullItem(string $id, Dimensions $d): Item
    {
        $vertices = [[0, 0, 0], [10, 0, 0], [0, 10, 0], [0, 0, 10]];
        return new Item($id, $d, null, 1, Rotation::all(), false, true, false, null, 0.0,
            null, [], [], 0, [], null, [], null, null, null, null,
            ShapeType::CONVEX_HULL, $vertices);
    }

    private static function compressibleItem(string $id, Dimensions $d): Item
    {
        return new Item($id, $d, null, 1, Rotation::all(), false, true, false, null, 0.0,
            null, [], [], 0, [], null, [], null, null, null, null,
            ShapeType::COMPRESSIBLE, null, 250000, 100);
    }

    private static function nestingItem(string $id, Dimensions $d): Item
    {
        // Positional only, and the position matters: 16 is `maxStackedItems`, 17
        // `eligibleContainerTags`, 18 `groundContactRule`, and 19 the nesting height.
        return new Item($id, $d, null, 1, Rotation::all(), false, true, false, null, 0.0,
            null, [], [], 0, [], null, [], null, new Length(5));
    }

    /**
     * The degenerate geometry each key guards against, mirroring the Python suite.
     *
     * Every branch here returns a *bound*, and a bound that is wrong on a degenerate request
     * is wrong in the direction that matters -- it claims a score is unreachable that a
     * solver then reaches. The reduced form lets a container be described inconsistently on
     * purpose, which is the only way to reach a guard whose job is to never be reached.
     */
    public static function testAContainerWithNoRoomBoundsNothingRatherThanDividingByIt(): void
    {
        $item = ['volume' => '1', 'weight' => 1, 'shrinks' => false];
        // Usable volume admits the item, but the inner volume the fill ratio divides by is
        // zero. Key 3 must abstain instead of dividing.
        $noInner = ['usable' => '1000', 'inner' => '0', 'base_area' => 100, 'height' => 10,
                    'payload' => null, 'max_items' => null, 'quantity' => 1, 'cost_minor' => 0];
        self::assertSame(0, ObjectiveBounds::fromNormalised([$item], [$noInner])[3]);

        // The same for key 4 against a floor with no area and against a container with no
        // height: the volume has nowhere to stand, so the height bound claims nothing.
        $noFloor = ['usable' => '1000', 'inner' => '1000', 'base_area' => 0, 'height' => 10,
                    'payload' => null, 'max_items' => null, 'quantity' => 1, 'cost_minor' => 0];
        $noHeight = ['usable' => '1000', 'inner' => '1000', 'base_area' => 100, 'height' => 0,
                     'payload' => null, 'max_items' => null, 'quantity' => 1, 'cost_minor' => 0];
        self::assertSame(0, ObjectiveBounds::fromNormalised([$item], [$noFloor])[4]);
        self::assertSame(0, ObjectiveBounds::fromNormalised([$item], [$noHeight])[4]);

        // A container so tall that the placed volume, stood on its widest floor, reaches
        // less than one part per million of it: the height bound has nothing to claim.
        $tall = ['usable' => '1000000000', 'inner' => '1000000000', 'base_area' => 100, 'height' => 10_000_000,
                 'payload' => null, 'max_items' => null, 'quantity' => 1, 'cost_minor' => 0];
        self::assertSame(0, ObjectiveBounds::fromNormalised([$item], [$tall])[4]);
    }

    /**
     * An unlimited supply of nothing is still nothing.
     *
     * A `null` quantity means unbounded inventory, and one unlimited type that carries
     * anything makes the whole capacity unbounded. A type that carries *zero* is the
     * exception on both the volume and the numeric paths: however many of it exist, they add
     * nothing, so it is skipped rather than making the total infinite.
     */
    public static function testAnUnlimitedSupplyOfZeroCapacityAddsNothing(): void
    {
        $item = ['volume' => '1', 'weight' => 1, 'shrinks' => false];
        $empty = ['usable' => '0', 'inner' => '1000', 'base_area' => 100, 'height' => 10,
                  'payload' => 0, 'max_items' => null, 'quantity' => null, 'cost_minor' => 0];
        // Nothing fits: unlimited containers of zero usable volume strand the item.
        self::assertSame(1, ObjectiveBounds::fromNormalised([$item], [$empty])[0]);
        // And no container is opened for an item that cannot go in one.
        self::assertSame(0, ObjectiveBounds::fromNormalised([$item], [$empty])[1]);
    }

    /**
     * The declared ceiling, at the same two inputs every other engine asserts.
     *
     * A quarter of the ceiling times three is inside it; a half times three is not. The
     * limit is declared rather than inherited because this engine's native integers silently
     * become doubles on overflow, JavaScript's `Number` stops being exact past 2^53, Python's
     * are unbounded and Rust's `i128` wraps. If each refused at its own limit, a caller would
     * get a number from one engine and a refusal from another for the same request.
     */
    public static function testASumPastTheDeclaredCeilingIsRefusedRatherThanAnswered(): void
    {
        $quarter = BigInt::divide(ObjectiveBounds::MAX_BOUND_SUM, '4');
        $half = BigInt::divide(ObjectiveBounds::MAX_BOUND_SUM, '2');
        $containers = [['usable' => '1000', 'inner' => '1000', 'base_area' => 100,
                        'height' => 10, 'payload' => null, 'max_items' => null,
                        'quantity' => 1, 'cost_minor' => 0]];
        $three = static fn(string $volume): array => array_fill(0, 3,
            ['volume' => $volume, 'weight' => 1, 'shrinks' => false]);

        self::assertSame([3, 0, 0, 0, 0],
            ObjectiveBounds::fromNormalised($three($quarter), $containers));

        self::assertThrows(BoundOverflowException::class,
            static fn() => ObjectiveBounds::fromNormalised($three($half), $containers));
    }

    /** Unlimited inventory must not hide a selected-cost overflow from the precheck. */
    public static function testABoundThatCannotCrossEveryBindingExactlyIsRefused(): void
    {
        $instances = array_fill(0, 2,
            ['volume' => '1', 'weight' => 1, 'shrinks' => false]);
        $containers = [['usable' => '1', 'inner' => '1', 'base_area' => 1,
                        'height' => 1, 'payload' => null, 'max_items' => 1,
                        'quantity' => null,
                        'cost_minor' => (int)ObjectiveBounds::MAX_BOUND_VALUE]];

        self::assertThrows(BoundOverflowException::class,
            static fn() => ObjectiveBounds::fromNormalised($instances, $containers));
    }

    /**
     * Every edge of the one refusal predicate, and the fixed-placement case.
     *
     * Written from the oracle, `scripts/optimality_bounds.py`, rather than from an engine,
     * because the predicate is what arbitrates between engines (finding F4). Before
     * this engine refused `Σ usable * (quantity ?? 1)` and `Σ cost * (quantity ?? 1)` -- totals
     * that do not exist for an unlimited type -- and guarded a payload total part-way through,
     * so the order the types were listed in decided whether a request was answered.
     */
    public static function testEverySceneEdgeIsAnsweredOrRefusedAsTheOraclePredicateSays(): void
    {
        $edges = self::scene()['edges'] ?? [];
        self::assertGreaterThan(9, count($edges), 'the scene carries too few edges to hold the predicate');
        foreach ($edges as $edge) {
            foreach ([$edge['containers'], array_reverse($edge['containers'])] as $containers) {
                if ($edge['bounds'] === null) {
                    self::assertThrows(BoundOverflowException::class,
                        static fn() => ObjectiveBounds::fromNormalised($edge['instances'], $containers),
                        "{$edge['name']} must be refused");
                    continue;
                }
                self::assertSame($edge['bounds'],
                    ObjectiveBounds::fromNormalised($edge['instances'], $containers), $edge['name']);
            }
        }
    }

    /** `lowest_cost` reads the same five numbers in its own order; nothing else gets any. */
    public static function testTheRequestBoundIsStatedOnlyForTheCoveredObjectives(): void
    {
        $items = self::instancesOf(Item::create('i', Dimensions::mm(60, 50, 40)), 2);
        $crate = [Container::create('c', Dimensions::mm(100, 100, 100), costMinor: 7)];
        $default = ObjectiveBounds::forRequest($items, $crate, 'default');
        self::assertSame(ObjectiveBounds::compute($items, $crate), $default);
        [$unpacked, $count, $cost, $unused, $height] = $default;
        self::assertSame([$unpacked, $cost, $count, $unused, $height],
            ObjectiveBounds::forRequest($items, $crate, 'lowest_cost'));
        foreach (['maximum_value', 'maximum_volume', 'open_dimension_height', 'shipping_cost'] as $objective) {
            self::assertNull(ObjectiveBounds::forRequest($items, $crate, $objective), $objective);
        }

        // D13: a box cut to measure has no declared size until it is cut.
        $ticks = static fn(int $mm): array => [$mm * Length::TICKS_PER_MM, $mm * Length::TICKS_PER_MM, $mm * Length::TICKS_PER_MM];
        $machine = [new Container('m', Dimensions::mm(1510, 1510, 1510),
            madeToMeasure: new MadeToMeasure($ticks(60), $ticks(25)))];
        self::assertNull(ObjectiveBounds::forRequest($items, $machine, 'default'));
    }

    /** A custom solution scorer ranks by positions the bound knows nothing about. */
    public static function testACustomSolutionScorerGetsNoBound(): void
    {
        $request = new PackingRequest([Item::create('i', Dimensions::mm(10, 10, 10))],
            [Container::create('c', Dimensions::mm(100, 100, 100))]);
        $bound = new \ReflectionMethod(Packer::class, 'objectiveBound');
        self::assertNotNull($bound->invoke(new Packer(), $request));
        self::assertNull($bound->invoke(new Packer(solutionScorer: new DefaultSolutionScorer()), $request));
    }

    /**
     * F1, end to end: a fixed slab and a free one fill a 100 mm cube exactly.
     *
     * The bound used to see only the free slab and claim half the cube must stay empty --
     * 500000 ppm on key 3 against a packing that scores 0. Over every instance it is the
     * packing's own score: attained, and so a proof.
     */
    public static function testAFixedPlacementIsCountedByTheBound(): void
    {
        $slab = Item::create('slab', Dimensions::mm(100, 100, 50), quantity: 2);
        $cube = Container::create('cube', Dimensions::mm(100, 100, 100), quantity: 1);
        $fixed = [new FixedPlacement('slab', 'cube', new Point(0, 0, 0), Rotation::LWH, 1)];
        $result = (new Packer(PackingConfig::quality(20000)))->pack([$slab], [$cube], $fixed);
        self::assertSame([0, 1, 0, 0, 1000000], $result->score);

        $request = new PackingRequest([$slab], [$cube], $fixed);
        self::assertSame($result->score, ObjectiveBounds::forRequest($request->instances(), [$cube], 'default'));
        // Attained and complete: the one route to a proof (OPTIMALITY-GAP.md § 1.3, Q1).
        self::assertSame(PackingStatus::Optimal, $result->status);
        self::assertSame(['code' => 'proven_optimal', 'lower_bound' => [0, 1, 0, 0, 1000000]],
            $result->optimality->toArray());
        $freeOnly = ObjectiveBounds::compute(array_slice($request->instances(), 1), [$cube]);
        self::assertSame(500000, $freeOnly[3], 'the free slab alone over-states key 3');
    }

    /**
     * F5: past the ceiling the bound is withheld and the pack is answered.
     *
     * A payload total of 10^31 is finite and past the ceiling. The quality profile runs the
     * container-set beam and `exact_small` the exhaustive search, where the old per-start
     * bound called `compute` with no `catch`, so on 1.7.0 both fail the whole pack with
     * `BoundOverflowException`. Four different items, because identical ones reach the
     * lattice fast path before either search.
     */
    public static function testARequestPastTheCeilingIsStillAnsweredOnTheBeam(): void
    {
        $items = array_map(static fn(int $n): Item => Item::create("i$n",
            Dimensions::mm(30 + 7 * $n, 20 + 3 * $n, 15 + 5 * $n), 1), range(0, 3));
        $box = Container::create('c', Dimensions::mm(100, 100, 100),
            maxPayload: new Weight(1_000_000_000_000_000_000), quantity: 10_000_000_000_000);
        $instances = array_merge(...array_map(static fn(Item $i): array => $i->instances(), $items));
        self::assertNull(ObjectiveBounds::forRequest($instances, [$box], 'default'));
        foreach ([PackingConfig::quality(20000), PackingConfig::exactSmall(20000)] as $config) {
            $result = (new Packer($config))->pack($items, [$box]);
            self::assertSame([], $result->unpacked, $config->profile->value);
            // Withheld means no statement at all, and no warning (Q6).
            self::assertSame(['code' => 'not_proven'], $result->optimality->toArray());
        }
    }

    /** F6: the cheapest entries of the per-unit multiset, from one sort of the types. */
    public static function testTheCostBoundTakesTheCheapestTypesWithoutExpandingThem(): void
    {
        $cost = new \ReflectionMethod(ObjectiveBounds::class, 'costBound');
        self::assertSame(5 + 7 * 3, $cost->invoke(null, [9, 5, 7], [3, 1, null], 4));
        self::assertSame(14, $cost->invoke(null, [9, 5], [1, 1], 4));
        self::assertSame(3 * 1_000_000_000_000, $cost->invoke(null, [3], [null], 1_000_000_000_000));
    }

    /**
     * The prefix through the first missed position, the unreduced relative gap,
     * its absence on a zero bound, and nothing for a score below its bound.
     */
    public static function testAStatementStopsAtTheFirstMissedPosition(): void
    {
        $bound = [0, 2, 10, 500, 7];
        self::assertSame(['lower_bound' => $bound], ObjectiveBounds::statement($bound, $bound));
        self::assertSame([
            'lower_bound' => [0, 2, 10], 'gap_key' => 2, 'absolute_gap' => 4,
            'relative_gap' => ['numerator' => 4, 'denominator' => 10],
        ], ObjectiveBounds::statement([0, 2, 14, 0, 0], $bound));
        self::assertSame(['lower_bound' => [0], 'gap_key' => 0, 'absolute_gap' => 1],
            ObjectiveBounds::statement([1, 0, 0, 0, 0], $bound));
        self::assertNull(ObjectiveBounds::statement([0, 1, 99, 0, 0], $bound));
    }

    /**
     * Q1: `exact_small` exhausting its candidates on one container is no longer a proof. Four
     * unlike items cannot fill the cube, so the bound is missed and the result says how far.
     */
    public static function testExhaustingASearchIsNoLongerAProof(): void
    {
        $items = array_map(static fn(int $n): Item => Item::create("i$n",
            Dimensions::mm(30 + 7 * $n, 20 + 3 * $n, 15 + 5 * $n), 1), range(0, 3));
        $result = (new Packer(PackingConfig::exactSmall(20000)))->pack(
            $items, [Container::create('c', Dimensions::mm(100, 100, 100))]);
        self::assertSame([], $result->unpacked);
        self::assertSame(PackingStatus::Feasible, $result->status);
        $fact = $result->optimality->toArray();
        self::assertSame('not_proven', $fact['code']);
        self::assertTrue(array_key_exists('gap_key', $fact), 'a missed bound carries its gap');
    }

    /**
     * OPTIMALITY-GAP.md § 1.5 condition 5: a score below its bound makes the bound the
     * suspect, so the result states nothing. Unreachable through a sound bound, so the
     * packer's own rule is called with one that is not.
     */
    public static function testAScoreBelowItsBoundWithholdsTheStatement(): void
    {
        $stated = new \ReflectionMethod(Packer::class, 'statedOptimality');
        self::assertSame([PackingStatus::Feasible, null],
            $stated->invoke(null, PackingStatus::Feasible, [0, 1, 0, 0, 0], [0, 2, 0, 0, 0], true));
    }
}
