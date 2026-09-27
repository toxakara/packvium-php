<?php
declare(strict_types=1);
namespace Packvium\Support;

use stdClass;

/**
 * A decoded JSON value, read the way Python reads what `json.loads` returns.
 *
 * PHP has one array type for two JSON types, so every reader here shares one rule: a PHP
 * list -- keys 0..n-1 in order, `[]` included -- is a JSON array, and a `stdClass` or any
 * other array is a JSON object. Text decoded with objects as `stdClass` keeps `{}` and
 * `{"0":"a"}` exactly; an associative array cannot, because PHP turns `"0"` into the integer
 * key 0 and has no empty object.
 */
final class JsonValue
{
    /** @param mixed $value */
    public static function isList($value): bool
    {
        if (!\is_array($value)) {
            return false;
        }
        // Stops at the first key out of place, so an associative array answers in O(1).
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }

    /**
     * The integer a JSON number is, judged by value as every engine can judge it: `1.0` is 1,
     * because JavaScript cannot tell them apart once the text is parsed; a boolean, a string,
     * `1.5` and anything past 2^53 - 1 -- which JavaScript no longer holds exactly, and which
     * `json_decode` may already have turned into a float -- are not integers. The bound also
     * means `+ 1` on a returned value can never overflow.
     *
     * @param mixed $value
     */
    public static function integer($value): ?int
    {
        if (\is_int($value)) {
            return \abs($value) <= CanonicalJson::MAX_EXACT_MAGNITUDE ? $value : null;
        }
        if (!\is_float($value) || !\is_finite($value) || \floor($value) !== $value
            || \abs($value) > CanonicalJson::MAX_EXACT_MAGNITUDE) {
            return null;
        }
        return (int) $value;
    }

    /** @param mixed $value */
    public static function isObject($value): bool
    {
        return $value instanceof stdClass || (\is_array($value) && !self::isList($value));
    }

    /**
     * Python's `name in mapping`: false for anything that is not an object.
     *
     * @param mixed $object
     */
    public static function has($object, string $name): bool
    {
        if ($object instanceof stdClass) {
            return \property_exists($object, $name);
        }
        return \is_array($object) && !self::isList($object) && \array_key_exists($name, $object);
    }

    /**
     * Python's `mapping.get(name)`: null when the member is absent, and when the value is not
     * an object at all.
     *
     * @param mixed $object
     * @return mixed
     */
    public static function get($object, string $name)
    {
        if (!self::has($object, $name)) {
            return null;
        }
        return $object instanceof stdClass ? $object->{$name} : $object[$name];
    }

    /**
     * An object's members in the order they were written, each name as a string -- an array
     * key `5` and a property `"5"` are the same JSON name.
     *
     * @param stdClass|array<array-key,mixed> $object
     * @return list<array{0:string,1:mixed}>
     */
    public static function members($object): array
    {
        $members = [];
        foreach ($object as $name => $value) {
            $members[] = [(string) $name, $value];
        }
        return $members;
    }

    /**
     * An object's member names that are not in `$allowed`, sorted by code point -- which is
     * UTF-8 byte order -- so every engine lists them alike. Names are strings even where PHP
     * made an array key or a property name numeric.
     *
     * @param stdClass|array<array-key,mixed> $object
     * @param list<string> $allowed
     * @return list<string>
     */
    public static function namesOutside($object, array $allowed): array
    {
        $unknown = [];
        foreach (self::members($object) as [$name]) {
            if (!\in_array($name, $allowed, true)) {
                $unknown[] = $name;
            }
        }
        \sort($unknown, \SORT_STRING);
        return $unknown;
    }
}
