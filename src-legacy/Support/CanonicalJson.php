<?php
declare(strict_types=1);
namespace Packvium\Support;

/**
 * RFC 8785 canonical JSON: the one spelling four engines can agree on byte for byte.
 *
 * Held byte-identical to `packvium._canonical_json`, and it settles each place PHP's own
 * `json_encode` disagrees with the other engines: `serialize_precision` decides its float
 * digits, it escapes U+2028 by default, and `ksort` orders keys by byte rather than by
 * UTF-16 code unit.
 *
 * - Object keys are sorted by their UTF-16 code units.
 * - Strings escape `"`, `\`, and U+0000..U+001F (`\b \t \n \f \r` by name, the rest as
 *   lowercase `\u00xx`); everything else is written as UTF-8.
 * - Numbers are written as ECMAScript's `Number::toString` writes them.
 * - No whitespace.
 *
 * A number whose magnitude exceeds 2^53 - 1, or that is not finite, is refused
 * (`number_out_of_range`); so is a string that is not valid UTF-8 (`invalid_string`), which
 * is where a lone surrogate ends up in PHP, and any value JSON has no type for
 * (`invalid_value`).
 *
 * PHP needs one rule of its own, the one in {@see JsonValue}: a list is a JSON array, and a
 * `stdClass` or any other array is a JSON object. An array key is a JSON name, so `[5 => 'a']`
 * spells `{"5":"a"}`.
 *
 * O(N + K log K) for N bytes of output and K members in the largest object.
 */
final class CanonicalJson
{
    /** The largest magnitude every engine holds exactly. */
    public const MAX_EXACT_MAGNITUDE = 9007199254740991;

    /**
     * @param mixed $value
     * @throws CanonicalJsonException
     */
    public static function encode($value): string
    {
        $out = '';
        self::write($value, $out);
        return $out;
    }

    /** @param mixed $value */
    private static function write($value, string &$out): void
    {
        if ($value === null) {
            $out .= 'null';
        } elseif (\is_bool($value)) {
            $out .= $value ? 'true' : 'false';
        } elseif (\is_string($value)) {
            $out .= self::string($value);
        } elseif (\is_int($value)) {
            $out .= self::integer($value);
        } elseif (\is_float($value)) {
            $out .= self::number($value);
        } elseif (JsonValue::isList($value)) {
            self::writeArray($value, $out);
        } elseif (JsonValue::isObject($value)) {
            self::writeObject($value, $out);
        } else {
            $type = \is_object($value) ? \get_class($value) : \gettype($value);
            throw new CanonicalJsonException('invalid_value', "a {$type} has no JSON spelling");
        }
    }

    /** @param list<mixed> $values */
    private static function writeArray(array $values, string &$out): void
    {
        $out .= '[';
        $first = true;
        foreach ($values as $element) {
            if (!$first) {
                $out .= ',';
            }
            $first = false;
            self::write($element, $out);
        }
        $out .= ']';
    }

    /** @param \stdClass|array<array-key,mixed> $object */
    private static function writeObject($object, string &$out): void
    {
        $members = [];
        // Every name is spelled -- and so checked -- before any value is written, as the
        // reference does.
        foreach (JsonValue::members($object) as [$name, $value]) {
            $members[] = [self::utf16Order($name), self::string($name), $value];
        }
        \usort($members, static function (array $left, array $right): int {
            return \strcmp($left[0], $right[0]);
        });
        $out .= '{';
        $first = true;
        foreach ($members as [, $spelled, $value]) {
            if (!$first) {
                $out .= ',';
            }
            $first = false;
            $out .= $spelled . ':';
            self::write($value, $out);
        }
        $out .= '}';
    }

    /**
     * A byte string that sorts as the name's UTF-16 code units do, without mbstring or iconv.
     *
     * UTF-8 bytes sort by code point, which agrees with UTF-16 everywhere except one place:
     * U+E000..U+FFFF sort after the supplementary planes in UTF-16, whose high surrogates are
     * U+D800..U+DBFF. Those characters are exactly the ones whose lead byte is 0xEE or 0xEF, a
     * continuation byte is never either, and 0xF5 and 0xF6 never occur in UTF-8 -- so moving
     * the two lead bytes there moves exactly those characters past the supplementary planes.
     * The name has already been checked as valid UTF-8.
     */
    private static function utf16Order(string $name): string
    {
        return \strtr($name, "\xEE\xEF", "\xF5\xF6");
    }

    private static function string(string $text): string
    {
        // PCRE's UTF-8 check refuses overlong forms, encoded surrogates and code points past
        // U+10FFFF, and PCRE is always compiled into PHP.
        if (\preg_match('//u', $text) !== 1) {
            throw new CanonicalJsonException('invalid_string', 'a string is not valid UTF-8 or carries a UTF-16 surrogate');
        }
        return '"' . \strtr($text, self::escapes()) . '"';
    }

    /** @return array<string,string> */
    private static function escapes(): array
    {
        static $escapes = null;
        if ($escapes === null) {
            $escapes = [
                '"' => '\\"', '\\' => '\\\\', "\x08" => '\\b', "\t" => '\\t',
                "\n" => '\\n', "\x0C" => '\\f', "\r" => '\\r',
            ];
            for ($code = 0; $code < 0x20; $code++) {
                $escapes[\chr($code)] = $escapes[\chr($code)] ?? \sprintf('\\u%04x', $code);
            }
        }
        return $escapes;
    }

    private static function integer(int $value): string
    {
        if ($value > self::MAX_EXACT_MAGNITUDE || $value < -self::MAX_EXACT_MAGNITUDE) {
            throw new CanonicalJsonException('number_out_of_range', "{$value} is beyond what every engine holds exactly");
        }
        return (string) $value;
    }

    private static function number(float $value): string
    {
        if (!\is_finite($value) || \abs($value) > self::MAX_EXACT_MAGNITUDE) {
            throw new CanonicalJsonException('number_out_of_range', 'a float is beyond what every engine holds exactly');
        }
        if ($value === 0.0) {
            return '0';
        }
        [$digits, $point] = ShortestFloat::digits(\abs($value));
        $count = \strlen($digits);
        if ($count <= $point && $point <= 21) {
            $rendered = $digits . \str_repeat('0', $point - $count);
        } elseif (0 < $point && $point <= 21) {
            $rendered = \substr($digits, 0, $point) . '.' . \substr($digits, $point);
        } elseif (-6 < $point && $point <= 0) {
            $rendered = '0.' . \str_repeat('0', -$point) . $digits;
        } else {
            $power = $point - 1;
            $rendered = $digits[0] . ($count > 1 ? '.' . \substr($digits, 1) : '')
                . 'e' . ($power >= 0 ? '+' : '-') . \abs($power);
        }
        return ($value < 0 ? '-' : '') . $rendered;
    }
}
