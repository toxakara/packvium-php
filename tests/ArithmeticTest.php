<?php
declare(strict_types=1);

namespace Packvium\Tests;

use InvalidArgumentException;
use Packvium\Support\Arithmetic;
use Packvium\Support\BigInt;

/**
 * `mulDiv` — exact floor(a * b / d) without overflowing a 64-bit integer.
 *
 * Load propagation multiplies a weight in eighth-micrograms by a contact area in
 * squared ticks. That product exceeds PHP_INT_MAX for ordinary parcels, and the
 * silent float coercion it used to produce was a real defect. Python and Rust get
 * this for free from arbitrary-precision integers; PHP has to earn it.
 */
final class ArithmeticTest extends TestCase
{
    public static function testSmallValuesTakeTheDirectPath(): void
    {
        self::assertSame(50, Arithmetic::mulDiv(100, 1, 2));
        self::assertSame(250, Arithmetic::mulDiv(1_000, 100, 400));
        self::assertSame(750, Arithmetic::mulDiv(1_000, 300, 400));
    }

    public static function testZeroOperandsShortCircuit(): void
    {
        self::assertSame(0, Arithmetic::mulDiv(0, 12_345, 7));
        self::assertSame(0, Arithmetic::mulDiv(12_345, 0, 7));
    }

    public static function testTheResultIsFlooredNotRounded(): void
    {
        self::assertSame(333, Arithmetic::mulDiv(1_000, 1, 3));
        self::assertSame(666, Arithmetic::mulDiv(1_000, 2, 3));
    }

    public static function testAnExactDivisionHasNoRemainderTerm(): void
    {
        self::assertSame(7_000, Arithmetic::mulDiv(7_000, 3, 3));
    }

    public static function testAProductBeyondIntegerRangeStaysExact(): void
    {
        // A one-kilogram box on a 100 mm x 100 mm face: 8e9 ticks by 2.56e12 squared
        // ticks is 2.048e22, far past PHP_INT_MAX (~9.22e18).
        $weight = 8_000_000_000;
        $area = 1_600_000 * 1_600_000;
        $total = $area * 2;

        $share = Arithmetic::mulDiv($weight, $area, $total);
        self::assertSame(4_000_000_000, $share);
    }

    public static function testTheBigIntegerFallbackAgreesWithDecimalStringArithmetic(): void
    {
        $a = PHP_INT_MAX - 1;
        $b = 3;
        $d = 7;
        $expected = (int)BigInt::divide(BigInt::multiply((string)$a, (string)$b), (string)$d);
        self::assertSame($expected, Arithmetic::mulDiv($a, $b, $d));
    }

    public static function testAShareIsNeverLargerThanTheWholeItSplits(): void
    {
        $weight = 8_000_000_000;
        $area = 1_600_000 * 1_600_000;
        foreach ([1, 2, 3, 7, 999] as $parts) {
            self::assertLessThanOrEqual($weight, Arithmetic::mulDiv($weight, $area, $area * $parts));
        }
    }

    public static function testANonPositiveDivisorIsRejected(): void
    {
        self::assertThrows(InvalidArgumentException::class, static fn() => Arithmetic::mulDiv(1, 1, 0));
        self::assertThrows(InvalidArgumentException::class, static fn() => Arithmetic::mulDiv(1, 1, -1));
    }
}
