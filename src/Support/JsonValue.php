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
}
