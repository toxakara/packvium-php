<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

use Packvium\Support\BigInt;
use Packvium\Support\SignedBigInt;
use Packvium\Validation\MalformedResult;

/**
 * Integer arithmetic with Python's semantics: exact at any size, floor division and modulo
 * rounding toward negative infinity.
 *
 * A value is a native `int` whenever it fits one and a decimal string only beyond that, so the
 * common case never leaves native arithmetic. PHP turns an overflowing `int` result into a
 * `float`, which `is_int` catches; the operation is then redone exactly in `SignedBigInt`.
 */
final class Exact
{
    /** @param int|string $value a decimal string from `SignedBigInt`, or a native int */
    public static function normalize($value)
    {
        if (\is_int($value)) {
            return $value;
        }
        $parsed = \filter_var($value, FILTER_VALIDATE_INT);
        return $parsed === false ? $value : $parsed;
    }

    /** @param int|string $a @param int|string $b @return int|string */
    public static function add($a, $b)
    {
        if (\is_int($a) && \is_int($b)) {
            $sum = $a + $b;
            if (\is_int($sum)) {
                return $sum;
            }
        }
        return self::normalize(SignedBigInt::add($a, $b));
    }

    /** @param int|string $a @param int|string $b @return int|string */
    public static function sub($a, $b)
    {
        if (\is_int($a) && \is_int($b)) {
            $difference = $a - $b;
            if (\is_int($difference)) {
                return $difference;
            }
        }
        return self::normalize(SignedBigInt::subtract($a, $b));
    }

    /** @param int|string $a @param int|string $b @return int|string */
    public static function mul($a, $b)
    {
        if (\is_int($a) && \is_int($b)) {
            $product = $a * $b;
            if (\is_int($product)) {
                return $product;
            }
        }
        return self::normalize(SignedBigInt::multiply($a, $b));
    }

    /** @param int|string $a @param int|string $b */
    public static function compare($a, $b): int
    {
        if (\is_int($a) && \is_int($b)) {
            return $a <=> $b;
        }
        return SignedBigInt::compare($a, $b);
    }

    /** @param int|string $value */
    public static function sign($value): int
    {
        return self::compare($value, 0);
    }

    /** @param int|string $value @return int|string */
    public static function negate($value)
    {
        if (\is_int($value) && $value !== PHP_INT_MIN) {
            return -$value;
        }
        return self::normalize(SignedBigInt::negate($value));
    }

    /** @param int|string $value @return int|string */
    public static function abs($value)
    {
        return self::sign($value) < 0 ? self::negate($value) : $value;
    }

    /** @param int|string $a @param int|string $b @return int|string */
    public static function max($a, $b)
    {
        return self::compare($a, $b) >= 0 ? $a : $b;
    }

    /**
     * Python's `a // b`.
     *
     * @param int|string $a @param int|string $b @return int|string
     */
    public static function floorDiv($a, $b)
    {
        if (self::sign($b) === 0) {
            throw MalformedResult::shape('division by zero');
        }
        if (\is_int($a) && \is_int($b) && !($a === PHP_INT_MIN && $b === -1)) {
            $quotient = \intdiv($a, $b);
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

    /** Python's `a % b`, which takes the divisor's sign. @param int|string $a @param int|string $b @return int|string */
    public static function mod($a, $b)
    {
        return self::sub($a, self::mul(self::floorDiv($a, $b), $b));
    }

    /** `-(-a // b)`. @param int|string $a @param int|string $b @return int|string */
    public static function ceilDiv($a, $b)
    {
        return self::negate(self::floorDiv(self::negate($a), $b));
    }

    /** @param int|string $a @param int|string $b @return int|string */
    public static function gcd($a, $b)
    {
        $a = self::abs($a);
        $b = self::abs($b);
        while (self::sign($b) !== 0) {
            [$a, $b] = [$b, self::mod($a, $b)];
        }
        return $a;
    }

    /** @param list<int|string> $values @return int|string */
    public static function sum(array $values)
    {
        $total = 0;
        foreach ($values as $value) {
            $total = self::add($total, $value);
        }
        return $total;
    }

    /** `2 ** $power`, exactly. @return int|string */
    public static function powerOfTwo(int $power)
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
}
