<?php
declare(strict_types=1);
namespace Packvium\Support;
use InvalidArgumentException;
use Packvium\Unit\Rounding;
final class RationalParser
{
    /**
     * Split an integer, decimal, fraction (`3/16`) or mixed fraction (`12 3/8`) into a
     * numerator and denominator.
     *
     * The sign is taken from the written text, not from the numeric value of the whole
     * part, so `-0 1/2` is minus one half rather than plus one half.
     *
     * @return array{0:int,1:int}
     * @param int|string $value
     */
    public static function parse($value): array
    {
        if (is_int($value)) { return [$value, 1]; }
        $text = trim(str_replace("\u{00A0}", ' ', $value));
        $negative = strncmp($text, '-', strlen('-')) === 0;
        if (preg_match('/^([+-]?\d+)\s+(\d+)\s*\/\s*(\d+)$/', $text, $m)) {
            $whole = (int)$m[1]; $n = (int)$m[2]; $d = (int)$m[3]; self::assertDenominator($d);
            $magnitude = abs($whole) * $d + $n;
            return [$negative ? -$magnitude : $magnitude, $d];
        }
        if (preg_match('/^([+-]?\d+)\s*\/\s*(\d+)$/', $text, $m)) {
            $d = (int)$m[2]; self::assertDenominator($d); return [(int)$m[1], $d];
        }
        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $text, $m)) { throw new InvalidArgumentException("Invalid decimal or fraction: {$text}"); }
        $fraction = $m[3] ?? '';
        if ($fraction === '') { return [$negative ? -(int)$m[2] : (int)$m[2], 1]; }
        if (strlen($fraction) > 18) { throw new InvalidArgumentException('Decimal has more fractional digits than a tick can distinguish'); }
        $den = 10 ** strlen($fraction);
        $num = (int)$m[2] * $den + (int)$fraction;
        return [$negative ? -$num : $num, $den];
    }

    /**
     * @param int|string $value
     */
    public static function scaled($value, int $multiplier, string $rounding): int
    {
        [$n, $d] = self::parse($value);
        // Cancel the denominator against the multiplier before multiplying. A value like
        // "1.123456789012345678" mm would otherwise overflow on n * 16000 even though the
        // exact result is a small integer number of ticks.
        $common = self::gcd($d, abs($multiplier));
        if ($common > 1) { $d = intdiv($d, $common); $multiplier = intdiv($multiplier, $common); }
        if ($n !== 0 && abs($n) > intdiv(PHP_INT_MAX, max(1, abs($multiplier)))) { throw new InvalidArgumentException('Scaled unit value exceeds integer range'); }
        return self::round($n * $multiplier, $d, $rounding);
    }

    /**
     * Render `ticks / divisor` as a decimal string rounded to $places digits,
     * ties to even (matches Python's `Decimal.quantize` default context).
     *
     * Long division on digits rather than a float division: a tick count beyond 2^53
     * cannot survive a `double`, and the serialized value is part of the wire contract
     * every implementation has to agree on.
     */
    public static function decimalString(int $ticks, int $divisor, int $places): string
    {
        $sign = $ticks < 0 ? '-' : '';
        $magnitude = abs($ticks);
        $whole = intdiv($magnitude, $divisor);
        $remainder = $magnitude % $divisor;
        $digits = '';
        for ($i = 0; $i < $places; $i++) {
            $remainder *= 10;
            $digits .= (string)intdiv($remainder, $divisor);
            $remainder %= $divisor;
        }
        $lastDigit = $places > 0 ? (int)$digits[$places - 1] : $whole;
        $roundUp = $remainder * 2 > $divisor || ($remainder * 2 === $divisor && $lastDigit % 2 === 1);
        if ($roundUp) {
            [$whole, $digits] = self::incrementDecimal($whole, $digits);
        }
        $digits = rtrim($digits, '0');
        $text = $digits === '' ? (string)$whole : $whole . '.' . $digits;
        return $text === '0' ? '0' : $sign . $text;
    }

    /** Add one ulp (at the last fractional digit) to a whole/digits decimal pair, carrying as needed. */
    private static function incrementDecimal(int $whole, string $digits): array
    {
        $chars = str_split($digits);
        for ($i = count($chars) - 1; $i >= 0; $i--) {
            if ($chars[$i] !== '9') {
                $chars[$i] = (string)((int)$chars[$i] + 1);
                return [$whole, implode('', $chars)];
            }
            $chars[$i] = '0';
        }
        return [$whole + 1, implode('', $chars)];
    }

    private static function round(int $n, int $d, string $rounding): int
    {
        $sign = $n < 0 ? -1 : 1; $a = abs($n); $q = intdiv($a, $d); $r = $a % $d;
        if ($r === 0) { return $sign * $q; }
        switch ($rounding) {
            case Rounding::Floor:
                return $sign < 0 ? -($q + 1) : $q;
            case Rounding::Ceil:
                return $sign < 0 ? -$q : $q + 1;
            case Rounding::Nearest:
                return $sign * (($r * 2 < $d) ? $q : (($r * 2 > $d) ? $q + 1 : ($q % 2 === 0 ? $q : $q + 1)));
        }
    }

    private static function gcd(int $a, int $b): int
    { while ($b !== 0) { [$a, $b] = [$b, $a % $b]; } return max(1, $a); }

    private static function assertDenominator(int $d): void { if ($d <= 0) { throw new InvalidArgumentException('Fraction denominator must be positive'); } }
}
