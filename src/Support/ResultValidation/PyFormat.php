<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

use Packvium\Support\ShortestFloat;

/**
 * Python's `str()` and `repr()` for the values an issue detail prints.
 *
 * The reference builds every detail with an f-string, so a detail carries Python's spelling of
 * whatever it names -- `None`, `True`, `'quoted'`, `(1, 2, 3)`, `1e-05` -- and matching it is what
 * lets one issue list be compared across languages byte for byte.
 */
final class PyFormat
{
    /** `str(value)`: a string as itself, anything else as its `repr`. @param mixed $value */
    public static function str($value): string
    {
        return \is_string($value) ? $value : self::repr($value);
    }

    /** @param mixed $value */
    public static function repr($value): string
    {
        if ($value === null) {
            return 'None';
        }
        if (\is_bool($value)) {
            return $value ? 'True' : 'False';
        }
        if (\is_int($value)) {
            return (string)$value;
        }
        if (\is_float($value)) {
            return self::floatRepr($value);
        }
        if (\is_string($value)) {
            return self::stringRepr($value);
        }
        if (Json::isList($value) && $value !== []) {
            return self::listOf(\array_map([self::class, 'repr'], $value));
        }
        $pairs = [];
        foreach ($value as $key => $element) {
            $pairs[] = self::stringRepr((string)$key) . ': ' . self::repr($element);
        }
        return $value === [] ? '[]' : '{' . \implode(', ', $pairs) . '}';
    }

    /** `[a, b]` from already-formatted elements. @param list<string> $elements */
    public static function listOf(array $elements): string
    {
        return '[' . \implode(', ', $elements) . ']';
    }

    /** `(a, b)`; a one-element tuple keeps its trailing comma. @param list<string> $elements */
    public static function tupleOf(array $elements): string
    {
        return \count($elements) === 1 ? '(' . $elements[0] . ',)' : '(' . \implode(', ', $elements) . ')';
    }

    /** @param list<int|string> $values */
    public static function intTuple(array $values): string
    {
        return self::tupleOf(\array_map('strval', $values));
    }

    /** @param list<int|string> $values */
    public static function intList(array $values): string
    {
        return self::listOf(\array_map('strval', $values));
    }

    /** @param list<mixed> $values */
    public static function valueTuple(array $values): string
    {
        return self::tupleOf(\array_map([self::class, 'repr'], $values));
    }

    public static function boolRepr(bool $flag): string
    {
        return $flag ? 'True' : 'False';
    }

    /** `f"{value:.6f}"`; PHP's `%f` and Python's both round the exact binary value half to even. */
    public static function fixed6(float $value): string
    {
        return \sprintf('%.6f', $value);
    }

    /**
     * `repr(float)`: the shortest digits that round-trip, in fixed notation for decimal exponents
     * from -4 to 15 and scientific (`1e-05`, `1e+16`) outside that.
     */
    public static function floatRepr(float $value): string
    {
        if (\is_nan($value)) {
            return 'nan';
        }
        if (\is_infinite($value)) {
            return $value > 0 ? 'inf' : '-inf';
        }
        // `atan2` is the one portable reading of negative zero's sign: -pi for -0.0, pi for 0.0.
        $sign = ($value < 0 || ($value == 0.0 && \atan2($value, -1.0) < 0)) ? '-' : '';
        if ($value == 0.0) {
            return $sign . '0.0';
        }
        [$digits, $point] = ShortestFloat::digits(\abs($value));
        $exponent = $point - 1;
        $count = \strlen($digits);
        if ($exponent >= -4 && $exponent < 16) {
            if ($point <= 0) {
                return $sign . '0.' . \str_repeat('0', -$point) . $digits;
            }
            if ($count <= $point) {
                return $sign . $digits . \str_repeat('0', $point - $count) . '.0';
            }
            return $sign . \substr($digits, 0, $point) . '.' . \substr($digits, $point);
        }
        $fraction = $count > 1 ? '.' . \substr($digits, 1) : '';
        return \sprintf('%s%s%se%s%02d', $sign, $digits[0], $fraction, $exponent < 0 ? '-' : '+', \abs($exponent));
    }

    /**
     * `repr(str)`: single quotes unless the text holds a single quote and no double quote,
     * Python's escapes for the backslash, the quote, `\t`, `\n`, `\r` and other non-printables.
     */
    public static function stringRepr(string $text): string
    {
        $quote = (\strpos($text, "'") !== false && \strpos($text, '"') === false) ? '"' : "'";
        $out = $quote;
        foreach (self::codePoints($text) as [$character, $code]) {
            if ($character === '\\') {
                $out .= '\\\\';
            } elseif ($character === "\t") {
                $out .= '\\t';
            } elseif ($character === "\n") {
                $out .= '\\n';
            } elseif ($character === "\r") {
                $out .= '\\r';
            } elseif ($character === $quote) {
                $out .= '\\' . $character;
            } elseif ($code < 0x20 || $code === 0x7f) {
                $out .= \sprintf('\\x%02x', $code);
            } elseif ($code < 0x7f || self::printable($code)) {
                $out .= $character;
            } elseif ($code <= 0xff) {
                $out .= \sprintf('\\x%02x', $code);
            } elseif ($code <= 0xffff) {
                $out .= \sprintf('\\u%04x', $code);
            } else {
                $out .= \sprintf('\\U%08x', $code);
            }
        }
        return $out . $quote;
    }

    /** @return list<array{0:string,1:int}> each UTF-8 character and its code point */
    private static function codePoints(string $text): array
    {
        $characters = \preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            $characters = \str_split($text);
        }
        $points = [];
        foreach ($characters as $character) {
            $bytes = \array_values(\unpack('C*', $character));
            $length = \count($bytes);
            if ($length === 1) {
                $code = $bytes[0];
            } elseif ($length === 2) {
                $code = (($bytes[0] & 0x1f) << 6) | ($bytes[1] & 0x3f);
            } elseif ($length === 3) {
                $code = (($bytes[0] & 0x0f) << 12) | (($bytes[1] & 0x3f) << 6) | ($bytes[2] & 0x3f);
            } else {
                $code = (($bytes[0] & 0x07) << 18) | (($bytes[1] & 0x3f) << 12) | (($bytes[2] & 0x3f) << 6) | ($bytes[3] & 0x3f);
            }
            $points[] = [$character, $code];
        }
        return $points;
    }

    /** Python's `str.isprintable` for a code point at or above U+007F, from the generated table. */
    private static function printable(int $code): bool
    {
        return !NonPrintable::contains($code);
    }
}
