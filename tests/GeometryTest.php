<?php
declare(strict_types=1);

namespace Packvium\Tests;

use InvalidArgumentException;
use Packvium\Domain\AxisAlignedBox;
use Packvium\Domain\Dimensions;
use Packvium\Domain\Point;
use Packvium\Domain\Rotation;
use Packvium\Support\BigInt;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;

/**
 * Integer geometry: rotations, containment and the half-open box convention.
 *
 * A box owns `[origin, origin + size)`. That is what lets two boxes share a face
 * without colliding, so it is asserted directly rather than assumed. Volumes are
 * compared through chunked big integers because a cubic-tick volume overflows both a
 * 64-bit integer and the exact range of a double.
 */
final class GeometryTest extends TestCase
{
    private static function box(int $x, int $y, int $z, int $l, int $w, int $h): AxisAlignedBox
    {
        return new AxisAlignedBox(new Point($x, $y, $z), new Dimensions(new Length($l), new Length($w), new Length($h)));
    }

    // ------------------------------------------------------------------ dimensions

    public static function testDimensionsMustBePositive(): void
    {
        foreach ([[0, 1, 1], [1, 0, 1], [1, 1, 0]] as [$l, $w, $h]) {
            self::assertThrows(InvalidArgumentException::class,
                static fn() => new Dimensions(new Length($l), new Length($w), new Length($h)));
        }
    }

    public static function testDerivedMeasures(): void
    {
        $dims = Dimensions::mm(2, 3, 5);
        self::assertSame(32_000 * 48_000, $dims->baseAreaTicks());
        self::assertSame(80_000, $dims->maxEdge());
    }

    public static function testVolumeIsExactBeyondSixtyFourBits(): void
    {
        // A one-metre cube is 4.096e21 cubic ticks — past PHP_INT_MAX by three orders.
        self::assertSame('4096000000000000000000', Dimensions::of(1, 1, 1, 'm')->volumeString());
    }

    public static function testChunkedVolumesOrderExactly(): void
    {
        $small = Dimensions::mm(100, 100, 100)->volumeKey();
        $large = Dimensions::mm(100, 100, 101)->volumeKey();
        self::assertTrue($small < $large, 'chunked comparison must reproduce integer ordering');
        self::assertTrue(Dimensions::mm(100, 100, 101)->descendingVolumeKey()
            < Dimensions::mm(100, 100, 100)->descendingVolumeKey());
    }

    public static function testChunksAndTheVolumeStringAgree(): void
    {
        $dims = Dimensions::mm(37, 41, 43);
        self::assertSame(BigInt::chunks($dims->volumeString()), $dims->volumeKey());
    }

    public static function testFromArrayAcceptsMixedNotations(): void
    {
        $dims = Dimensions::fromArray([
            'length' => ['value' => '1', 'unit' => 'in'], 'width' => '10', 'height' => 20,
        ]);
        self::assertSame(Length::inches(1)->ticks, $dims->length->ticks);
        self::assertSame(Length::mm(10)->ticks, $dims->width->ticks);
        self::assertSame(Length::mm(20)->ticks, $dims->height->ticks);
    }

    // ------------------------------------------------------------------- rotations

    public static function testEveryRotationIsThePermutationItsNameSpells(): void
    {
        $dims = Dimensions::mm(2, 3, 5);
        $expected = [
            'LWH' => [2, 3, 5], 'LHW' => [2, 5, 3], 'WLH' => [3, 2, 5],
            'WHL' => [3, 5, 2], 'HLW' => [5, 2, 3], 'HWL' => [5, 3, 2],
        ];
        foreach ($expected as $name => [$l, $w, $h]) {
            $rotated = $dims->rotated(Rotation::from($name));
            self::assertSame(
                [Length::mm($l)->ticks, Length::mm($w)->ticks, Length::mm($h)->ticks],
                [$rotated->length->ticks, $rotated->width->ticks, $rotated->height->ticks],
                "rotation {$name}",
            );
        }
    }

    public static function testRotationPreservesVolume(): void
    {
        $dims = Dimensions::mm(2, 3, 5);
        foreach (Rotation::all() as $rotation) {
            self::assertSame($dims->volumeString(), $dims->rotated($rotation)->volumeString());
        }
    }

    public static function testUniqueRotationsCollapsesDuplicateShapes(): void
    {
        // Trying the same physical shape six times multiplies the search for nothing.
        self::assertCount(6, Dimensions::mm(2, 3, 5)->uniqueRotations(Rotation::all()));
        self::assertCount(3, Dimensions::mm(4, 4, 7)->uniqueRotations(Rotation::all()));
        self::assertCount(1, Dimensions::mm(4, 4, 4)->uniqueRotations(Rotation::all()));
    }

    public static function testUniqueRotationsKeepsTheFirstNameForAShape(): void
    {
        $rotations = Dimensions::mm(4, 4, 4)->uniqueRotations(Rotation::all());
        self::assertSame(Rotation::LWH, $rotations[0][0]);
    }

    public static function testUprightRotationsKeepTheHeightAxis(): void
    {
        $dims = Dimensions::mm(2, 3, 5);
        foreach (Rotation::upright() as $rotation) {
            self::assertSame($dims->height->ticks, $dims->rotated($rotation)->height->ticks);
        }
    }

    // ----------------------------------------------------------------- containment

    public static function testFitsInsideIsPerAxisAndInclusive(): void
    {
        self::assertTrue(Dimensions::mm(10, 10, 10)->fitsInside(Dimensions::mm(10, 10, 10)));
        self::assertFalse(Dimensions::mm(11, 10, 10)->fitsInside(Dimensions::mm(10, 10, 10)));
    }

    public static function testExpandAddsTheClearanceToBothSides(): void
    {
        $grown = Dimensions::mm(10, 20, 30)->expand(Length::mm(2));
        self::assertSame(
            [Length::mm(14)->ticks, Length::mm(24)->ticks, Length::mm(34)->ticks],
            [$grown->length->ticks, $grown->width->ticks, $grown->height->ticks],
        );
    }

    public static function testExpandingByZeroIsTheIdentity(): void
    {
        $dims = Dimensions::mm(10, 20, 30);
        self::assertSame($dims->length->ticks, $dims->expand(new Length(0))->length->ticks);
    }

    // ---------------------------------------------------------------------- points

    public static function testPointsCannotBeNegative(): void
    {
        foreach ([[-1, 0, 0], [0, -1, 0], [0, 0, -1]] as [$x, $y, $z]) {
            self::assertThrows(InvalidArgumentException::class, static fn() => new Point($x, $y, $z));
        }
    }

    // ----------------------------------------------------------------------- boxes

    public static function testFarCornerIsOriginPlusSize(): void
    {
        $box = self::box(10, 20, 30, 1, 2, 3);
        self::assertSame([11, 22, 33], [$box->x2(), $box->y2(), $box->z2()]);
    }

    public static function testTouchingFacesDoNotIntersect(): void
    {
        // Without the half-open convention, a perfectly tiled container would report a
        // collision between every pair of neighbours.
        $left = self::box(0, 0, 0, 10, 10, 10);
        foreach ([self::box(10, 0, 0, 10, 10, 10), self::box(0, 10, 0, 10, 10, 10), self::box(0, 0, 10, 10, 10, 10)] as $touching) {
            self::assertFalse($left->intersects($touching));
            self::assertFalse($touching->intersects($left));
        }
    }

    public static function testASingleOverlappingTickIsAnIntersection(): void
    {
        self::assertTrue(self::box(0, 0, 0, 10, 10, 10)->intersects(self::box(9, 9, 9, 10, 10, 10)));
    }

    public static function testContainmentAllowsAFlushFitButNotAnOverhang(): void
    {
        $outer = self::box(0, 0, 0, 10, 10, 10);
        self::assertTrue($outer->contains(self::box(0, 0, 0, 10, 10, 10)));
        self::assertTrue($outer->contains(self::box(1, 1, 1, 8, 8, 8)));
        self::assertFalse($outer->contains(self::box(1, 1, 1, 10, 10, 10)));
    }

    public static function testContainsPointExcludesTheFarFaces(): void
    {
        $box = self::box(0, 0, 0, 10, 10, 10);
        self::assertTrue($box->containsPoint(new Point(0, 0, 0)));
        self::assertTrue($box->containsPoint(new Point(9, 9, 9)));
        self::assertFalse($box->containsPoint(new Point(10, 0, 0)));
    }

    public static function testOverlapAreaIgnoresHeight(): void
    {
        // Support is decided by the footprint two boxes share, so this projection is
        // deliberately two-dimensional; the caller compares z separately.
        self::assertSame(50, self::box(0, 0, 0, 10, 10, 1)->overlapAreaXY(self::box(5, 0, 900, 10, 10, 1)));
    }

    public static function testDisjointAndEdgeTouchingFootprintsHaveNoArea(): void
    {
        $floor = self::box(0, 0, 0, 10, 10, 1);
        self::assertSame(0, $floor->overlapAreaXY(self::box(20, 20, 0, 5, 5, 1)));
        self::assertSame(0, $floor->overlapAreaXY(self::box(10, 0, 0, 5, 5, 1)));
    }

    public static function testOverlapAreaIsSymmetric(): void
    {
        $one = self::box(0, 0, 0, 10, 10, 1);
        $other = self::box(3, 4, 0, 10, 10, 1);
        self::assertSame(42, $one->overlapAreaXY($other));
        self::assertSame(42, $other->overlapAreaXY($one));
    }

    public static function testExtentIsTheHotLoopsIntegerView(): void
    {
        self::assertSame([1, 2, 3, 11, 22, 33], self::box(1, 2, 3, 10, 20, 30)->extent());
    }

    // ------------------------------------------------------------ dimensional weight

    public static function testDimensionalWeightMatchesTheTextbookInchesAndPoundsExample(): void
    {
        // A classic carrier example: a 10x10x10in box at a 139 divisor weighs 1000/139 lb.
        $result = Dimensions::inches(10, 10, 10)->dimensionalWeight(139, 'in', 'lb');
        $expectedTicks = intdiv(1000 * Weight::TICKS_PER_LB, 139);
        self::assertSame($expectedTicks, $result->ticks);
    }

    public static function testDimensionalWeightIsExactForARoundCentimetresAndKilogramsCase(): void
    {
        // 40 x 30 x 20 cm at a 5000 divisor is exactly 4.8 kg -- no rounding involved.
        $result = Dimensions::of(40, 30, 20, 'cm')->dimensionalWeight(5000, 'cm', 'kg');
        self::assertSame(Weight::of('4.8', 'kg')->ticks, $result->ticks);
    }

    public static function testDimensionalWeightScalesInverselyWithTheDivisor(): void
    {
        $dims = Dimensions::inches(20, 20, 20);
        $lowerDivisor = $dims->dimensionalWeight(139, 'in', 'lb');
        $higherDivisor = $dims->dimensionalWeight(166, 'in', 'lb');
        self::assertGreaterThan($higherDivisor->ticks, $lowerDivisor->ticks);
    }

    public static function testDimensionalWeightRejectsANonPositiveDivisor(): void
    {
        self::assertThrows(InvalidArgumentException::class, static fn() => Dimensions::inches(1, 1, 1)->dimensionalWeight(0, 'in', 'lb'));
    }
}
