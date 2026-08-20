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
    public static function fail(string $path, string $message): never
    {
        throw new CommerceInputException("{$path}: {$message}");
    }

    /** @return array<array-key,mixed> */
    public static function map(mixed $value, string $path): array
    {
        if (!is_array($value) || (($value !== []) && array_is_list($value))) { self::fail($path, 'expected an object'); }
        return $value;
    }

    /** @return list<mixed> */
    public static function listOf(mixed $value, string $path): array
    {
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) { self::fail($path, 'expected a list'); }
        return $value;
    }

    public static function integer(mixed $value, string $path): int
    {
        // A JSON boolean where an exact integer belongs is a caller mistake, not a 0 or 1.
        if (!is_int($value) || is_bool($value)) { self::fail($path, 'expected an exact integer'); }
        return $value;
    }

    public static function text(mixed $value, string $path): string
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
        $present = array_map(strval(...), array_keys($value));
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

    /** @return list<int> */
    public static function axes(mixed $value, string $path, int $axes): array
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
     */
    public static function enumCase(string $enum, mixed $value, string $path): \BackedEnum
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
    public static function model(string $path, callable $build): mixed
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
        return implode(', ', array_map(static fn(string $value): string => "'{$value}'", $values));
    }
}
