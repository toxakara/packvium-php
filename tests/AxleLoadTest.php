<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Constraint\AxleLoad;
use Packvium\Constraint\LoadUnit;
use Packvium\Domain\Axle;
use Packvium\Domain\AxisAlignedBox;
use Packvium\Domain\Dimensions;
use Packvium\Domain\Point;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;

/**
 * Two-axle weight distribution, checked against hand-computed values.
 *
 * Two-point beam statics: taking moments about each axle gives an exact fraction for
 * what the other axle carries. Every value here was worked out by hand first, not
 * derived from the code under test. The Python suite runs the same shape of test in
 * packvium-python/tests/test_axle_load.py.
 */
final class AxleLoadTest extends TestCase
{
    private static function unit(int $weight, int $x, int $length, string $label = 'u'): LoadUnit
    {
        $box = new AxisAlignedBox(new Point($x, 0, 0), new Dimensions(new Length($length), new Length(1000), new Length(100)));
        return new LoadUnit($box, $weight, null, null, $label);
    }

    public static function testALoadCentredBetweenTheAxlesSplitsEvenly(): void
    {
        // A single item spanning the whole container has its own centre at x=500,
        // exactly midway between axles at 100 and 900 -- each axle bears half of 800.
        $axles = [new Axle(new Length(100), new Weight(500)), new Axle(new Length(900), new Weight(500))];
        self::assertNull(AxleLoad::exceeded($axles, [self::unit(800, 0, 1000)]));
    }

    public static function testALoadRestingExactlyOnTheFrontAxlePutsNothingOnTheRear(): void
    {
        // The item's own centre (x=100) coincides with the front axle -- by lever
        // physics the rear axle bears none of it, so a zero-limit rear axle still passes.
        $axles = [new Axle(new Length(100), new Weight(800)), new Axle(new Length(900), new Weight(0))];
        self::assertNull(AxleLoad::exceeded($axles, [self::unit(800, 0, 200)]));
    }

    public static function testALoadRestingExactlyOnTheFrontAxleWouldOverloadALighterFrontLimit(): void
    {
        $axles = [new Axle(new Length(100), new Weight(799)), new Axle(new Length(900), new Weight(800))];
        self::assertSame(['axle_overloaded', 'front'], AxleLoad::exceeded($axles, [self::unit(800, 0, 200)]));
    }

    public static function testTheRearAxleBoundaryIsExactToTheTick(): void
    {
        // Item centred at x=500 (weight 800) over axles at 100/900 puts exactly 400
        // on each axle -- a rear limit of 400 passes, one tick less does not.
        $passing = [new Axle(new Length(100), new Weight(1000)), new Axle(new Length(900), new Weight(400))];
        $failing = [new Axle(new Length(100), new Weight(1000)), new Axle(new Length(900), new Weight(399))];
        $load = [self::unit(800, 0, 1000)];
        self::assertNull(AxleLoad::exceeded($passing, $load));
        self::assertSame(['axle_overloaded', 'rear'], AxleLoad::exceeded($failing, $load));
    }

    public static function testTwoItemsCombineBySuperposition(): void
    {
        // 400 at x=100 (all front) plus 400 at x=900 (all rear) -- front gets 400,
        // rear gets 400, neither axle sees the other item's contribution.
        $axles = [new Axle(new Length(100), new Weight(400)), new Axle(new Length(900), new Weight(400))];
        $atFront = self::unit(400, 20, 160, 'front'); // centre exactly 100
        $atRear = self::unit(400, 820, 160, 'rear'); // centre exactly 900
        self::assertNull(AxleLoad::exceeded($axles, [$atFront, $atRear]));
    }

    public static function testNoLimitOnAnAxleMeansThatAxleIsNeverChecked(): void
    {
        $axles = [new Axle(new Length(100), null), new Axle(new Length(900), new Weight(0))];
        // All the weight sits at the front axle's position, so the (unlimited)
        // front axle would bear everything and the (zero-limit) rear axle bears none.
        self::assertNull(AxleLoad::exceeded($axles, [self::unit(10_000, 20, 160)]));
    }

    public static function testNoUnitsMeansNoLoadAndNoViolation(): void
    {
        $axles = [new Axle(new Length(100), new Weight(0)), new Axle(new Length(900), new Weight(0))];
        self::assertNull(AxleLoad::exceeded($axles, []));
    }

    public static function testCentredTareIsPartOfTheGrossReactionAndIsReportedExactly(): void
    {
        $axles = [new Axle(new Length(100), new Weight(100)), new Axle(new Length(900), new Weight(100))];
        self::assertSame(
            ['denominator' => '1600', 'front_numerator' => '160000', 'rear_numerator' => '160000'],
            AxleLoad::reactions($axles, [], 200, 1000),
        );
        self::assertNull(AxleLoad::exceeded($axles, [], 200, 1000));
    }

    public static function testALoadOutsideTheAxleSpanHasANegativeOppositeReaction(): void
    {
        $axles = [new Axle(new Length(100), null), new Axle(new Length(900), null)];
        $reaction = AxleLoad::reactions($axles, [self::unit(800, 900, 200)]);
        self::assertSame('1600', $reaction['denominator']);
        self::assertTrue(str_starts_with($reaction['front_numerator'], '-'));
        self::assertFalse(str_starts_with($reaction['rear_numerator'], '-'));
    }
}
