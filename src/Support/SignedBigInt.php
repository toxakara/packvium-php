<?php
declare(strict_types=1);
namespace Packvium\Support;

/**
 * Decimal-string arithmetic on integers that may be negative.
 *
 * `BigInt` is deliberately unsigned: every value it was written for -- volumes, objective
 * keys, load shares -- is a magnitude, and giving it a sign would have been unused weight.
 * Separating-axis geometry is the first thing here that needs the other half. A projection
 * `v . d` is a signed quantity, and it exceeds a 64-bit integer for hulls whose reduced axes
 * stay large: measured on a 100 mm hull with coprime vertex coordinates, the worst
 * projection is 2.5e19 against a ceiling of 9.2e18.
 *
 * Sign is carried separately from magnitude and the magnitude work is delegated to `BigInt`,
 * so there is exactly one implementation of decimal addition in this codebase. Zero is always
 * spelled `"0"`, never `"-0"`, so equal values compare equal as strings.
 */
final class SignedBigInt
{
    public static function multiply(string|int $a, string|int $b): string
    {
        [$leftSign, $left] = self::split($a);
        [$rightSign, $right] = self::split($b);
        return self::compose($leftSign * $rightSign, BigInt::multiply($left, $right));
    }

    public static function add(string|int $a, string|int $b): string
    {
        [$leftSign, $left] = self::split($a);
        [$rightSign, $right] = self::split($b);
        if ($leftSign === $rightSign) {
            return self::compose($leftSign, BigInt::add($left, $right));
        }
        // Opposite signs: the larger magnitude decides the sign and the smaller is taken
        // away from it, which is the only case where subtraction can be needed at all.
        $order = BigInt::compare($left, $right);
        if ($order === 0) {
            return '0';
        }
        return $order > 0
            ? self::compose($leftSign, BigInt::subtract($left, $right))
            : self::compose($rightSign, BigInt::subtract($right, $left));
    }

    public static function subtract(string|int $a, string|int $b): string
    {
        return self::add($a, self::negate($b));
    }

    public static function compare(string|int $a, string|int $b): int
    {
        [$leftSign, $left] = self::split($a);
        [$rightSign, $right] = self::split($b);
        if ($leftSign !== $rightSign) {
            return $leftSign < $rightSign ? -1 : 1;
        }
        $order = BigInt::compare($left, $right);
        return $leftSign < 0 ? -$order : $order;
    }

    public static function negate(string|int $value): string
    {
        [$sign, $magnitude] = self::split($value);
        return self::compose(-$sign, $magnitude);
    }

    /** @return array{int,string} the sign (-1, 0 or 1) and the bare magnitude */
    private static function split(string|int $value): array
    {
        $text = (string)$value;
        $negative = $text !== '' && $text[0] === '-';
        if ($negative) {
            $text = substr($text, 1);
        }
        $text = ltrim($text, '0');
        if ($text === '') {
            return [0, '0'];
        }
        return [$negative ? -1 : 1, $text];
    }

    private static function compose(int $sign, string $magnitude): string
    {
        $magnitude = ltrim($magnitude, '0');
        if ($magnitude === '') {
            return '0';
        }
        return $sign < 0 ? '-' . $magnitude : $magnitude;
    }
}
