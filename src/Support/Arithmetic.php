<?php
declare(strict_types=1);
namespace Packvium\Support;
final class Arithmetic
{
    /**
     * Exact floor(a * b / d) for non-negative inputs, without overflowing a 64-bit int.
     *
     * Contact areas are squared tick counts and weights are eighth-microgram counts, so
     * their product routinely exceeds PHP_INT_MAX even for ordinary parcels. Python and
     * Rust do this in arbitrary precision; PHP falls back to decimal-string arithmetic
     * only when the direct product would wrap.
     */
    public static function mulDiv(int $a, int $b, int $d): int
    {
        if ($d <= 0) { throw new \InvalidArgumentException('Divisor must be positive'); }
        if ($a === 0 || $b === 0) { return 0; }
        $quotient = intdiv($a, $d);
        $remainder = $a % $d;
        $whole = $quotient === 0 ? 0 : $quotient * $b;
        if ($remainder === 0) { return $whole; }
        if ($remainder <= intdiv(PHP_INT_MAX, $b)) { return $whole + intdiv($remainder * $b, $d); }
        return $whole + (int)BigInt::divide(BigInt::multiply((string)$remainder, (string)$b), (string)$d);
    }

    /**
     * Exact ceil(a * b / d) for non-negative inputs, without overflowing a 64-bit int.
     *
     * The rounding direction is the contract, not a detail: every inexact division in
     * the rating model (dimensional weight, permille-shaped surcharges) rounds up, so a
     * quote can never come out a minor unit below what the tariff actually charges.
     */
    public static function mulDivCeil(int $a, int $b, int $d): int
    {
        if ($d <= 0) { throw new \InvalidArgumentException('Divisor must be positive'); }
        if ($a === 0 || $b === 0) { return 0; }
        if ($a <= intdiv(PHP_INT_MAX, $b)) {
            $product = $a * $b;
            return intdiv($product, $d) + ($product % $d === 0 ? 0 : 1);
        }
        $product = BigInt::multiply((string)$a, (string)$b);
        $quotient = BigInt::divide($product, (string)$d);
        $exact = BigInt::compare(BigInt::multiply($quotient, (string)$d), $product) === 0;
        return (int)$quotient + ($exact ? 0 : 1);
    }
}
