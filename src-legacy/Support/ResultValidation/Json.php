<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

use Packvium\Validation\MalformedResult;

/**
 * The decoded JSON documents read the way the reference's Python reads them.
 *
 * Values are what `json_decode($text, true)` returns. An object and a list are both PHP
 * arrays, and `{}` and `[]` decode alike, so an empty array reads as either: every place the
 * reference tells them apart treats an empty object and an empty list the same way.
 */
final class Json
{
    /** @param mixed $value */
    public static function isDict($value): bool
    {
        return \is_array($value) && ($value === [] || !self::isList($value));
    }

    /** @param mixed $value */
    public static function isList($value): bool
    {
        if (!\is_array($value)) {
            return false;
        }
        $expected = 0;
        foreach (\array_keys($value) as $key) {
            if ($key !== $expected++) {
                return false;
            }
        }
        return true;
    }

    /** Python's `key in value` for an object; anything else does not hold it. @param mixed $value */
    public static function has($value, string $key): bool
    {
        return self::isDict($value) && \array_key_exists($key, $value);
    }

    /**
     * Python's `d.get(key, default)`: the default only when the key is absent, so a key present
     * as `null` still reads as `null`. A non-object has no `.get`.
     *
     * @param mixed $value @param mixed $default @return mixed
     */
    public static function get($value, string $key, $default = null)
    {
        if (!self::isDict($value)) {
            throw MalformedResult::shape(\sprintf('expected an object holding "%s", got %s', $key, self::kind($value)));
        }
        return \array_key_exists($key, $value) ? $value[$key] : $default;
    }

    /** Python's `d[key]`. @param mixed $value @return mixed */
    public static function at($value, string $key)
    {
        if (!self::isDict($value) || !\array_key_exists($key, $value)) {
            throw MalformedResult::shape(\sprintf('missing "%s"', $key));
        }
        return $value[$key];
    }

    /** Something the reference iterates; only a list is admitted. @param mixed $value @return list<mixed> */
    public static function items($value, string $what): array
    {
        if (!self::isList($value)) {
            throw MalformedResult::shape(\sprintf('%s must be a list, got %s', $what, self::kind($value)));
        }
        return $value;
    }

    /** @param mixed $value */
    public static function text($value, string $what): string
    {
        if (!\is_string($value)) {
            throw MalformedResult::shape(\sprintf('%s must be a string, got %s', $what, self::kind($value)));
        }
        return $value;
    }

    /**
     * An integer the reference does arithmetic on. A boolean is Python's `int`; a float would
     * carry the reference into float arithmetic this port does not follow, so it is refused.
     *
     * @param mixed $value
     */
    public static function int($value, string $what): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_bool($value)) {
            return (int)$value;
        }
        throw MalformedResult::shape(\sprintf('%s must be an integer, got %s', $what, self::kind($value)));
    }

    /** @param mixed $value */
    public static function optionalInt($value, string $what): ?int
    {
        return $value === null ? null : self::int($value, $what);
    }

    /** `isinstance(value, int)`, which a boolean satisfies. @param mixed $value */
    public static function intInstance($value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        return \is_bool($value) ? (int)$value : null;
    }

    /** `isinstance(value, int) and not isinstance(value, bool)`. @param mixed $value */
    public static function strictInt($value): ?int
    {
        return \is_int($value) ? $value : null;
    }

    /** Python's `int(value)`: a number truncates, a string parses as a base-10 integer. @param mixed $value @return int|string */
    public static function pyInt($value, string $what)
    {
        if (\is_int($value) || \is_bool($value)) {
            return (int)$value;
        }
        if (\is_float($value) && \is_finite($value)) {
            $truncated = $value < 0 ? \ceil($value) : \floor($value);
            if (\abs($truncated) < 9.0e18) {
                return (int)$truncated;
            }
            return Exact::normalize(\sprintf('%.0f', $truncated));
        }
        if (\is_string($value) && \preg_match('/^\s*([+-]?)(\d+(?:_\d+)*)\s*$/', $value, $parts) === 1) {
            $digits = \ltrim(\str_replace('_', '', $parts[2]), '0');
            $magnitude = Exact::normalize($digits === '' ? '0' : $digits);
            return $parts[1] === '-' ? Exact::negate($magnitude) : $magnitude;
        }
        throw MalformedResult::shape(\sprintf('%s: int() cannot convert %s', $what, self::kind($value)));
    }

    /** Python truthiness. @param mixed $value */
    public static function truthy($value): bool
    {
        if ($value === null) {
            return false;
        }
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return $value != 0;
        }
        if (\is_string($value)) {
            return $value !== '';
        }
        return $value !== [];
    }

    /**
     * Python `==`: a boolean equals its integer, an integer equals the float of the same value,
     * containers compare element-wise and objects regardless of key order.
     *
     * @param mixed $left @param mixed $right
     */
    public static function equals($left, $right): bool
    {
        $leftNumber = self::number($left);
        $rightNumber = self::number($right);
        if ($leftNumber !== null || $rightNumber !== null) {
            return $leftNumber !== null && $rightNumber !== null && self::compareNumbers($leftNumber, $rightNumber) === 0;
        }
        if ($left === null || $right === null) {
            return $left === $right;
        }
        if (\is_string($left) || \is_string($right)) {
            return \is_string($left) && \is_string($right) && $left === $right;
        }
        if (\count($left) !== \count($right)) {
            return false;
        }
        if (self::isList($left) && self::isList($right)) {
            foreach ($left as $index => $element) {
                if (!self::equals($element, $right[$index])) {
                    return false;
                }
            }
            return true;
        }
        foreach ($left as $key => $element) {
            if (!\array_key_exists($key, $right) || !self::equals($element, $right[$key])) {
                return false;
            }
        }
        return true;
    }

    /** `value == number` for an exact integer. @param mixed $value @param int|string $number */
    public static function equalsInt($value, $number): bool
    {
        $read = self::number($value);
        return $read !== null && self::compareNumbers($read, $number) === 0;
    }

    /** `value == text`. @param mixed $value */
    public static function isText($value, string $text): bool
    {
        return \is_string($value) && $value === $text;
    }

    /**
     * Python's ordering for what the reference sorts or compares: numbers with numbers, strings
     * with strings, lists element-wise. Anything else is Python's `TypeError`.
     *
     * @param mixed $left @param mixed $right
     */
    public static function compare($left, $right): int
    {
        $leftNumber = self::number($left);
        $rightNumber = self::number($right);
        if ($leftNumber !== null && $rightNumber !== null) {
            return self::compareNumbers($leftNumber, $rightNumber);
        }
        if (\is_string($left) && \is_string($right)) {
            return \strcmp($left, $right) <=> 0;
        }
        if (self::isList($left) && self::isList($right)) {
            foreach ($left as $index => $element) {
                if (!\array_key_exists($index, $right)) {
                    return 1;
                }
                if (!self::equals($element, $right[$index])) {
                    return self::compare($element, $right[$index]);
                }
            }
            return \count($left) <=> \count($right);
        }
        throw MalformedResult::shape(\sprintf('cannot order %s against %s', self::kind($left), self::kind($right)));
    }

    /**
     * The identity Python hashes a set member or dict key by, so `1`, `1.0` and `True` share one.
     * A list or an object is unhashable.
     *
     * @param mixed $value
     */
    public static function hashKey($value): string
    {
        $number = self::number($value);
        if ($number !== null) {
            return 'n' . (\is_float($number) ? \sprintf('%.17e', $number) : (string)$number);
        }
        if ($value === null) {
            return 'null';
        }
        if (\is_string($value)) {
            return 's' . $value;
        }
        throw MalformedResult::shape(self::kind($value) . ' is unhashable');
    }

    /** Sort values in place by Python's ordering. @param list<mixed> $values */
    public static function sort(array &$values): void
    {
        \usort($values, [self::class, 'compare']);
    }

    /** @param mixed $value */
    public static function kind($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (\is_bool($value)) {
            return 'a boolean';
        }
        if (\is_int($value)) {
            return 'an integer';
        }
        if (\is_float($value)) {
            return 'a number';
        }
        if (\is_string($value)) {
            return 'a string';
        }
        return self::isList($value) && $value !== [] ? 'a list' : 'an object';
    }

    /**
     * A JSON number as an exact integer when it is one (an integral float within range reads as
     * that integer, as Python compares it), else the float.
     *
     * @param mixed $value @return int|float|null
     */
    private static function number($value)
    {
        if (\is_bool($value)) {
            return (int)$value;
        }
        if (\is_int($value)) {
            return $value;
        }
        if (\is_float($value)) {
            return (\floor($value) === $value && \abs($value) < 9.0e18) ? (int)$value : $value;
        }
        return null;
    }

    /** @param int|float|string $left @param int|float|string $right */
    private static function compareNumbers($left, $right): int
    {
        if (\is_float($left) || \is_float($right)) {
            return (float)$left <=> (float)$right;
        }
        return Exact::compare($left, $right);
    }
}
