<?php
declare(strict_types=1);
namespace Packvium\Commerce;

use Packvium\Commerce\Catalog\CatalogException;
use Packvium\Commerce\Policy\PolicyException;

/**
 * Shape validation for the commerce document and its requests.
 *
 * Every failure is reported with the JSON path that caused it, so a caller fixes the
 * field rather than bisecting the payload. Nothing here interprets a value's meaning --
 * the models keep the one definition of what is valid, and model() only re-labels their
 * own validation for the API boundary.
 */
final class Shape
{
    /**
     * @return never
     */
    public static function fail(string $path, string $message)
    {
        throw new CommerceInputException("{$path}: {$message}");
    }

    /** @return array<array-key,mixed>
     * @param mixed $value */
    public static function map($value, string $path): array
    {
        $arrayIsListFunction = function (array $array): bool {
            if (function_exists('array_is_list')) {
                return array_is_list($array);
            }
            if ($array === []) {
                return true;
            }
            $current_key = 0;
            foreach ($array as $key => $noop) {
                if ($key !== $current_key) {
                    return false;
                }
                ++$current_key;
            }
            return true;
        };
        if (!is_array($value) || (($value !== []) && $arrayIsListFunction($value))) { self::fail($path, 'expected an object'); }
        return $value;
    }

    /** @return list<mixed>
     * @param mixed $value */
    public static function listOf($value, string $path): array
    {
        $arrayIsListFunction = function (array $array): bool {
            if (function_exists('array_is_list')) {
                return array_is_list($array);
            }
            if ($array === []) {
                return true;
            }
            $current_key = 0;
            foreach ($array as $key => $noop) {
                if ($key !== $current_key) {
                    return false;
                }
                ++$current_key;
            }
            return true;
        };
        if (!is_array($value) || ($value !== [] && !$arrayIsListFunction($value))) { self::fail($path, 'expected a list'); }
        return $value;
    }

    /**
     * @param mixed $value
     */
    public static function integer($value, string $path): int
    {
        // A JSON boolean where an exact integer belongs is a caller mistake, not a 0 or 1.
        if (!is_int($value) || is_bool($value)) { self::fail($path, 'expected an exact integer'); }
        return $value;
    }

    /**
     * @param mixed $value
     */
    public static function text($value, string $path): string
    {
        if (!is_string($value)) { self::fail($path, 'expected a string'); }
        return $value;
    }

    /**
     * @param  list<string> $required
     * @param  list<string> $optional
     * @param  array<array-key,mixed> $value
     */
    public static function keys(array $value, string $path, array $required, array $optional = []): void
    {
        $present = array_map(\Closure::fromCallable('strval'), array_keys($value));
        $missing = array_values(array_diff($required, $present));
        if ($missing !== []) {
            sort($missing, SORT_STRING);
            self::fail($path, 'missing required key(s) [' . self::render($missing) . ']');
        }
        $unknown = array_values(array_diff($present, $required, $optional));
        if ($unknown !== []) {
            sort($unknown, SORT_STRING);
            self::fail($path, 'unrecognised key(s) [' . self::render($unknown) . ']');
        }
    }

    /** @return list<int>
     * @param mixed $value */
    public static function axes($value, string $path, int $axes): array
    {
        $entries = self::listOf($value, $path);
        if (count($entries) !== $axes) { self::fail($path, "expected exactly {$axes} axes"); }
        $parsed = [];
        foreach ($entries as $index => $entry) { $parsed[] = self::integer($entry, "{$path}[{$index}]"); }
        return $parsed;
    }

    /**
     * @template T of \BackedEnum
     * @param  class-string<T> $enum
     * @return T
     * @param mixed $value
     */
    public static function enumCase(string $enum, $value, string $path): \BackedEnum
    {
        $case = $enum::tryFrom(self::text($value, $path));
        if ($case === null) { self::fail($path, "unsupported value '" . self::text($value, $path) . "'"); }
        return $case;
    }

    /**
     * Run a model constructor, reporting its own validation as an input error.
     *
     * @template T
     * @param  callable():T $build
     * @return T
     */
    public static function model(string $path, callable $build)
    {
        try {
            return $build();
        } catch (\InvalidArgumentException|\TypeError|PolicyException|CatalogException $error) {
            if ($error instanceof CommerceInputException) { throw $error; }
            throw new CommerceInputException("{$path}: " . $error->getMessage(), 0, $error);
        }
    }

    /** @param list<string> $values */
    private static function render(array $values): string
    {
        return implode(', ', array_map(static function (string $value): string {
            return "'{$value}'";
        }, $values));
    }
}
