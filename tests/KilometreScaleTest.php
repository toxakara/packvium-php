<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Algorithm\CandidateFinder;
use Packvium\Constraint\SupportPolygon;
use Packvium\Domain\AxisAlignedBox;
use Packvium\Domain\Compression;
use Packvium\Domain\Dimensions;
use Packvium\Domain\LatticeSummary;
use Packvium\Domain\Point;
use Packvium\Domain\Rotation;
use Packvium\Support\ExactGeometry;
use Packvium\Support\ExactInteger;
use Packvium\Unit\Length;

/**
 * Every length below `PHP_INT_MAX` ticks is a valid request, and two of them multiply
 * past a native int. Python and Rust (`i128`) hold those products exactly; these pin that
 * PHP does too, at each place one is formed.
 */
final class KilometreScaleTest extends TestCase
{
    private const FAR = PHP_INT_MAX - 1_000;

    private static function box(int $x, int $y, int $length, int $width): AxisAlignedBox
    {
        return new AxisAlignedBox(new Point($x, $y, 0), new Dimensions(new Length($length), new Length($width), new Length(10)));
    }

    public static function testAnOverlapPastANativeIntIsExact(): void
    {
        $side = 4_000_000_000;
        $field = self::box(0, 0, $side, $side);
        self::assertSame('16000000000000000000', ExactGeometry::overlapAreaXY($field, $field));
        self::assertSame(100, ExactGeometry::overlapAreaXY(self::box(0, 0, 10, 10), self::box(0, 0, 10, 10)));
    }

    public static function testABaseAreaPastANativeIntIsExactAndOrdersLargestFirst(): void
    {
        // 200 km by 200 km is 1.024e25 square ticks.
        $field = Dimensions::mm(200_000_000, 200_000_000, 1);
        $larger = Dimensions::mm(200_000_000, 200_000_001, 1);
        self::assertSame('10240000000000000000000000', ExactGeometry::baseArea($field));
        $key = static fn(Dimensions $dimensions): array => ExactInteger::descendingKey(ExactGeometry::baseArea($dimensions));
        self::assertTrue($key($larger) < $key($field));
        self::assertTrue($key($field) < $key(Dimensions::mm(1, 1, 1)));
    }

    public static function testTheCentroidRuleStaysExactNextToTheLargestCoordinate(): void
    {
        // A centroid half a tick from the support's edge, where doubled coordinates pass a native int.
        $candidate = self::box(self::FAR - 101, 0, 101, 10);
        $hull = static fn(int $length): array => SupportPolygon::convexHull(
            SupportPolygon::contactHullPoints($candidate, [self::box(self::FAR - 101, 0, $length, 10)]),
        );
        $centroid = SupportPolygon::doubledCentroid($candidate);
        self::assertTrue(SupportPolygon::pointInHull($centroid, $hull(51)));
        self::assertFalse(SupportPolygon::pointInHull($centroid, $hull(50)));
    }

    public static function testADoubledCentreNearTheLargestCoordinateIsExact(): void
    {
        self::assertSame('18446744073709549615', ExactGeometry::doubledCentre(self::FAR, 1));
        self::assertSame('21', ExactGeometry::doubledCentre(10, 1));
    }

    public static function testTheRoomLeftScoreOrdersPastANativeInt(): void
    {
        $vast = CandidateFinder::roomLeftKey(4_000_000_000, 4_000_000_001, 0);
        $vaster = CandidateFinder::roomLeftKey(4_000_000_000, 4_000_000_002, 0);
        self::assertSame(17, CandidateFinder::roomLeftKey(3, 5, 2));
        self::assertTrue([0, $vast] < [0, $vaster], 'the exact keys keep their order');
        self::assertTrue([0, PHP_INT_MAX] < [0, $vast], 'any exact key ranks above every int');
    }

    public static function testALatticeWithMoreSlotsPerLayerThanAnIntHoldsCountsOneLayer(): void
    {
        $grain = new Dimensions(new Length(1), new Length(1), new Length(1));
        $lattice = new LatticeSummary('grain', Rotation::LWH, $grain, $grain, 4_000_000_000, 4_000_000_000, 1, 0, 5, 0);
        self::assertSame(0, $lattice->fullLayers());
        self::assertSame(5, $lattice->remainder());
        self::assertSame(1, $lattice->layersUsed());
    }

    public static function testPressureOverAFootprintPastANativeIntIsExact(): void
    {
        [$numerator, $denominator] = Compression::pressure(8_000_000, '16000000000000000000');
        self::assertTrue(Compression::exceeds([$numerator, $denominator], 0));
        self::assertFalse(Compression::exceeds([$numerator, $denominator], 1));
    }
}
