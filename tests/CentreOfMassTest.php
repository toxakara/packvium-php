<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Domain\CentreOfMass;
use Packvium\Domain\Container;
use Packvium\Domain\Dimensions;
use Packvium\Domain\Item;
use Packvium\Domain\PackedContainer;
use Packvium\Domain\Placement;
use Packvium\Domain\Point;
use Packvium\Domain\Rotation;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;

/**
 * Centre of mass and its offset, checked against hand-computed values.
 *
 * Needed for axle load and side-to-side balance, both of which care about the worst
 * horizontal axis, not a single blended distance -- which is also why this is a
 * Chebyshev offset (the worse of the two axis ratios), not a Euclidean one: a square
 * root would break the exact-integer arithmetic this library is built on. The Python
 * suite runs the same shape of test in packvium-python/tests/test_centre_of_mass.py.
 */
final class CentreOfMassTest extends TestCase
{
    private static function inner(): Dimensions
    {
        return new Dimensions(new Length(1000), new Length(1000), new Length(100));
    }

    private static function placement(int $weight, int $x, int $length, int $y = 0, int $width = 1000): Placement
    {
        $dims = new Dimensions(new Length($length), new Length($width), new Length(100));
        [$instance] = Item::create("i{$x}-{$y}", $dims, new Weight($weight))->instances();
        $position = new Point($x, $y, 0);
        return new Placement($instance, $position, Rotation::LWH, $dims, $position, $dims);
    }

    public static function testASingleItemFlushAgainstOneWallGivesAnExactHandCheckedOffset(): void
    {
        // Container centre is x=500; the item's own centre is x=100 (0 + 200/2), a
        // distance of 400 out of a 500 half-length -- 80% exactly, and 0% on y since
        // the item spans the full width and is already centred on that axis.
        $placements = [self::placement(1, 0, 200)];
        self::assertSame(800_000, CentreOfMass::offsetPpm(self::inner(), $placements));
    }

    public static function testTwoSymmetricItemsCancelToACentredOffset(): void
    {
        $placements = [self::placement(1, 0, 100), self::placement(1, 900, 100)];
        self::assertSame(0, CentreOfMass::offsetPpm(self::inner(), $placements));
    }

    public static function testAHeavierItemPullsTheCentreOfMassTowardIt(): void
    {
        // centre_x = (1*50 + 3*950) / 4 = 725; container centre 500; offset 225/500 = 45%.
        $light = self::placement(1, 0, 100);
        $heavy = self::placement(3, 900, 100);
        self::assertSame(450_000, CentreOfMass::offsetPpm(self::inner(), [$light, $heavy]));
    }

    public static function testTheWorseAxisWinsNotABlendedDistance(): void
    {
        // x spans the full length, so it is centred (0%); y spans [0, 200], centre
        // 100, a distance of 400 out of a 500 half-width -- 80%. The Chebyshev
        // offset reports that 80%, not some smaller blend with x's 0%.
        $dims = new Dimensions(new Length(1000), new Length(200), new Length(100));
        [$instance] = Item::create('a', $dims, new Weight(1))->instances();
        $position = new Point(0, 0, 0);
        $offCenterY = new Placement($instance, $position, Rotation::LWH, $dims, $position, $dims);
        self::assertSame(800_000, CentreOfMass::offsetPpm(self::inner(), [$offCenterY]));
    }

    public static function testNoPlacementsReportAZeroOffset(): void
    {
        self::assertSame(0, CentreOfMass::offsetPpm(self::inner(), []));
    }

    public static function testMasslessPlacementsReportAZeroOffsetRatherThanDividingByZero(): void
    {
        $weightless = self::placement(0, 0, 100);
        self::assertSame(0, CentreOfMass::offsetPpm(self::inner(), [$weightless]));
    }

    public static function testThePackedContainerMethodMatchesTheFreeFunction(): void
    {
        $box = Container::create('c', self::inner());
        $placements = [self::placement(1, 0, 200)];
        $packed = new PackedContainer($box, 1, $placements);
        self::assertSame(CentreOfMass::offsetPpm(self::inner(), $placements), $packed->centreOfMassOffsetPpm());
    }
}
