<?php
declare(strict_types=1);
namespace Packvium\Tests;

use Packvium\Config\PackingConfig;
use Packvium\Domain\{Dimensions, FixedPlacement, HullShape, Item, Orientations, Point, Rotation, ShapeType};
use Packvium\Packer;
use Packvium\Revisions\PlanRevision;
use Packvium\Revisions\PlanRevisionException;
use Packvium\Serialization\ArrayCodec;
use Packvium\Serialization\InvalidRequestException;
use Packvium\Support\FixedPlacementShape;
use Packvium\Support\ObjectRefusal;
use Packvium\Validation\FixedPlacementException;

/**
 * 1.10: the 24 proper rotations a convex hull may take, and the six a box keeps.
 *
 * Held to `packvium-python/tests/test_signed_rotations.py`. The legacy six keep their matrices,
 * so every answer that lists none of the 18 signed codes is unchanged; the signed codes are
 * refused on anything but a hull, and `keep_upright` keeps the codes whose z entry is `+H`.
 */
final class SignedRotationsTest extends TestCase
{
    private const SIDE = 10 * Support::MM;
    private const WEDGE = [[0, 0, 0], [self::SIDE, 0, 0], [0, self::SIDE, 0], [0, 0, self::SIDE], [self::SIDE, 0, self::SIDE], [0, self::SIDE, self::SIDE]];

    /** The table every engine and validator shares, spelled out (docs/IRREGULAR-ITEMS.md). */
    private const TABLE = [
        ['LWH', [0, 1, 2], [1, 1, 1]], ['LHW', [0, 2, 1], [-1, 1, 1]],
        ['WLH', [1, 0, 2], [-1, 1, 1]], ['WHL', [1, 2, 0], [1, 1, 1]],
        ['HLW', [2, 0, 1], [1, 1, 1]], ['HWL', [2, 1, 0], [-1, 1, 1]],
        ['+L-W-H', [0, 1, 2], [1, -1, -1]], ['-L+W-H', [0, 1, 2], [-1, 1, -1]],
        ['-L-W+H', [0, 1, 2], [-1, -1, 1]], ['+L-H+W', [0, 2, 1], [1, -1, 1]],
        ['+L+H-W', [0, 2, 1], [1, 1, -1]], ['-L-H-W', [0, 2, 1], [-1, -1, -1]],
        ['+W-L+H', [1, 0, 2], [1, -1, 1]], ['+W+L-H', [1, 0, 2], [1, 1, -1]],
        ['-W-L-H', [1, 0, 2], [-1, -1, -1]], ['+W-H-L', [1, 2, 0], [1, -1, -1]],
        ['-W+H-L', [1, 2, 0], [-1, 1, -1]], ['-W-H+L', [1, 2, 0], [-1, -1, 1]],
        ['+H-L-W', [2, 0, 1], [1, -1, -1]], ['-H+L-W', [2, 0, 1], [-1, 1, -1]],
        ['-H-L+W', [2, 0, 1], [-1, -1, 1]], ['+H-W+L', [2, 1, 0], [1, -1, 1]],
        ['+H+W-L', [2, 1, 0], [1, 1, -1]], ['-H-W-L', [2, 1, 0], [-1, -1, -1]],
    ];

    public static function testTheTableIsTheCanonicalOrderAndEveryEntryIsAProperRotation(): void
    {
        $rows = [];
        foreach (Rotation::every() as $rotation) {
            $rows[] = [$rotation->value, Orientations::sourceAxes($rotation), Orientations::signs($rotation)];
        }
        self::assertSame(self::TABLE, $rows);
        self::assertSame(\array_column(self::TABLE, 0), \array_keys(Orientations::TABLE));
        $distinct = [];
        foreach (self::TABLE as [, $axes, $signs]) {
            self::assertSame(1, self::determinant($axes, $signs));
            $distinct[\json_encode([$axes, $signs])] = true;
        }
        self::assertCount(24, $distinct);
    }

    public static function testTheLegacyVocabularyAndItsDefaultsAreUnchanged(): void
    {
        self::assertSame(Orientations::LEGACY_CODES, \array_map(static fn(Rotation $r): string => $r->value, Rotation::all()));
        self::assertSame([Rotation::LWH, Rotation::WLH], Rotation::upright());
        self::assertSame(Rotation::cases(), Rotation::every());
        $upright = \array_values(\array_filter(Rotation::every(), static fn(Rotation $r): bool => Orientations::isUpright($r)));
        self::assertSame(['LWH', 'WLH', '-L-W+H', '+W-L+H'], \array_map(static fn(Rotation $r): string => $r->value, $upright));
        self::assertSame([], \array_filter(Rotation::all(), static fn(Rotation $r): bool => Orientations::isSigned($r)));
        self::assertCount(18, \array_filter(\array_slice(Rotation::every(), 6), static fn(Rotation $r): bool => Orientations::isSigned($r)));
    }

    public static function testABoxIgnoresTheSigns(): void
    {
        $dimensions = Support::dims(1, 2, 3);
        $legacyByAxes = [];
        foreach (Rotation::all() as $legacy) {
            $legacyByAxes[\implode(',', Orientations::sourceAxes($legacy))] = $legacy;
        }
        foreach (Rotation::every() as $rotation) {
            $legacy = $legacyByAxes[\implode(',', Orientations::sourceAxes($rotation))];
            self::assertEquals($dimensions->rotated($legacy), $dimensions->rotated($rotation), $rotation->value);
        }
    }

    public static function testAHullTurnsByItsSignedMatrixThenBackToTheOrigin(): void
    {
        $volume = HullShape::of(self::WEDGE)->volume;
        foreach (self::TABLE as [$code, $axes, $signs]) {
            $turned = \array_map(static fn(array $v): array => [$signs[0] * $v[$axes[0]], $signs[1] * $v[$axes[1]], $signs[2] * $v[$axes[2]]], self::WEDGE);
            $low = [\min(\array_column($turned, 0)), \min(\array_column($turned, 1)), \min(\array_column($turned, 2))];
            $expected = \array_map(static fn(array $v): array => [$v[0] - $low[0], $v[1] - $low[1], $v[2] - $low[2]], $turned);
            $rotated = HullShape::rotate(self::WEDGE, Rotation::from($code));
            self::assertSame($expected, $rotated, $code);
            self::assertSame($volume, HullShape::of($rotated)->volume, $code);
        }
    }

    public static function testThe24RotationsOfAChiralHullAreCachedApart(): void
    {
        $shapes = [];
        foreach (Rotation::every() as $rotation) {
            $shapes[$rotation->value] = HullShape::shapeFor(self::WEDGE, $rotation);
        }
        // The half-turn about z is the wedge's complement in its box, not the wedge itself.
        self::assertTrue($shapes['-L-W+H'] != $shapes['LWH'], 'the half-turn is a different solid');
        foreach (Rotation::every() as $rotation) {
            self::assertTrue(HullShape::shapeFor(self::WEDGE, $rotation) === $shapes[$rotation->value], $rotation->value);
        }
    }

    public static function testASignedCodeIsAHullOnlyRuleOnTheObjectPath(): void
    {
        $refusal = self::caught(static fn() => Item::create('box', Support::dims(1, 2, 3), allowedRotations: [Rotation::NL_NW_PH]));
        self::assertTrue($refusal instanceof ObjectRefusal, 'an ObjectRefusal');
        self::assertSame(['invalid_value', Orientations::SIGNED_CODE_NEEDS_A_HULL], [$refusal->reason(), $refusal->detail()]);
        self::assertSame([Rotation::NL_NW_PH], self::wedge(allowedRotations: [Rotation::NL_NW_PH])->allowedRotations);
    }

    public static function testKeepUprightKeepsTheCodesWhoseZEntryIsPlusH(): void
    {
        $kept = self::wedge(allowedRotations: Rotation::every(), keepUpright: true)->allowedRotations;
        self::assertSame(Orientations::UPRIGHT_CODES, \array_map(static fn(Rotation $r): string => $r->value, $kept));
        self::assertSame(Rotation::upright(), Item::create('a', Support::dims(1, 2, 3), keepUpright: true)->allowedRotations);
    }

    public static function testTheJsonPathNamesTheCodeItRefuses(): void
    {
        $cases = [
            [['allowed_rotations' => ['LWH', '-L-W+H']], 'invalid_value', '/items/0/allowed_rotations/1'],
            [['allowed_rotations' => ['+L+W+H']], 'not_allowed', '/items/0/allowed_rotations/0'],
            [['shape_type' => 'convex_hull', 'hull_vertices' => [], 'allowed_rotations' => ['-L+W+H']], 'not_allowed', '/items/0/allowed_rotations/0'],
        ];
        foreach ($cases as [$item, $reason, $field]) {
            $error = self::caught(static fn() => ArrayCodec::pack(self::request($item)));
            self::assertTrue($error instanceof InvalidRequestException, $field);
            self::assertSame([$reason, $field], [$error->reason(), $error->field()]);
        }
        $legacy = self::caught(static fn() => ArrayCodec::pack(self::request(['allowed_rotations' => ['XYZ']])));
        self::assertSame('must be one of ["LWH","LHW","WLH","WHL","HLW","HWL"]', $legacy->detail());
    }

    public static function testTwoChiralWedgesShareACrateOnlyUnderASignedCode(): void
    {
        $crates = [Support::box('crate', 10, 10, 10)];
        $config = new PackingConfig(maxContainers: 1);
        $legacy = Support::pack([self::wedge(quantity: 2)], $crates, $config);
        self::assertCount(1, $legacy->unpacked);
        $items = [self::wedge(quantity: 2, allowedRotations: Rotation::every())];
        $every = Support::pack($items, $crates, $config);
        self::assertSame([], Support::problems($every, $items, $crates));
        self::assertSame([], $every->unpacked);
        self::assertSame(['LWH', '-L-W+H'], \array_map(static fn($p): string => $p->rotation->value, $every->containers[0]->placements));
    }

    public static function testAHullMayBeFixedInASignedOrientation(): void
    {
        $result = ArrayCodec::pack(self::chiral('-L-W+H'));
        $placements = $result['containers'][0]['placements'];
        self::assertSame([['-L-W+H', true], ['LWH', false]],
            \array_map(static fn(array $p): array => [$p['orientation'], $p['fixed'] ?? false], $placements));
    }

    public static function testAFixedHullOrientationIsReadFromItsVocabularyAndItsList(): void
    {
        foreach ([['-L+W+H', 'malformed', 'must be one of ["LWH"'], ['+L-W-H', 'cannot_hold', 'may not be placed in orientation +L-W-H']] as [$orientation, $reason, $detail]) {
            $request = self::chiral($orientation);
            $request['items'][0]['allowed_rotations'] = ['LWH', '-L-W+H'];
            $error = self::caught(static fn() => ArrayCodec::pack($request));
            self::assertTrue($error instanceof FixedPlacementException, $orientation);
            self::assertSame($reason, $error->reason());
            self::assertTrue(\str_contains($error->getMessage(), $detail), $error->getMessage());
        }
    }

    public static function testASignedOrientationCannotFixABoxOnEitherPath(): void
    {
        $request = self::chiral('-L-W+H');
        unset($request['items'][0]['shape_type'], $request['items'][0]['hull_vertices'], $request['items'][0]['allowed_rotations']);
        $fromJson = self::caught(static fn() => ArrayCodec::pack($request));
        self::assertTrue($fromJson instanceof FixedPlacementException, 'the JSON path');
        self::assertSame(['invalid_value', '/fixed_placements/0/orientation'], [$fromJson->reason(), $fromJson->field()]);
        $box = Item::create('wedge', Support::dims(10, 10, 10), quantity: 2);
        $fromObjects = self::caught(static fn() => (new Packer(new PackingConfig()))->pack(
            [$box], [Support::box('crate', 10, 10, 10)], [new FixedPlacement('wedge', 'crate', new Point(0, 0, 0), Rotation::NL_NW_PH)]));
        self::assertSame($fromJson->getMessage(), $fromObjects->getMessage());
    }

    public static function testARevisionLocksAHullInASignedOrientationAndRefusesOneOnABox(): void
    {
        $request = self::chiral('LWH');
        unset($request['fixed_placements']);
        $lock = ['sequence' => 1, 'type' => 'placement_locked',
            'placement' => ['item_type' => 'wedge', 'container_type' => 'crate', 'orientation' => '-L-W+H']];
        $derived = PlanRevision::applyEvents($request, [$lock]);
        self::assertSame('-L-W+H', $derived->fixed_placements[0]->orientation);
        $request['items'][0]['shape_type'] = 'rigid_cuboid';
        $error = self::caught(static fn() => PlanRevision::applyEvents($request, [$lock]));
        self::assertTrue($error instanceof PlanRevisionException, 'a revision refusal');
        self::assertTrue(\str_contains($error->getMessage(), 'placement.orientation: a signed rotation code requires'), $error->getMessage());
    }

    public static function testARequestWithNoItemListNamesNoHull(): void
    {
        self::assertSame([], FixedPlacementShape::hullItemTypes(null));
        self::assertSame(['w'], FixedPlacementShape::hullItemTypes([['id' => 'w', 'shape_type' => 'convex_hull'], 'x', ['id' => 5, 'shape_type' => 'convex_hull']]));
    }

    /** @param array{0:int,1:int,2:int} $axes @param array{0:int,1:int,2:int} $signs */
    private static function determinant(array $axes, array $signs): int
    {
        $m = [];
        for ($row = 0; $row < 3; ++$row) {
            for ($column = 0; $column < 3; ++$column) {
                $m[$row][$column] = $column === $axes[$row] ? $signs[$row] : 0;
            }
        }
        return $m[0][0] * ($m[1][1] * $m[2][2] - $m[1][2] * $m[2][1])
            - $m[0][1] * ($m[1][0] * $m[2][2] - $m[1][2] * $m[2][0])
            + $m[0][2] * ($m[1][0] * $m[2][1] - $m[1][1] * $m[2][0]);
    }

    /** @param list<Rotation> $allowedRotations */
    private static function wedge(int $quantity = 1, array $allowedRotations = [Rotation::LWH, Rotation::LHW, Rotation::WLH, Rotation::WHL, Rotation::HLW, Rotation::HWL], bool $keepUpright = false): Item
    {
        return Item::create('wedge', Dimensions::mm(10, 10, 10), quantity: $quantity, allowedRotations: $allowedRotations,
            keepUpright: $keepUpright, shapeType: ShapeType::CONVEX_HULL, hullVertices: self::WEDGE);
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private static function request(array $item): array
    {
        return ['items' => [['id' => 'a', 'dimensions' => ['length' => '10', 'width' => '10', 'height' => '10']] + $item],
            'containers' => [['id' => 'c', 'inner_dimensions' => ['length' => '10', 'width' => '10', 'height' => '10']]]];
    }

    /** @return array<string,mixed> */
    private static function chiral(string $orientation): array
    {
        $corners = \array_map(static fn(array $c): array => ['x' => (string)$c[0], 'y' => (string)$c[1], 'z' => (string)$c[2]],
            [[0, 0, 0], [10, 0, 0], [0, 10, 0], [0, 0, 10], [10, 0, 10], [0, 10, 10]]);
        return ['items' => [['id' => 'wedge', 'quantity' => 2, 'dimensions' => ['length' => '10', 'width' => '10', 'height' => '10'],
                'shape_type' => 'convex_hull', 'hull_vertices' => $corners, 'allowed_rotations' => \array_keys(Orientations::TABLE)]],
            'containers' => [['id' => 'crate', 'quantity' => 1, 'inner_dimensions' => ['length' => '10', 'width' => '10', 'height' => '10']]],
            'fixed_placements' => [['item_type' => 'wedge', 'container_type' => 'crate', 'orientation' => $orientation]]];
    }

    private static function caught(callable $call): \Throwable
    {
        try {
            $call();
        } catch (\Throwable $error) {
            return $error;
        }
        throw new \RuntimeException('expected a refusal, nothing was thrown');
    }
}
