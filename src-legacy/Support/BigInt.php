<?php
declare(strict_types=1);
namespace Packvium\Support;

/**
 * Decimal-string arithmetic on non-negative integers.
 *
 * Volumes are measured in cubic length ticks, where a one-metre cube is already
 * 4.096e21 — beyond a 64-bit integer. These helpers keep such values exact without
 * depending on bcmath or gmp, which are not guaranteed to be installed.
 */
final class BigInt
{
    /** Decimal digits of one limb: a limb product plus two limbs stays below PHP_INT_MAX. */
    private const LIMB_DIGITS=9;
    private const LIMB=1000000000;
    /** Digits of the largest value whose sum or product with another of its size is a native integer. */
    private const NATIVE_DIGITS=18;

    public static function add(string $a, string $b): string
    {
        if (strlen($a) <= self::NATIVE_DIGITS && strlen($b) <= self::NATIVE_DIGITS) { return (string)((int)$a + (int)$b); }
        $x = self::limbs($a); $y = self::limbs($b); $sum = []; $carry = 0;
        for ($i = 0, $n = max(count($x), count($y)); $i < $n; ++$i) {
            $limb = ($x[$i] ?? 0) + ($y[$i] ?? 0) + $carry;
            $carry = $limb >= self::LIMB ? 1 : 0;
            $sum[] = $limb - $carry * self::LIMB;
        }
        if ($carry > 0) { $sum[] = $carry; }
        return self::fromLimbs($sum);
    }

    /**
     * Split a non-negative decimal string into fixed-width integer chunks, most
     * significant first.
     *
     * Comparing the resulting arrays with PHP's array comparison reproduces exact
     * numeric ordering for values far beyond a 64-bit integer, which is what volume
     * comparisons need.
     *
     * @return list<int>
     */
    public static function chunks(string $value, int $groups = 6, int $width = 15): array
    {
        $digits = ltrim($value, '0') ?: '0';
        $padded = str_pad($digits, $groups * $width, '0', STR_PAD_LEFT);
        if (strlen($padded) > $groups * $width) { throw new \InvalidArgumentException('Value too large to chunk'); }
        $out = [];
        for ($offset = 0; $offset < $groups * $width; $offset += $width) { $out[] = (int)substr($padded, $offset, $width); }
        return $out;
    }

    /**
     * @param string|int $a
     * @param string|int $b
     */
    public static function multiply($a, $b): string
    {
        $a = (string)$a; $b = (string)$b;
        if (strlen($a) + strlen($b) <= self::NATIVE_DIGITS) { return (string)((int)$a * (int)$b); }
        $x = self::limbs($a); $y = self::limbs($b);
        $product = array_fill(0, count($x) + count($y), 0);
        foreach ($x as $i => $left) {
            if ($left === 0) { continue; }
            $carry = 0;
            foreach ($y as $j => $right) {
                $limb = $product[$i + $j] + $left * $right + $carry;
                $carry = intdiv($limb, self::LIMB);
                $product[$i + $j] = $limb - $carry * self::LIMB;
            }
            $product[$i + count($y)] += $carry;
        }
        return self::fromLimbs($product);
    }

    /** Compare two non-negative decimal strings: -1, 0 or 1.
     * @param string|int $a
     * @param string|int $b */
    public static function compare($a, $b): int
    {
        $a = self::normalize($a); $b = self::normalize($b);
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
    }

    /**
     * Subtract $b from $a.
     *
     * @throws \InvalidArgumentException when $b exceeds $a; the result would be negative
     *                                   and these helpers model non-negative integers only.
     * @param string|int $a
     * @param string|int $b
     */
    public static function subtract($a, $b): string
    {
        $a = self::normalize($a); $b = self::normalize($b);
        if (self::compare($a, $b) < 0) {
            throw new \InvalidArgumentException("BigInt::subtract would be negative: {$a} - {$b}");
        }
        if (strlen($a) <= self::NATIVE_DIGITS) { return (string)((int)$a - (int)$b); }
        $x = self::limbs($a); $y = self::limbs($b); $difference = []; $borrow = 0;
        foreach ($x as $i => $limb) {
            $limb -= ($y[$i] ?? 0) + $borrow;
            $borrow = $limb < 0 ? 1 : 0;
            $difference[] = $limb + $borrow * self::LIMB;
        }
        return self::fromLimbs($difference);
    }

    /**
     * Floor division.
     *
     * @throws \DivisionByZeroError when $b is zero.
     * @param string|int $a
     * @param string|int $b
     */
    public static function divide($a, $b): string
    {
        $a = self::normalize($a); $b = self::normalize($b);
        if ($b === '0') { throw new \DivisionByZeroError('BigInt::divide by zero'); }
        if (self::compare($a, $b) < 0) { return '0'; }
        if (strlen($a) <= self::NATIVE_DIGITS) { return (string)intdiv((int)$a, (int)$b); }
        if (strlen($b) <= self::LIMB_DIGITS) { return self::divideByLimb($a, (int)$b); }
        return self::divideByEstimate($a, $b);
    }

    /** Short division by a divisor below one limb: each step divides a value below LIMB^2. */
    private static function divideByLimb(string $a, int $b): string
    {
        $quotient = []; $remainder = 0;
        foreach (array_reverse(self::limbs($a)) as $limb) {
            $remainder = $remainder * self::LIMB + $limb;
            $quotient[] = intdiv($remainder, $b);
            $remainder %= $b;
        }
        return self::fromLimbs(array_reverse($quotient));
    }

    /**
     * A float quotient corrected until q * b <= a < (q + 1) * b, which only the floor meets.
     *
     * Below 2^50 the float estimate is within a few units of the floor, so the correction
     * takes a step or two; a larger quotient falls back to digit-by-digit long division.
     */
    private static function divideByEstimate(string $a, string $b): string
    {
        $estimate = floor((float)$a / (float)$b);
        if (!($estimate < 2 ** 50)) { return self::longDivide($a, $b); }
        $quotient = (int)$estimate;
        $product = self::multiply($quotient, $b);
        while (self::compare($product, $a) > 0) { --$quotient; $product = self::subtract($product, $b); }
        while (self::compare($next = self::add($product, $b), $a) <= 0) { ++$quotient; $product = $next; }
        return (string)$quotient;
    }

    private static function longDivide(string $a, string $b): string
    {
        $quotient = ''; $remainder = '0';
        for ($i = 0, $n = strlen($a); $i < $n; ++$i) {
            // Schoolbook long division: shift the next digit in, then find the largest
            // multiple of the divisor that still fits. The inner search runs at most
            // ten times per digit.
            $remainder = ltrim($remainder . $a[$i], '0') ?: '0';
            $digit = 0;
            while (self::compare($remainder, $b) >= 0) {
                $remainder = self::subtract($remainder, $b);
                ++$digit;
            }
            $quotient .= (string)$digit;
        }
        return ltrim($quotient, '0') ?: '0';
    }

    /** @return list<int> The value's base-LIMB digits, least significant first. */
    private static function limbs(string $digits): array
    {
        $limbs = [];
        for ($end = strlen($digits); $end > 0; $end -= self::LIMB_DIGITS) {
            $start = $end > self::LIMB_DIGITS ? $end - self::LIMB_DIGITS : 0;
            $limbs[] = (int)substr($digits, $start, $end - $start);
        }
        return $limbs;
    }

    /** @param non-empty-list<int> $limbs Least significant first. */
    private static function fromLimbs(array $limbs): string
    {
        $top = count($limbs) - 1;
        while ($top > 0 && $limbs[$top] === 0) { --$top; }
        $out = (string)$limbs[$top];
        for ($i = $top - 1; $i >= 0; --$i) { $out .= str_pad((string)$limbs[$i], self::LIMB_DIGITS, '0', STR_PAD_LEFT); }
        return $out;
    }

    /**
     * @param string|int $value
     */
    private static function normalize($value): string
    {
        $value = ltrim((string)$value, '0');
        return $value === '' ? '0' : $value;
    }
}
