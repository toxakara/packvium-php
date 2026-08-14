<?php
declare(strict_types=1);

namespace Packvium\Tests;

use InvalidArgumentException;
use Packvium\Support\RationalParser;
use Packvium\Unit\Rounding;

/**
 * The text-to-rational front door.
 *
 * PHP's 64-bit integers are the tightest constraint in the project: Python and Rust
 * would happily parse values this must refuse, so the accepted range and the overflow
 * guards are pinned here. A silently different accepted range between languages is
 * exactly the divergence this library exists to avoid.
 */
final class RationalParserTest extends TestCase
{
    public static function testIntegers(): void
    {
        self::assertSame([5, 1], RationalParser::parse(5));
        self::assertSame([-5, 1], RationalParser::parse(-5));
        self::assertSame([7, 1], RationalParser::parse('7'));
        self::assertSame([7, 1], RationalParser::parse('+7'));
        self::assertSame([-7, 1], RationalParser::parse('-7'));
    }

    public static function testDecimals(): void
    {
        self::assertSame([25, 10], RationalParser::parse('2.5'));
        self::assertSame([-25, 10], RationalParser::parse('-2.5'));
        self::assertSame([1, 100], RationalParser::parse('0.01'));
    }

    public static function testSimpleFractions(): void
    {
        self::assertSame([1, 2], RationalParser::parse('1/2'));
        self::assertSame([-1, 2], RationalParser::parse('-1/2'));
        self::assertSame([3, 16], RationalParser::parse('3 / 16'));
    }

    public static function testMixedFractions(): void
    {
        self::assertSame([99, 8], RationalParser::parse('12 3/8'));
        self::assertSame([3, 2], RationalParser::parse('1 1/2'));
    }

    public static function testAMixedFractionTakesItsSignFromTheText(): void
    {
        // "-0 1/2" is minus one half; the sign cannot be read off a whole part of zero.
        self::assertSame([-1, 2], RationalParser::parse('-0 1/2'));
        self::assertSame([-3, 2], RationalParser::parse('-1 1/2'));
    }

    public static function testNonBreakingSpacesAreTolerated(): void
    {
        // Copied out of a spreadsheet, this is what a mixed fraction often arrives as.
        self::assertSame([99, 8], RationalParser::parse("12\u{00A0}3/8"));
    }

    public static function testMalformedTextIsRejected(): void
    {
        foreach (['', 'abc', '1 2 3', '--1', '1..2', '1/'] as $text) {
            self::assertThrows(InvalidArgumentException::class,
                static fn() => RationalParser::parse($text), "accepted '{$text}'");
        }
    }

    public static function testAZeroDenominatorIsRejected(): void
    {
        self::assertThrows(InvalidArgumentException::class, static fn() => RationalParser::parse('1/0'));
    }

    public static function testAbsurdlyPreciseDecimalsAreRejected(): void
    {
        // A shared cap with Python: 10**19 does not fit a PHP integer.
        $accepted = RationalParser::parse('0.' . str_repeat('1', 18));
        self::assertSame(10 ** 18, $accepted[1]);
        self::assertThrows(InvalidArgumentException::class,
            static fn() => RationalParser::parse('0.' . str_repeat('1', 19)));
    }

    public static function testScalingCancelsTheDenominatorBeforeMultiplying(): void
    {
        // Without the cancellation, numerator * 16000 overflows even though the exact
        // answer is a small number of ticks.
        self::assertSame(17_975, RationalParser::scaled('1.123456789012345678', 16_000, Rounding::Nearest));
    }

    public static function testScalingRefusesValuesBeyondIntegerRange(): void
    {
        self::assertThrows(InvalidArgumentException::class,
            static fn() => RationalParser::scaled('9223372036854775807', 16_000, Rounding::Nearest));
    }

    public static function testRoundingModesAgreeWithPython(): void
    {
        self::assertSame(1, RationalParser::scaled('1.4', 1, Rounding::Floor));
        self::assertSame(2, RationalParser::scaled('1.4', 1, Rounding::Ceil));
        self::assertSame(1, RationalParser::scaled('1.4', 1, Rounding::Nearest));
    }

    public static function testNearestBreaksTiesToEven(): void
    {
        self::assertSame(0, RationalParser::scaled('0.5', 1, Rounding::Nearest));
        self::assertSame(2, RationalParser::scaled('1.5', 1, Rounding::Nearest));
        self::assertSame(2, RationalParser::scaled('2.5', 1, Rounding::Nearest));
    }

    public static function testFloorMeansFloorForNegativeValues(): void
    {
        self::assertSame(-2, RationalParser::scaled('-1.5', 1, Rounding::Floor));
        self::assertSame(-1, RationalParser::scaled('-1.5', 1, Rounding::Ceil));
        self::assertSame(-2, RationalParser::scaled('-1.5', 1, Rounding::Nearest));
    }

    public static function testDecimalStringIsExactLongDivision(): void
    {
        // Not a float division: a tick count beyond 2^53 cannot survive a double, and
        // the rendered value is part of the wire contract.
        self::assertSame('25.4', RationalParser::decimalString(406_400, 16_000, 8));
        self::assertSame('0', RationalParser::decimalString(0, 16_000, 8));
        self::assertSame('0.0625', RationalParser::decimalString(1_000, 16_000, 8));
        self::assertSame('-25.4', RationalParser::decimalString(-406_400, 16_000, 8));
    }

    public static function testDecimalStringRoundsTiesToEvenAtTheRequestedPrecision(): void
    {
        self::assertSame('0.33', RationalParser::decimalString(1, 3, 2));
    }

    public static function testDecimalStringMatchesPythonsRoundHalfEven(): void
    {
        // 799998 ticks rendered as inches used to truncate to '1.96849901'
        // here while Python's Decimal.quantize (ROUND_HALF_EVEN, its default context)
        // rendered '1.96849902'. Both engines must agree on the wire value.
        self::assertSame('1.96849902', RationalParser::decimalString(799_998, 406_400, 8));
    }

    public static function testDecimalStringRoundsUpOnAnExactHalfWithOddPrecedingDigit(): void
    {
        // 1/2 to 0 places is exactly 0.5; the whole part 0 is even, so it stays 0.
        self::assertSame('0', RationalParser::decimalString(1, 2, 0));
        // 3/2 to 0 places is exactly 1.5; ties-to-even rounds up to the even 2.
        self::assertSame('2', RationalParser::decimalString(3, 2, 0));
        // 5/4 to 1 place is exactly 1.25 -> last kept digit 2 is even, stays '1.2'.
        self::assertSame('1.2', RationalParser::decimalString(5, 4, 1));
        // 7/4 to 1 place is exactly 1.75 -> last kept digit 7 is odd, rounds up to '1.8'.
        self::assertSame('1.8', RationalParser::decimalString(7, 4, 1));
    }
}
