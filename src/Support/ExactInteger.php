<?php
declare(strict_types=1);
namespace Packvium\Support;

/**
 * Integer arithmetic with Python's semantics: exact at any size, floor division rounding
 * toward negative infinity.
 *
 * A value is a native `int` whenever it fits one and a decimal string only beyond that, so
 * the common case never leaves native arithmetic. PHP turns an overflowing `int` result
 * into a `float`, which `is_int` catches; the operation is then redone exactly in
 * `SignedBigInt`. Areas are the reason the engine needs this: two lengths below
 * `PHP_INT_MAX` ticks multiply to a value Python and Rust (`i128`) still hold exactly.
 *
 * `ResultValidation\Exact` is the result validator's own copy, kept apart on purpose: the
 * validator shares no code with the engine it checks.
 */
final class ExactInteger
{
    /** Below this magnitude an `int` converts to a `float` exactly. */
    private const EXACT_FLOAT = 9_007_199_254_740_992;

    /** Bits kept before rounding a quotient to a double: 53 of mantissa, a guard and a round bit. */
    private const QUOTIENT_BITS = 55;

    public static function normalize(int|string $value): int|string
    {
        if (is_int($value)) {
            return $value;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        return $parsed === false ? $value : $parsed;
    }

    public static function add(int|string $a, int|string $b): int|string
    {
        if (is_int($a) && is_int($b)) {
            $sum = $a + $b;
            if (is_int($sum)) {
                return $sum;
            }
        }
        return self::normalize(SignedBigInt::add($a, $b));
    }

    public static function sub(int|string $a, int|string $b): int|string
    {
        if (is_int($a) && is_int($b)) {
            $difference = $a - $b;
            if (is_int($difference)) {
                return $difference;
            }
        }
        return self::normalize(SignedBigInt::subtract($a, $b));
    }

    public static function mul(int|string $a, int|string $b): int|string
    {
        if (is_int($a) && is_int($b)) {
            $product = $a * $b;
            if (is_int($product)) {
                return $product;
            }
        }
        return self::normalize(SignedBigInt::multiply($a, $b));
    }

    public static function compare(int|string $a, int|string $b): int
    {
        if (is_int($a) && is_int($b)) {
            return $a <=> $b;
        }
        return SignedBigInt::compare($a, $b);
    }

    public static function sign(int|string $value): int
    {
        return self::compare($value, 0);
    }

    public static function negate(int|string $value): int|string
    {
        if (is_int($value) && $value !== PHP_INT_MIN) {
            return -$value;
        }
        return self::normalize(SignedBigInt::negate($value));
    }

    public static function abs(int|string $value): int|string
    {
        return self::sign($value) < 0 ? self::negate($value) : $value;
    }

    public static function max(int|string $a, int|string $b): int|string
    {
        return self::compare($a, $b) >= 0 ? $a : $b;
    }

    /** Python's `a // b`. */
    public static function floorDiv(int|string $a, int|string $b): int|string
    {
        if (self::sign($b) === 0) {
            throw new \DivisionByZeroError('Division by zero');
        }
        if (is_int($a) && is_int($b) && !($a === PHP_INT_MIN && $b === -1)) {
            $quotient = intdiv($a, $b);
            return ($a % $b !== 0 && (($a < 0) !== ($b < 0))) ? $quotient - 1 : $quotient;
        }
        $magnitude = BigInt::divide((string)self::abs($a), (string)self::abs($b));
        $negative = (self::sign($a) < 0) !== (self::sign($b) < 0);
        if (!$negative) {
            return self::normalize($magnitude);
        }
        $exact = self::compare(self::mul($magnitude, self::abs($b)), self::abs($a)) === 0;
        return self::negate($exact ? self::normalize($magnitude) : self::add($magnitude, 1));
    }

    /** Python's `a % b`, which takes the divisor's sign. */
    public static function mod(int|string $a, int|string $b): int|string
    {
        return self::sub($a, self::mul(self::floorDiv($a, $b), $b));
    }

    /** @param iterable<int|string> $values */
    public static function sum(iterable $values): int|string
    {
        $total = 0;
        foreach ($values as $value) {
            $total = self::add($total, $value);
        }
        return $total;
    }

    /** `2 ** $power`, exactly. */
    public static function powerOfTwo(int $power): int|string
    {
        if ($power < 62) {
            return 1 << $power;
        }
        $value = 1 << 61;
        for ($i = 61; $i < $power; ++$i) {
            $value = self::mul($value, 2);
        }
        return $value;
    }

    /**
     * Exact `floor(a * b / d)` for non-negative operands whose quotient fits an `int`: a
     * weight shared out by contact area, where the area and its total may not.
     */
    public static function mulDiv(int $a, int|string $b, int|string $d): int
    {
        if (is_int($b) && is_int($d)) {
            return Arithmetic::mulDiv($a, $b, $d);
        }
        $quotient = self::floorDiv(self::mul($a, $b), $d);
        if (!is_int($quotient)) {
            throw new \OverflowException('quotient exceeds a native integer');
        }
        return $quotient;
    }

    /**
     * Python's `numerator / denominator` for non-negative integers: the double nearest the
     * exact quotient, ties to even. PHP converts each operand to a double first, which
     * rounds twice once either passes 2^53. O(d^2) in the operands' digit count, and only
     * then; below 2^53 it is one native division.
     */
    public static function ratio(int|string $numerator, int|string $denominator): float
    {
        if (is_int($numerator) && is_int($denominator)
            && $numerator <= self::EXACT_FLOAT && $denominator <= self::EXACT_FLOAT) {
            return $numerator / $denominator;
        }
        if (self::sign($numerator) === 0) {
            return 0.0;
        }
        $shift = self::QUOTIENT_BITS - self::bitLength($numerator) + self::bitLength($denominator);
        // The estimate is within a bit or two; each step doubles or halves the quotient, so
        // exactly one shift lands it in [2^54, 2^55).
        while (true) {
            [$quotient, $inexact] = self::shiftedQuotient($numerator, $denominator, $shift);
            if (!is_int($quotient) || $quotient >= 1 << self::QUOTIENT_BITS) {
                --$shift;
            } elseif ($quotient < 1 << (self::QUOTIENT_BITS - 1)) {
                ++$shift;
            } else {
                break;
            }
        }
        $mantissa = $quotient >> 2;
        $dropped = $quotient & 3;
        if ($dropped > 2 || ($dropped === 2 && ($inexact || ($mantissa & 1) === 1))) {
            ++$mantissa;
        }
        return $mantissa * 2.0 ** (2 - $shift);
    }

    /**
     * An order-preserving stand-in for a non-negative value inside a PHP array key: the
     * value itself while it is an `int`, and its fixed-width chunks beyond. PHP ranks any
     * array above any `int`, and every value that needs chunks exceeds every `int`, so
     * comparing keys that hold either form still follows the exact integer order.
     *
     * @return int|list<int>
     */
    public static function sortKey(int|string $value): int|array
    {
        return is_int($value) ? $value : BigInt::chunks($value, 3, 15);
    }

    /**
     * Fixed-width chunks of a non-negative value, negated so ascending array order is
     * largest first: the same shape as `Dimensions::descendingVolumeKey`.
     *
     * @return list<int>
     */
    public static function descendingKey(int|string $value): array
    {
        return array_map(static fn(int $chunk): int => -$chunk, BigInt::chunks((string)$value, 3, 15));
    }

    /** Approximate: within a bit or two of the exact length, which `ratio` corrects. */
    private static function bitLength(int|string $value): int
    {
        $digits = ltrim((string)$value, '-');
        $lead = (float)substr($digits, 0, 15);
        return $lead <= 0.0 ? 0 : (int)floor(log($lead, 2) + max(0, strlen($digits) - 15) * log(10, 2)) + 1;
    }

    /**
     * `floor(n * 2^shift / d)` and whether it dropped a remainder.
     *
     * @return array{0:int|string,1:bool}
     */
    private static function shiftedQuotient(int|string $numerator, int|string $denominator, int $shift): array
    {
        $scaledNumerator = $shift >= 0 ? self::mul($numerator, self::powerOfTwo($shift)) : $numerator;
        $scaledDenominator = $shift < 0 ? self::mul($denominator, self::powerOfTwo(-$shift)) : $denominator;
        $quotient = self::floorDiv($scaledNumerator, $scaledDenominator);
        $inexact = self::sign(self::sub($scaledNumerator, self::mul($quotient, $scaledDenominator))) !== 0;
        return [$quotient, $inexact];
    }
}
