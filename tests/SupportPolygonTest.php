<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Constraint\Point2D;
use Packvium\Constraint\SupportPolygon;
use Packvium\Domain\AxisAlignedBox;
use Packvium\Domain\Dimensions;
use Packvium\Domain\Point;
use Packvium\Unit\Length;

/**
 * Convex hull and tipping geometry, checked against hand-computed values.
 *
 * Overlap area is not the same as stability: an item can be supported over enough
 * area and still overhang its own centre of gravity. Every value here was worked out
 * by hand first, not derived from the code under test. The Python suite runs the
 * same shape of test in packvium-python/tests/test_support_polygon.py.
 */
final class SupportPolygonTest extends TestCase
{
    private static function box(int $x, int $y, int $l, int $w, int $h = 10): AxisAlignedBox
    {
        return new AxisAlignedBox(new Point($x, $y, 0), new Dimensions(new Length($l), new Length($w), new Length($h)));
    }

    /** @param list<Point2D> $points @return list<array{0:int,1:int}> */
    private static function pairs(array $points): array
    {
        return array_map(static fn(Point2D $p): array => [$p->x, $p->y], $points);
    }

    // ------------------------------------------------------------------- convex hull

    public static function testTheHullOfARectangleIsItsFourCornersCounterClockwise(): void
    {
        $points = [new Point2D(0, 0), new Point2D(80, 0), new Point2D(80, 200), new Point2D(0, 200)];
        self::assertSame([[0, 0], [80, 0], [80, 200], [0, 200]], self::pairs(SupportPolygon::convexHull($points)));
    }

    public static function testAnInteriorPointIsDroppedFromTheHull(): void
    {
        $points = [new Point2D(0, 0), new Point2D(80, 0), new Point2D(80, 200), new Point2D(0, 200), new Point2D(40, 100)];
        self::assertSame([[0, 0], [80, 0], [80, 200], [0, 200]], self::pairs(SupportPolygon::convexHull($points)));
    }

    public static function testDuplicatePointsCollapseToOne(): void
    {
        $points = [new Point2D(0, 0), new Point2D(0, 0), new Point2D(80, 0), new Point2D(80, 0)];
        self::assertSame([[0, 0], [80, 0]], self::pairs(SupportPolygon::convexHull($points)));
    }

    public static function testASinglePointIsItsOwnDegenerateHull(): void
    {
        self::assertSame([[5, 5]], self::pairs(SupportPolygon::convexHull([new Point2D(5, 5)])));
    }

    public static function testCollinearPointsFormADegenerateSegmentHull(): void
    {
        $points = [new Point2D(0, 0), new Point2D(10, 0), new Point2D(20, 0)];
        self::assertSame([[0, 0], [20, 0]], self::pairs(SupportPolygon::convexHull($points)));
    }

    // --------------------------------------------------------------- point in hull

    public static function testTheCentreOfASquareHullIsInside(): void
    {
        $hull = SupportPolygon::convexHull([new Point2D(0, 0), new Point2D(100, 0), new Point2D(100, 100), new Point2D(0, 100)]);
        self::assertTrue(SupportPolygon::pointInHull(new Point2D(50, 50), $hull));
    }

    public static function testAPointOutsideTheHullIsRejected(): void
    {
        $hull = SupportPolygon::convexHull([new Point2D(0, 0), new Point2D(100, 0), new Point2D(100, 100), new Point2D(0, 100)]);
        self::assertFalse(SupportPolygon::pointInHull(new Point2D(150, 50), $hull));
    }

    public static function testAPointExactlyOnTheBoundaryCountsAsSupported(): void
    {
        $hull = SupportPolygon::convexHull([new Point2D(0, 0), new Point2D(100, 0), new Point2D(100, 100), new Point2D(0, 100)]);
        self::assertTrue(SupportPolygon::pointInHull(new Point2D(100, 50), $hull));
        self::assertTrue(SupportPolygon::pointInHull(new Point2D(0, 0), $hull));
    }

    public static function testAPointMatchingADegenerateSinglePointHullIsInside(): void
    {
        self::assertTrue(SupportPolygon::pointInHull(new Point2D(5, 5), [new Point2D(5, 5)]));
        self::assertFalse(SupportPolygon::pointInHull(new Point2D(5, 6), [new Point2D(5, 5)]));
    }

    public static function testAPointOnADegenerateSegmentHullIsInside(): void
    {
        $hull = [new Point2D(0, 0), new Point2D(20, 0)];
        self::assertTrue(SupportPolygon::pointInHull(new Point2D(10, 0), $hull));
        self::assertFalse(SupportPolygon::pointInHull(new Point2D(10, 1), $hull));
        self::assertFalse(SupportPolygon::pointInHull(new Point2D(30, 0), $hull));
    }

    public static function testAnEmptyHullSupportsNothing(): void
    {
        self::assertFalse(SupportPolygon::pointInHull(new Point2D(0, 0), []));
    }

    // ------------------------------------------------------------- physical scenario

    public static function testFullSupportKeepsTheCentroidInsideTheHull(): void
    {
        $candidate = self::box(0, 0, 100, 100);
        $supporter = self::box(0, 0, 100, 100);
        $hull = SupportPolygon::convexHull(SupportPolygon::contactHullPoints($candidate, [$supporter]));
        self::assertTrue(SupportPolygon::pointInHull(SupportPolygon::doubledCentroid($candidate), $hull));
    }

    public static function testAnOverhangThatMeetsAnAreaRatioCanStillTip(): void
    {
        // The whole point of this rule: 40% overlap can satisfy a modest support-ratio
        // threshold while leaving the candidate's own centroid entirely unsupported.
        $candidate = self::box(0, 0, 100, 100);
        $supporter = self::box(0, 0, 40, 100);
        $hull = SupportPolygon::convexHull(SupportPolygon::contactHullPoints($candidate, [$supporter]));
        self::assertSame([[0, 0], [80, 0], [80, 200], [0, 200]], self::pairs($hull));
        $centroid = SupportPolygon::doubledCentroid($candidate);
        self::assertSame(100, $centroid->x);
        self::assertSame(100, $centroid->y);
        self::assertFalse(SupportPolygon::pointInHull($centroid, $hull));
    }

    public static function testTwoNarrowSupportersCanStillBracketTheCentroid(): void
    {
        // Two thin rails, one on each side, support nothing directly under the
        // middle by area alone, but their combined hull still spans the centroid.
        $candidate = self::box(0, 0, 100, 100);
        $leftRail = self::box(0, 0, 10, 100);
        $rightRail = self::box(90, 0, 10, 100);
        $hull = SupportPolygon::convexHull(SupportPolygon::contactHullPoints($candidate, [$leftRail, $rightRail]));
        self::assertTrue(SupportPolygon::pointInHull(SupportPolygon::doubledCentroid($candidate), $hull));
    }

    // ------------------------------------------------------- rare and absurd supports

    /** @param list<AxisAlignedBox> $supporters */
    private static function supported(AxisAlignedBox $candidate, array $supporters): bool
    {
        $hull = SupportPolygon::convexHull(SupportPolygon::contactHullPoints($candidate, $supporters));
        return SupportPolygon::pointInHull(SupportPolygon::doubledCentroid($candidate), $hull);
    }

    public static function testAOneTickKnifeEdgeUnderTheCentroidCarriesTheBox(): void
    {
        self::assertTrue(self::supported(self::box(0, 0, 10, 10), [self::box(5, 0, 1, 10)]));
        self::assertFalse(self::supported(self::box(0, 0, 10, 10), [self::box(6, 0, 1, 10)]));
    }

    public static function testASupporterWiderThanTheFootprintIsClippedToIt(): void
    {
        $points = SupportPolygon::contactHullPoints(self::box(10, 10, 10, 10), [self::box(0, 0, 100, 100)]);
        self::assertSame([[20, 20], [20, 40], [40, 20], [40, 40]], self::pairs($points));
    }

    public static function testACornerSquareWhoseInnerCornerIsTheCentroidCarriesIt(): void
    {
        self::assertTrue(self::supported(self::box(0, 0, 40, 40), [self::box(0, 0, 20, 20)]));
        self::assertFalse(self::supported(self::box(0, 0, 40, 40), [self::box(0, 0, 20, 19)]));
    }

    /** @return list<AxisAlignedBox> */
    private static function legsOnACircle(callable $keep): array
    {
        $legs = [];
        for ($degree = 0; $degree < 360; $degree++) {
            $angle = deg2rad($degree);
            if ($keep($angle)) {
                $legs[] = self::box((int) round(500 + 400 * cos($angle)), (int) round(500 + 400 * sin($angle)), 1, 1);
            }
        }
        return $legs;
    }

    public static function testThreeHundredAndSixtyLegsOnACircleCarryTheCentroid(): void
    {
        self::assertTrue(self::supported(self::box(0, 0, 1000, 1000), self::legsOnACircle(static fn(float $a): bool => true)));
    }

    public static function testLegsOnHalfACircleLeaveTheCentroidHanging(): void
    {
        $legs = self::legsOnACircle(static fn(float $a): bool => sin($a) > 0.1);
        self::assertFalse(self::supported(self::box(0, 0, 1000, 1000), $legs));
    }

    public static function testRepeatedAndReorderedSupportersGiveTheSameHull(): void
    {
        $legs = [self::box(0, 0, 5, 5), self::box(35, 0, 5, 5), self::box(0, 35, 5, 5)];
        $candidate = self::box(0, 0, 40, 40);
        $hull = static fn(array $order): array => self::pairs(
            SupportPolygon::convexHull(SupportPolygon::contactHullPoints($candidate, $order))
        );
        self::assertSame($hull($legs), $hull(array_reverse($legs)));
        self::assertSame($hull($legs), $hull(array_merge($legs, $legs)));
        self::assertTrue(self::supported($candidate, $legs));
    }
}
