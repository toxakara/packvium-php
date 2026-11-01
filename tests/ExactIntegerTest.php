<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Support\ExactInteger;

/**
 * Python's integer semantics past a native int. Expected values were computed in
 * Python, whose integers and `int / int` division are the reference.
 */
final class ExactIntegerTest extends TestCase
{
    public static function testAProductThatFitsStaysANativeInt(): void
    {
        self::assertSame(6, ExactInteger::mul(2, 3));
        self::assertSame(PHP_INT_MAX, ExactInteger::mul(PHP_INT_MAX, 1));
    }

    public static function testAProductPastANativeIntIsAnExactDecimalString(): void
    {
        self::assertSame('85070591730234615847396907784232501249', ExactInteger::mul(PHP_INT_MAX, PHP_INT_MAX));
        self::assertSame('-18446744073709551614', ExactInteger::mul(PHP_INT_MAX, -2));
    }

    public static function testAnExactResultBackInRangeIsANativeIntAgain(): void
    {
        $square = ExactInteger::mul(PHP_INT_MAX, PHP_INT_MAX);
        self::assertSame(PHP_INT_MAX, ExactInteger::floorDiv($square, PHP_INT_MAX));
        self::assertSame(0, ExactInteger::sub($square, $square));
    }

    public static function testMixedFormsCompareByValue(): void
    {
        $huge = ExactInteger::mul(PHP_INT_MAX, 2);
        self::assertSame(1, ExactInteger::compare($huge, PHP_INT_MAX));
        self::assertSame(-1, ExactInteger::compare(ExactInteger::negate($huge), PHP_INT_MIN));
        self::assertSame($huge, ExactInteger::max(7, $huge));
    }

    public static function testFloorDivisionRoundsTowardNegativeInfinity(): void
    {
        self::assertSame(-4, ExactInteger::floorDiv(-7, 2));
        self::assertSame(1, ExactInteger::mod(-7, 2));
        self::assertSame('-46116860184273879040', ExactInteger::floorDiv(ExactInteger::mul(PHP_INT_MIN, 10), 2));
        self::assertThrows(\DivisionByZeroError::class, static fn() => ExactInteger::floorDiv(1, 0));
    }

    public static function testMulDivSharesAWeightByAnAreaPastANativeInt(): void
    {
        // Two equal contacts of (3.2e12 ticks)^2 each share 1001 ticks: 500 and the rest.
        $area = ExactInteger::mul(3_200_000_000_000, 3_200_000_000_000);
        self::assertSame(500, ExactInteger::mulDiv(1001, $area, ExactInteger::mul($area, 2)));
    }

    public static function testARatioIsTheDoubleNearestTheExactQuotient(): void
    {
        self::assertSame(1 / 3, ExactInteger::ratio(1, 3));
        self::assertSame(0.0, ExactInteger::ratio(0, '340282366920938463463374607431768211455'));
        self::assertSame(1.0, ExactInteger::ratio('340282366920938463463374607431768211455', '340282366920938463463374607431768211455'));
        // Python: 9007199254740993 / 9007199254740995 == 0.9999999999999998; PHP's own
        // division rounds both operands to doubles first and answers 1.0.
        self::assertSame(0.9999999999999998, ExactInteger::ratio(9_007_199_254_740_993, 9_007_199_254_740_995));
    }

    public static function testAnExactHalfBetweenTwoDoublesRoundsToEven(): void
    {
        // (2^53 + 1) / 2^54 lies exactly between two doubles; Python picks the even one.
        self::assertSame(0.5, ExactInteger::ratio(9_007_199_254_740_993, '18014398509481984'));
        // (2^53 + 3) / 2^54 rounds up to the even neighbour above.
        self::assertSame(0.5000000000000002, ExactInteger::ratio(9_007_199_254_740_995, '18014398509481984'));
    }

    public static function testARatioWhoseFirstEstimateFallsShortIsCorrected(): void
    {
        // 2^56 / (2^57 + 1): the bit-length estimate starts one shift low.
        self::assertSame(0.5, ExactInteger::ratio(72_057_594_037_927_936, '144115188075855873'));
    }

    public static function testAShareTooLargeForANativeIntIsRefused(): void
    {
        self::assertThrows(\OverflowException::class, static fn() => ExactInteger::mulDiv(PHP_INT_MAX, '36893488147419103232', 2));
    }

    public static function testNegationKeepsANativeIntNative(): void
    {
        self::assertSame(-5, ExactInteger::negate(5));
        self::assertSame(5, ExactInteger::abs(-5));
    }

    public static function testASortKeyKeepsTheIntegerOrderAcrossBothForms(): void
    {
        $keys = [
            ExactInteger::sortKey(ExactInteger::mul(PHP_INT_MAX, 3)),
            ExactInteger::sortKey(5),
            ExactInteger::sortKey(ExactInteger::mul(PHP_INT_MAX, 2)),
            ExactInteger::sortKey(PHP_INT_MAX),
        ];
        usort($keys, static fn($a, $b): int => $a <=> $b);
        self::assertSame([5, PHP_INT_MAX], array_slice($keys, 0, 2));
        self::assertTrue($keys[2] < $keys[3], 'twice before three times PHP_INT_MAX');
    }

    public static function testADescendingKeyPutsTheLargestFirst(): void
    {
        $large = ExactInteger::descendingKey(ExactInteger::mul(PHP_INT_MAX, 3));
        $small = ExactInteger::descendingKey(PHP_INT_MAX);
        self::assertTrue($large < $small);
        self::assertSame([0, 0, -1], ExactInteger::descendingKey(1));
    }

    public static function testPowersOfTwoAreExactPastANativeInt(): void
    {
        self::assertSame(1 << 61, ExactInteger::powerOfTwo(61));
        self::assertSame('18446744073709551616', ExactInteger::powerOfTwo(64));
    }
}
