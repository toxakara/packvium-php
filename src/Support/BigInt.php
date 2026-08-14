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
    public static function add(string $a, string $b): string
    {
        $i = strlen($a) - 1; $j = strlen($b) - 1; $carry = 0; $out = '';
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = ($i >= 0 ? ord($a[$i--]) - 48 : 0) + ($j >= 0 ? ord($b[$j--]) - 48 : 0) + $carry;
            $out = (string)($sum % 10) . $out; $carry = intdiv($sum, 10);
        }
        return ltrim($out, '0') ?: '0';
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

    public static function multiply(string|int $a, string|int $b): string
    {
        $a = ltrim((string)$a, '0') ?: '0'; $b = ltrim((string)$b, '0') ?: '0';
        if ($a === '0' || $b === '0') { return '0'; }
        $digits = array_fill(0, strlen($a) + strlen($b), 0);
        for ($i = strlen($a)-1; $i >= 0; --$i) {
            for ($j = strlen($b)-1; $j >= 0; --$j) {
                $p = (ord($a[$i])-48) * (ord($b[$j])-48) + $digits[$i+$j+1];
                $digits[$i+$j+1] = $p % 10; $digits[$i+$j] += intdiv($p,10);
            }
        }
        return ltrim(implode('', $digits), '0') ?: '0';
    }

    /** Compare two non-negative decimal strings: -1, 0 or 1. */
    public static function compare(string|int $a, string|int $b): int
    {
        $a = self::normalize($a); $b = self::normalize($b);
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
    }

    /**
     * Subtract $b from $a.
     *
     * @throws \InvalidArgumentException when $b exceeds $a; the result would be negative
     *                                   and these helpers model non-negative integers only.
     */
    public static function subtract(string|int $a, string|int $b): string
    {
        $a = self::normalize($a); $b = self::normalize($b);
        if (self::compare($a, $b) < 0) {
            throw new \InvalidArgumentException("BigInt::subtract would be negative: {$a} - {$b}");
        }
        $i = strlen($a) - 1; $j = strlen($b) - 1; $borrow = 0; $out = '';
        while ($i >= 0) {
            $digit = (ord($a[$i--]) - 48) - $borrow - ($j >= 0 ? ord($b[$j--]) - 48 : 0);
            if ($digit < 0) { $digit += 10; $borrow = 1; } else { $borrow = 0; }
            $out = (string)$digit . $out;
        }
        return ltrim($out, '0') ?: '0';
    }

    /**
     * Floor division.
     *
     * @throws \DivisionByZeroError when $b is zero.
     */
    public static function divide(string|int $a, string|int $b): string
    {
        $a = self::normalize($a); $b = self::normalize($b);
        if ($b === '0') { throw new \DivisionByZeroError('BigInt::divide by zero'); }
        if (self::compare($a, $b) < 0) { return '0'; }
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

    private static function normalize(string|int $value): string
    {
        $value = ltrim((string)$value, '0');
        return $value === '' ? '0' : $value;
    }
}
