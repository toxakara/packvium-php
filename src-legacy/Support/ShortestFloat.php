<?php
declare(strict_types=1);
namespace Packvium\Support;

use InvalidArgumentException;
use LogicException;

/**
 * The shortest decimal digits that read back as a given double.
 *
 * Python's `repr` and ECMAScript's `Number::toString` both start from this digit string and
 * lay it out differently. It is computed without `json_encode`, `var_export` or a string cast,
 * whose digits follow the `precision` and `serialize_precision` ini settings, and without
 * changing either setting, which a host may lock.
 *
 * For each length from 1 to 17 digits, `sprintf('%.Ne')` gives the correctly rounded
 * candidate and a cast back to float says whether it identifies the double. A rounded
 * candidate can miss while the grid point on the other side of the value hits -- just above
 * a power of two the rounding interval is twice as wide on one side -- so that neighbour is
 * tried before a longer length. Seventeen significant digits always identify a double.
 * O(1): at most 34 formatted candidates.
 */
final class ShortestFloat
{
    private const MAX_DIGITS = 17;

    /**
     * @return array{0:string,1:int} the digits without trailing zeros, and the decimal point
     *     position `n` for which the value is 0.DIGITS x 10^n
     */
    public static function digits(float $value): array
    {
        if (!\is_finite($value) || $value <= 0.0) {
            throw new InvalidArgumentException('shortest digits are defined for a finite positive double');
        }
        for ($length = 1; $length <= self::MAX_DIGITS; $length++) {
            [$mantissa, $exponent] = self::rounded($value, $length);
            if (self::readsBackAs($mantissa, $exponent, $value)) {
                return self::normalized($mantissa, $exponent);
            }
            [$mantissa, $exponent] = self::otherNeighbour($mantissa, $exponent, $length, $value);
            if (self::readsBackAs($mantissa, $exponent, $value)) {
                return self::normalized($mantissa, $exponent);
            }
        }
        throw new LogicException('seventeen significant digits always identify a double');
    }

    /**
     * The correctly rounded `$length`-digit candidate as an integer mantissa and a power of ten.
     *
     * @return array{0:int,1:int}
     */
    private static function rounded(float $value, int $length): array
    {
        $text = \sprintf('%.' . ($length - 1) . 'e', $value);
        // The separator is matched as any non-digit: only the digits and the exponent are read.
        if (\preg_match('/^(\d)(?:\D(\d+))?e([+-]?\d+)$/', $text, $parts) !== 1) {
            throw new LogicException("sprintf spelled a double as '{$text}'");
        }
        return [(int) ($parts[1] . ($parts[2] ?? '')), (int) $parts[3] - ($length - 1)];
    }

    /**
     * The grid point bracketing the value from the side the rounded candidate is not on. Below
     * a power of ten the grid is ten times finer, so the lower neighbour of 10^(length-1) is
     * 10^length - 1 one decade down.
     *
     * @return array{0:int,1:int}
     */
    private static function otherNeighbour(int $mantissa, int $exponent, int $length, float $value): array
    {
        if ((float) ($mantissa . 'e' . $exponent) < $value) {
            return [$mantissa + 1, $exponent];
        }
        $smallest = 10 ** ($length - 1);
        if ($mantissa === $smallest) {
            return [10 * $smallest - 1, $exponent - 1];
        }
        return [$mantissa - 1, $exponent];
    }

    private static function readsBackAs(int $mantissa, int $exponent, float $value): bool
    {
        // A numeric-string cast is `zend_strtod`: correctly rounded and locale-independent.
        return (float) ($mantissa . 'e' . $exponent) === $value;
    }

    /** @return array{0:string,1:int} */
    private static function normalized(int $mantissa, int $exponent): array
    {
        $digits = (string) $mantissa;
        $trimmed = \rtrim($digits, '0');
        $exponent += \strlen($digits) - \strlen($trimmed);
        return [$trimmed, \strlen($trimmed) + $exponent];
    }
}
