<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Domain\Dimensions;
use Packvium\Domain\Item;
use Packvium\Domain\Nesting;
use Packvium\Domain\Placement;
use Packvium\Domain\Point;
use Packvium\Domain\Rotation;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;

/**
 * Nesting overlap accounting, checked against hand-computed values.
 *
 * A naive sum of each placement's own volume overstates how much space a nested
 * column actually fills, since neighbouring nested layers share part of the same
 * physical space. Every value here was worked out by hand first, not derived from
 * the code under test. The Python suite runs the same shape of test in
 * packvium-python/tests/test_nesting.py.
 */
final class NestingTest extends TestCase
{
    private static function placement(int $x, int $y, int $z, int $size, ?Item $item = null): Placement
    {
        $dims = new Dimensions(new Length($size), new Length($size), new Length($size));
        $item ??= Item::create('a', $dims, new Weight(0));
        [$instance] = $item->instances();
        $position = new Point($x, $y, $z);
        return new Placement($instance, $position, Rotation::LWH, $dims, $position, $dims);
    }

    public static function testALonePlacementHasNoOverlapToSubtract(): void
    {
        $p = self::placement(0, 0, 0, 100);
        self::assertSame((string)(100 ** 3), Nesting::usedVolume([$p]));
    }

    public static function testTwoNestedLayersDoNotDoubleCountTheSharedSlice(): void
    {
        $item = Item::create('crate', new Dimensions(new Length(100), new Length(100), new Length(100)), new Weight(0), nestingHeight: new Length(40));
        $lower = self::placement(0, 0, 0, 100, $item);
        $upper = self::placement(0, 0, 60, 100, $item);
        self::assertTrue(Nesting::isValidNesting($lower, $upper));
        // Union height is 100 + 60 = 160, not 200 -- the naive sum of two 100^3 cubes.
        self::assertSame((string)(100 * 100 * 160), Nesting::usedVolume([$lower, $upper]));
    }

    public static function testAThreeHighNestedColumnOnlySubtractsEachAdjacentPairOnce(): void
    {
        $item = Item::create('crate', new Dimensions(new Length(100), new Length(100), new Length(100)), new Weight(0), nestingHeight: new Length(40));
        $layers = [self::placement(0, 0, 0, 100, $item), self::placement(0, 0, 60, 100, $item), self::placement(0, 0, 120, 100, $item)];
        // Union height is 220 (100 + 60 + 60), matching the GridSolver placement test.
        self::assertSame((string)(100 * 100 * 220), Nesting::usedVolume($layers));
    }

    public static function testAnOverlapDeeperThanDeclaredIsNotTreatedAsAValidNest(): void
    {
        $item = Item::create('crate', new Dimensions(new Length(100), new Length(100), new Length(100)), new Weight(0), nestingHeight: new Length(40));
        $lower = self::placement(0, 0, 0, 100, $item);
        $upper = self::placement(0, 0, 50, 100, $item); // 50mm overlap, not 40
        self::assertFalse(Nesting::isValidNesting($lower, $upper));
        self::assertSame((string)(2 * 100 ** 3), Nesting::usedVolume([$lower, $upper]));
    }

    public static function testItemsWithoutNestingHeightAreSummedPlainly(): void
    {
        $a = self::placement(0, 0, 0, 100);
        $b = self::placement(100, 0, 0, 100);
        self::assertSame((string)(2 * 100 ** 3), Nesting::usedVolume([$a, $b]));
    }
}
