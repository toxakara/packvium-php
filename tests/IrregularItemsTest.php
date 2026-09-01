<?php
declare(strict_types=1);
namespace Packvium\Tests;

use Packvium\Constraint\LoadCalculator;
use Packvium\Domain\{AxisAlignedBox, Compression, Dimensions, HullShape, Item, Nesting, Placement, Point, Rotation, ShapeType};
use Packvium\Serialization\ArrayCodec;
use Packvium\Unit\{Length, Weight};
use Packvium\Support\SignedBigInt;

/**
 * Worked irregular-item boundaries, asserted directly on the PHP engine.
 *
 * The shared request corpus establishes cross-language equality on all three shape fixtures.
 * What these tests add is the local
 * detail those fixtures cannot show: the arithmetic that had to be built for PHP, and the
 * refusals a golden never exercises.
 */
final class IrregularItemsTest extends TestCase
{
    private const SIDE = 10 * 16000;

    public static function testTheDocumentedTenTickCubeBoundary(): void
    {
        $cube = self::cube(10);
        self::assertTrue(HullShape::collide($cube, [0, 0, 0], $cube, [9, 0, 0]), 'offset 9 overlaps');
        self::assertFalse(HullShape::collide($cube, [0, 0, 0], $cube, [10, 0, 0]), 'offset 10 only touches');
        self::assertFalse(HullShape::collide($cube, [0, 0, 0], $cube, [11, 0, 0]), 'offset 11 is clear');
    }

    public static function testVolumesOfTheShapesTheModelPins(): void
    {
        self::assertSame('1000', self::cube(10)->volume);
        self::assertSame('500', HullShape::of([[0,0,0],[10,0,0],[0,10,0],[0,0,10],[10,0,10],[0,10,10]])->volume);
        self::assertSame('8', HullShape::of([[0,0,0],[6,0,0],[0,4,0],[0,0,2]])->volume);
        self::assertSame('60', HullShape::box(3, 4, 5)->volume);
    }

    public static function testTheTriangulatedSurfaceOfAHullCloses(): void
    {
        // The check that caught a wrong volume in the Python engine: for a closed surface the
        // outward triangle area vectors sum to zero. Kept here so the port cannot regress in
        // the same way, and asserted through the volume of a shape whose value is known
        // independently -- a lattice count converging from above gave 576.12 for this hull.
        self::assertSame('576', HullShape::of(
            [[8,12,16],[16,8,8],[12,0,0],[8,8,16],[12,4,16],[8,12,8],[0,16,4]]
        )->volume);
        self::assertSame('277', HullShape::of(
            [[4,4,8],[0,0,16],[12,8,16],[8,16,0],[0,0,8],[16,12,12]]
        )->volume);
    }

    public static function testEveryRotationKeepsTheWedgeAWedge(): void
    {
        // A bare coordinate permutation would mirror the shape for three of the six
        // rotations. The signed volume of the same four vertices is the cheapest witness
        // that none of them does: a reflection flips its sign.
        $wedge = [[0,0,0],[6,0,0],[0,4,0],[0,0,2],[6,0,2],[0,4,2]];
        $reference = null;
        foreach (Rotation::all() as $rotation) {
            $turned = HullShape::rotate($wedge, $rotation);
            $signed = self::signedVolume($turned[0], $turned[1], $turned[2], $turned[3]);
            $reference ??= $signed <=> 0;
            self::assertSame($reference, $signed <=> 0, "rotation {$rotation->value} mirrored the hull");
        }
    }

    public static function testTheDocumentedCompressionExamples(): void
    {
        self::assertSame(100, Compression::effectiveHeight(100, 250000, 100, ['0', '1']));
        self::assertSame(88, Compression::effectiveHeight(100, 250000, 100, ['50', '1']));
        self::assertSame(75, Compression::effectiveHeight(100, 250000, 100, ['100', '1']));
    }

    public static function testTheCrushBoundaryIsInclusive(): void
    {
        self::assertFalse(Compression::exceeds(['100', '1'], 100), 'the limit itself is admissible');
        self::assertTrue(Compression::exceeds(['100000001', '1000000'], 100), 'one millionth over is not');
    }

    public static function testAFullyCompressibleItemStillOccupiesOneTick(): void
    {
        // Zero height would let an item escape collision and support invariants rather than
        // merely occupy very little, so the floor is part of the contract.
        self::assertSame(1, Compression::effectiveHeight(100, Compression::PPM, 10, ['10', '1']));
    }

    public static function testSignedBigIntCarriesTheSignThroughEveryOperation(): void
    {
        // `BigInt` is unsigned by design; a projection is not. Spot-checked here, and
        // cross-checked against Python on 400 random cases during the port.
        self::assertSame('-21', SignedBigInt::multiply('-3', '7'));
        self::assertSame('21', SignedBigInt::multiply('-3', '-7'));
        self::assertSame('-7', SignedBigInt::add('-10', '3'));
        self::assertSame('0', SignedBigInt::add('-3', '3'));
        self::assertSame('-13', SignedBigInt::subtract('-10', '3'));
        self::assertSame(-1, SignedBigInt::compare('-5', '2'));
    }

    public static function testAShapeRefusesDataBelongingToAnotherShape(): void
    {
        $verts = [[0,0,0],[self::SIDE,0,0],[0,self::SIDE,0],[0,0,self::SIDE]];
        self::assertRefused('hull_vertices is not part of a rigid_cuboid item',
            fn() => self::item(ShapeType::RIGID_CUBOID, $verts));
        self::assertRefused('compression_ratio is not part of a convex_hull item',
            fn() => self::item(ShapeType::CONVEX_HULL, $verts, 250000, 100));
        self::assertRefused('a convex_hull item requires hull_vertices',
            fn() => self::item(ShapeType::CONVEX_HULL));
        self::assertRefused('a compressible item requires both',
            fn() => self::item(ShapeType::COMPRESSIBLE, null, 250000));
    }

    public static function testADegenerateHullIsRefusedRatherThanPacked(): void
    {
        // A zero-volume hull is separated from everything on its own normal, so it would pass
        // through every other item and still be reported as a valid placement.
        self::assertRefused('coplanar', fn() => HullShape::of([[0,0,0],[10,0,0],[0,10,0],[10,10,0]]));
        self::assertRefused('at least 4 vertices', fn() => HullShape::of([[0,0,0],[1,0,0],[0,1,0]]));
        self::assertRefused('unique', fn() => HullShape::of([[0,0,0],[1,0,0],[0,1,0],[0,1,0]]));
    }

    private static function cube(int $side): HullShape
    {
        $vertices = [];
        foreach ([0, 1] as $x) {
            foreach ([0, 1] as $y) {
                foreach ([0, 1] as $z) {
                    $vertices[] = [$x * $side, $y * $side, $z * $side];
                }
            }
        }
        return HullShape::of($vertices);
    }

    private static function item(ShapeType $shape, ?array $vertices = null, ?int $ratio = null, ?int $limit = null): Item
    {
        return new Item('x', Dimensions::mm(10, 10, 10), null, 1, Rotation::all(), false, true, false,
            null, 0.0, null, [], [], 0, [], null, [], null, null, null, null, $shape, $vertices, $ratio, $limit);
    }

    private static function assertRefused(string $needle, callable $act): void
    {
        try {
            $act();
        } catch (\Throwable $error) {
            self::assertTrue(
                str_contains($error->getMessage(), $needle),
                "expected a refusal naming {$needle}, got: {$error->getMessage()}",
            );
            return;
        }
        self::assertTrue(false, "expected a refusal naming {$needle}, nothing was thrown");
    }

    private static function signedVolume(array $a, array $b, array $c, array $d): int
    {
        $ab = [$b[0]-$a[0], $b[1]-$a[1], $b[2]-$a[2]];
        $ac = [$c[0]-$a[0], $c[1]-$a[1], $c[2]-$a[2]];
        $ad = [$d[0]-$a[0], $d[1]-$a[1], $d[2]-$a[2]];
        $cross = [$ab[1]*$ac[2]-$ab[2]*$ac[1], $ab[2]*$ac[0]-$ab[0]*$ac[2], $ab[0]*$ac[1]-$ab[1]*$ac[0]];
        return $ad[0]*$cross[0] + $ad[1]*$cross[1] + $ad[2]*$cross[2];
    }

    // ------------------------------------------------------------ through the whole engine

    public static function testTwoComplementaryWedgesShareOneCrateSizedSpace(): void
    {
        // The whole point of the epic, as a packing outcome rather than a predicate. Their
        // bounding boxes are identical and their solids meet only on the shared face, so a
        // box-only engine fits one of them here.
        $result = self::packWedges(102);
        $placed = [];
        foreach ($result['containers'] as $container) {
            foreach ($container['placements'] as $placement) { $placed[] = $placement['item_id']; }
        }
        sort($placed);
        self::assertSame(['lower#1', 'upper#1'], $placed);
        // Two bounding boxes would fill the crate twice over; two hulls fill it exactly once.
        self::assertSame((string)((100 * 16000) ** 3), $result['containers'][0]['used_volume_ticks3']);
    }

    public static function testTouchingWedgesAreContactAndNotCollision(): void
    {
        $lower = self::placedWedge('lower', self::lowerWedge(), 0, 0, 0);
        $upper = self::placedWedge('upper', self::upperWedge(), 0, 0, 0);
        self::assertFalse(Placement::collide($lower, $upper), 'complementary halves only touch');
        self::assertTrue(Placement::collide($lower, self::placedWedge('other', self::lowerWedge(), 0, 0, 0)));
    }

    public static function testARouteBoundHullFallsBackToItsBox(): void
    {
        // `packing_sequence` reasons with box sweeps, so a hull on a route is deliberately
        // packed as its box: one conservative answer in both places beats two that disagree.
        $lower = self::placedWedge('lower', self::lowerWedge(), 0, 0, 0, 0);
        $upper = self::placedWedge('upper', self::upperWedge(), 0, 0, 0, 1);
        self::assertNull($lower->hullShape());
        self::assertTrue(Placement::collide($lower, $upper));
        self::assertSame((string)intdiv((100 * 16000) ** 3, 2), Nesting::occupiedVolume($lower));
    }

    public static function testAClearanceMakesTheHullFallBackToItsEnvelope(): void
    {
        $item = self::hullItem('lower', self::lowerWedge());
        [$instance] = $item->instances();
        $origin = new Point(0, 0, 0);
        $inflated = $item->dimensions->expand(Length::mm(1));
        $withMargin = new Placement($instance, $origin, Rotation::LWH, $item->dimensions, $origin, $inflated);
        $exact = new Placement($instance, $origin, Rotation::LWH, $item->dimensions, $origin, $item->dimensions);
        self::assertNull($withMargin->hullShape(), 'a margin around a hull is not a hull');
        self::assertNotNull($exact->hullShape());
        self::assertSame($exact->hullShape()->volume, Nesting::occupiedVolume($withMargin));
    }

    public static function testAWedgeClearsAnObstacleItsBoundingBoxOverlaps(): void
    {
        // The far quarter of the crate, which the wedge's diagonal face slopes away from:
        // their boxes overlap across the whole footprint and their solids meet on one edge.
        $post = new AxisAlignedBox(new Point(50 * 16000, 50 * 16000, 0), Dimensions::mm(50, 50, 100));
        $wedge = self::placedWedge('lower', self::lowerWedge(), 0, 0, 0);
        self::assertFalse(Placement::hitsBox($wedge, $post), 'the wedge slopes away from that quarter');
        $filling = new AxisAlignedBox(new Point(0, 0, 0), Dimensions::mm(100, 100, 100));
        self::assertTrue(Placement::hitsBox($wedge, $filling));
    }

    public static function testOccupiedVolumeFollowsTheShape(): void
    {
        $wedge = self::placedWedge('lower', self::lowerWedge(), 0, 0, 0);
        self::assertSame($wedge->hullShape()->volume, Nesting::occupiedVolume($wedge));

        $cushion = self::compressibleItem();
        [$instance] = $cushion->instances();
        $origin = new Point(0, 0, 0);
        $bare = new Placement($instance, $origin, Rotation::LWH, $cushion->dimensions, $origin, $cushion->dimensions);
        self::assertSame($cushion->dimensions->volumeString(), Nesting::occupiedVolume($bare), 'no load, no compression');

        $loaded = new Placement($instance, $origin, Rotation::LWH, $cushion->dimensions, $origin,
            $cushion->dimensions, 1.0, Weight::parse('101kg'));
        self::assertNotSame($cushion->dimensions->volumeString(), Nesting::occupiedVolume($loaded));

        // A crushed item keeps its uncompressed figure: the arrangement is already invalid and
        // the crush check reports it, so this stays one reported issue rather than two.
        $crushed = new Placement($instance, $origin, Rotation::LWH, $cushion->dimensions, $origin,
            $cushion->dimensions, 1.0, Weight::parse('500kg'));
        self::assertSame($cushion->dimensions->volumeString(), Nesting::occupiedVolume($crushed));
    }

    public static function testTheCrushBoundaryDecidesWhetherALoadMayRest(): void
    {
        // 0.01 square metres of footprint under a 100 kPa limit puts the boundary between
        // 101 kg and 102 kg -- close enough to state, far enough from a round number that a
        // floating-point shortcut would land on the wrong side of it.
        self::assertNull(self::crushVerdict('101kg'));
        self::assertSame(['crush_violation', 'cushion#1'], self::crushVerdict('102kg'));
    }

    public static function testAHullRequestSurvivesTheWire(): void
    {
        $result = ArrayCodec::pack(self::wireRequest([
            self::wireItem('lower', 'convex_hull', self::wireVertices(self::lowerWedge())),
            self::wireItem('upper', 'convex_hull', self::wireVertices(self::upperWedge())),
        ]));
        $placed = [];
        foreach ($result['containers'] as $container) {
            foreach ($container['placements'] as $placement) { $placed[] = $placement['item_id']; }
        }
        sort($placed);
        self::assertSame(['lower#1', 'upper#1'], $placed);
    }

    public static function testAHullCoordinateBelowZeroIsRefusedAtTheWire(): void
    {
        // `Length` owns non-negativity, and the refusal names it rather than producing a hull
        // mirrored into the wrong octant.
        self::assertRefused('negative', fn() => ArrayCodec::pack(self::wireRequest([
            self::wireItem('bad', 'convex_hull', [
                ['x' => '0', 'y' => '0', 'z' => '0'], ['x' => '-1', 'y' => '0', 'z' => '0'],
                ['x' => '0', 'y' => '100', 'z' => '0'], ['x' => '0', 'y' => '0', 'z' => '100'],
            ]),
        ])));
    }

    public static function testACompressibleRequestSurvivesTheWire(): void
    {
        $request = self::wireRequest([
            ['id' => 'cushion', 'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100'],
             'weight' => ['value' => '2', 'unit' => 'kg'], 'must_be_on_floor' => true,
             'shape_type' => 'compressible', 'compression_ratio' => 0.25,
             'max_compression_pressure_kpa' => 100],
            ['id' => 'brick', 'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100'],
             'weight' => ['value' => '101', 'unit' => 'kg']],
        ], 200);
        $result = ArrayCodec::pack($request);
        $placed = [];
        foreach ($result['containers'] as $container) {
            foreach ($container['placements'] as $placement) { $placed[] = $placement['item_id']; }
        }
        sort($placed);
        self::assertSame(['brick#1', 'cushion#1'], $placed);
    }

    public static function testCompressionRefreshesVolumeBeforeReserveAdmission(): void
    {
        $request = self::wireRequest([[
            'id' => 'cushion',
            'quantity' => 2,
            'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100'],
            'weight' => ['value' => '50', 'unit' => 'kg'],
            'shape_type' => 'compressible',
            'compression_ratio' => 0.25,
            'max_compression_pressure_kpa' => 100,
        ]], 200);
        $request['containers'][0]['void_fill_reserve_ratio'] = 0.061291;

        $result = ArrayCodec::pack($request);

        self::assertSame(2, $result['summary']['packed_item_count']);
        self::assertSame('7689899520000000000', $result['containers'][0]['used_volume_ticks3']);
        self::assertSame('502095872000000000', $result['containers'][0]['void_fill_reserve_ticks3']);
    }

    public static function testTheArithmeticRefusesInputItCannotAnswerFor(): void
    {
        self::assertRefused('footprint area must be positive', fn() => Compression::pressure(0, 0));
        self::assertRefused('height must be positive', fn() => Compression::effectiveHeight(0, 0, 1, ['0', '1']));
        self::assertRefused('between zero and one', fn() => Compression::effectiveHeight(1, Compression::PPM + 1, 1, ['0', '1']));
        self::assertRefused('cannot be negative', fn() => Compression::effectiveHeight(1, 0, -1, ['0', '1']));
        self::assertRefused('between zero and one', fn() => Compression::ratioToPpm(1.5));
        self::assertSame(250000, Compression::ratioToPpm(0.25));
    }

    public static function testAHullBeyondTheCoordinateBoundIsRefused(): void
    {
        // The bound is what makes every cross product provably native, so it is a refusal
        // rather than a silently wrapped answer.
        $huge = 200000000;
        self::assertRefused('within 100000000 ticks',
            fn() => HullShape::of([[0,0,0],[$huge,0,0],[0,$huge,0],[0,0,$huge]]));
    }

    // ------------------------------------------------------------ fixtures

    /** @return list<array{int,int,int}> */
    private static function lowerWedge(): array
    {
        $s = 100 * 16000;
        return [[0,0,0],[$s,0,0],[0,$s,0],[0,0,$s],[$s,0,$s],[0,$s,$s]];
    }

    /** @return list<array{int,int,int}> */
    private static function upperWedge(): array
    {
        $s = 100 * 16000;
        return [[$s,$s,0],[$s,0,0],[0,$s,0],[$s,$s,$s],[$s,0,$s],[0,$s,$s]];
    }

    private static function hullItem(string $id, array $vertices, ?int $stop = null): Item
    {
        return new Item($id, Dimensions::mm(100, 100, 100), null, 1, Rotation::all(), false, true,
            false, null, 0.0, null, [], [], 0, [], null, [], null, null, $stop, null,
            ShapeType::CONVEX_HULL, $vertices);
    }

    private static function compressibleItem(): Item
    {
        return new Item('cushion', Dimensions::mm(100, 100, 100), Weight::parse('2kg'), 1,
            Rotation::all(), false, true, true, null, 0.0, null, [], [], 0, [], null, [], null,
            null, null, null, ShapeType::COMPRESSIBLE, null, 250000, 100);
    }

    private static function placedWedge(string $id, array $vertices, int $x, int $y, int $z, ?int $stop = null): Placement
    {
        $item = self::hullItem($id, $vertices, $stop);
        [$instance] = $item->instances();
        $origin = new Point($x, $y, $z);
        return new Placement($instance, $origin, Rotation::LWH, $item->dimensions, $origin, $item->dimensions);
    }

    /** @return array{0:string,1:string}|null */
    private static function crushVerdict(string $load): ?array
    {
        $cushion = self::compressibleItem();
        [$base] = $cushion->instances();
        $brick = Item::create('brick', Dimensions::mm(100, 100, 100), $load);
        [$top] = $brick->instances();
        $floor = new Point(0, 0, 0);
        $above = new Point(0, 0, $cushion->dimensions->height->ticks);
        $placements = [
            new Placement($base, $floor, Rotation::LWH, $cushion->dimensions, $floor, $cushion->dimensions),
            new Placement($top, $above, Rotation::LWH, $brick->dimensions, $above, $brick->dimensions),
        ];
        return LoadCalculator::crushed(LoadCalculator::units($placements));
    }

    private static function packWedges(int $unusedHeight): array
    {
        return ArrayCodec::pack(self::wireRequest([
            self::wireItem('lower', 'convex_hull', self::wireVertices(self::lowerWedge())),
            self::wireItem('upper', 'convex_hull', self::wireVertices(self::upperWedge())),
        ]));
    }

    /** @return list<array{x:string,y:string,z:string}> */
    private static function wireVertices(array $vertices): array
    {
        $out = [];
        foreach ($vertices as $vertex) {
            $out[] = ['x' => (string)intdiv($vertex[0], 16000), 'y' => (string)intdiv($vertex[1], 16000),
                      'z' => (string)intdiv($vertex[2], 16000)];
        }
        return $out;
    }

    private static function wireItem(string $id, string $shape, array $vertices): array
    {
        return ['id' => $id, 'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100'],
                'shape_type' => $shape, 'hull_vertices' => $vertices];
    }

    private static function wireRequest(array $items, int $height = 100): array
    {
        return [
            'units' => ['length' => 'mm'],
            'configuration' => ['solver_profile' => 'balanced', 'max_containers' => 1],
            'items' => $items,
            'containers' => [['id' => 'crate', 'inner_dimensions' => [
                'length' => '100', 'width' => '100', 'height' => (string)$height]]],
        ];
    }

    public static function testTheRemainingShapeAdmissionRules(): void
    {
        $s = 100 * 16000;
        $wedge = self::lowerWedge();
        // Nesting and compression both rewrite occupied height; choosing an order silently
        // would give four engines four contracts, so the interaction is refused.
        self::assertRefused('nesting_height with shape_type convex_hull is not supported yet',
            fn() => new Item('x', Dimensions::mm(100, 100, 100), null, 1, Rotation::all(), false,
                true, false, null, 0.0, null, [], [], 0, [], null, [], null, Length::mm(2), null,
                null, ShapeType::CONVEX_HULL, $wedge));
        // `dimensions` stays the broad phase and the candidate envelope, so a hull larger than
        // it would be collision-tested against space the solver never reserved.
        self::assertRefused('does not fit inside dimensions',
            fn() => new Item('x', Dimensions::mm(100, 100, 100), null, 1, Rotation::all(), false,
                true, false, null, 0.0, null, [], [], 0, [], null, [], null, null, null, null,
                ShapeType::CONVEX_HULL, [[0,0,0],[$s + 1,0,0],[0,$s,0],[0,0,$s]]));
        self::assertRefused('compression_ratio must be between zero and one',
            fn() => new Item('x', Dimensions::mm(100, 100, 100), null, 1, Rotation::all(), false,
                true, false, null, 0.0, null, [], [], 0, [], null, [], null, null, null, null,
                ShapeType::COMPRESSIBLE, null, Compression::PPM + 1, 100));
        self::assertRefused('max_compression_pressure_kpa cannot be negative',
            fn() => new Item('x', Dimensions::mm(100, 100, 100), null, 1, Rotation::all(), false,
                true, false, null, 0.0, null, [], [], 0, [], null, [], null, null, null, null,
                ShapeType::COMPRESSIBLE, null, 250000, -1));
    }

    public static function testTheExactPathAgreesWithPythonWhereSixtyFourBitsDoNot(): void
    {
        // The safety net, exercised on the case that needs it. This hull's vertex coordinates
        // are coprime, so the gcd reduction cannot shrink its axes and the worst projection
        // is 12287800320884799202 -- past the 9223372036854775807 a signed 64-bit integer
        // holds. A native computation here would wrap and answer confidently wrong; the
        // per-axis check routes it to `SignedBigInt` instead. Every verdict below is Python's.
        $s = 100 * 16000;
        $shape = HullShape::of([[0,0,0],[$s,1,2],[3,$s,5],[7,11,$s],[$s-13,$s-17,$s-19]]);
        self::assertSame('2047966720147466533', $shape->volume);
        self::assertTrue(HullShape::collide($shape, [0,0,0], $shape, [0,0,0]));
        self::assertTrue(HullShape::collide($shape, [0,0,0], $shape, [533333,320000,228571]));
        self::assertFalse(HullShape::collide($shape, [0,0,0], $shape, [2 * $s, 0, 0]));
    }

    /**
     * A cuboid has twelve edges in three directions, and finding more would mean
     * the face walk is emitting diagonals rather than hull edges.
     *
     * `HullShape::box` names those three without searching; a hull authored as the same eight
     * corners has to find them, so this is the cheapest check that the two agree.
     */
    public static function testAnAuthoredBoxReducesToThreeEdgeDirections(): void
    {
        $corners = [];
        foreach ([0, 10] as $x) {
            foreach ([0, 20] as $y) {
                foreach ([0, 30] as $z) {
                    $corners[] = [$x, $y, $z];
                }
            }
        }
        $authored = HullShape::of($corners);
        self::assertSame(3, count($authored->edgeDirections));
        self::assertSame(3, count($authored->faceAxes));
        self::assertSame('6000', $authored->volume);
    }

    /**
     * A convex polyhedron on `v` vertices has at most `3v - 6` edges.
     *
     * The one cheap statement that separates a real edge set from a plausible wrong one. A
     * walk that closed a face early would still pass every collision test -- a superset is
     * always safe -- while quietly giving back the cost this change was made for.
     */
    public static function testTheEdgeCountObeysTheEulerBound(): void
    {
        $hulls = [
            [[0,0,0],[100,0,0],[0,100,0],[0,0,100],[100,0,100],[0,100,100]],
            [[8,12,16],[16,8,8],[12,0,0],[8,8,16],[12,4,16],[8,12,8],[0,16,4]],
            [[4,4,8],[0,0,16],[12,8,16],[8,16,0],[0,0,8],[16,12,12]],
        ];
        foreach ($hulls as $vertices) {
            $shape = HullShape::of($vertices);
            $bound = 3 * count($vertices) - 6;
            self::assertTrue(
                count($shape->edgeDirections) <= $bound,
                count($shape->edgeDirections) . ' edge directions on ' . count($vertices)
                    . ' vertices exceeds the Euler bound ' . $bound,
            );
        }
    }

    /**
     * The memo may change how often a shape is built and never what it is.
     *
     * Asserted rather than assumed: a cache is the classic place for a determinism
     * regression to hide, because a wrong entry is only visible on the second call.
     */
    public static function testTheShapeMemoReturnsWhatAFreshBuildWould(): void
    {
        $vertices = [[0,0,0],[12,0,0],[0,9,0],[0,0,7],[12,9,0],[4,3,7]];
        foreach (Rotation::all() as $rotation) {
            $fresh = HullShape::of(HullShape::rotate($vertices, $rotation));
            $first = HullShape::shapeFor($vertices, $rotation);
            $second = HullShape::shapeFor($vertices, $rotation);
            self::assertSame($fresh->volume, $first->volume);
            self::assertSame($fresh->vertices, $first->vertices);
            self::assertSame($fresh->faceAxes, $first->faceAxes);
            self::assertSame($fresh->edgeDirections, $first->edgeDirections);
            self::assertSame($first, $second, 'the second call must be the memoised shape');
        }
    }

    /**
     * The face walk must start from a corner, not from a small decimal string.
     *
     * This engine ordered the face by `key()` -- the `"x,y,z"` encoding it uses to
     * deduplicate -- and string order is not lexicographic on the vector: `"10,0,0"` sorts
     * before `"9,0,0"`. So the walk could begin at a vertex lying inside the face or part-way
     * along one of its edges, and emit a segment that is not a hull edge.
     *
     * Nothing observable showed it. The volume was right, and the collision verdict was right
     * because a superset of separating axes can never report a false separation -- the only
     * symptom was that this engine found 18, 13 and 10 edge directions where Python, Rust and
     * the fixed JavaScript fallback find 17, 12 and 9. The exact ordered axes come from one
     * shared fixture read by all four ports, so equal counts cannot hide different edges.
     */
    public static function testTheFaceWalkStartsFromACornerNotFromASmallString(): void
    {
        $path = __DIR__ . '/../../conformance/scene/hull-internals.json';
        $document = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('packvium-hull-internals/v1', $document['format']);
        foreach ($document['cases'] as $case) {
            $shape = HullShape::of($case['vertices']);
            self::assertSame($case['volume'], $shape->volume);
            self::assertSame($case['face_axes'], $shape->faceAxes);
            self::assertSame($case['edge_directions'], $shape->edgeDirections);
        }
    }
}
