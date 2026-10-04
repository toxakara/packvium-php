<?php
declare(strict_types=1);
namespace Packvium\Support;

use Packvium\Unit\Length;
use Packvium\Validation\FixedPlacementException;
use stdClass;
use Throwable;

/**
 * The schema's shape of a request's `fixed_placements`, checked before anything is parsed.
 *
 * Held to `packvium.fixed_placements.require_fixed_placement_shapes`. Nothing is coerced: `"1"`,
 * `true` and `1.9` are not container instances, and a list is not a position -- each used to
 * be read as one, and a list position became the origin. The first entry out of shape is the
 * refusal, `invalid_fixed_placement` with reason `malformed` and the pointer of the bad value,
 * spelled as every engine spells it.
 */
final class FixedPlacementShape
{
    public const ORIENTATIONS = ['LWH', 'LHW', 'WLH', 'WHL', 'HLW', 'HWL'];
    private const REQUIRED = ['item_type', 'container_type', 'orientation'];
    private const FIELDS = ['item_type', 'container_type', 'orientation', 'container_instance', 'position'];
    private const AXES = ['x', 'y', 'z'];

    /**
     * The entries as JSON gave them; absent or null is none. O(f) for f entries.
     *
     * Entries arrive from `json_decode(..., true)`, where `{}` is the empty array, so an empty
     * array stands for an empty object wherever an object is required.
     *
     * @param mixed $raw
     * @return list<stdClass|array<string,mixed>>
     * @throws FixedPlacementException
     */
    public static function requireEntries($raw, string $unit = 'mm'): array
    {
        if ($raw === null) {
            return [];
        }
        if (!JsonValue::isList($raw)) {
            throw self::malformed('fixed_placements is a list', '/fixed_placements');
        }
        foreach ($raw as $index => $entry) {
            self::requireEntry($entry === [] ? new stdClass() : $entry, "fixed_placements[{$index}]", "/fixed_placements/{$index}", $unit);
        }
        return $raw;
    }

    /**
     * A point is an object of `x`, `y` and `z` measures; what a measure is, is the length
     * parser's business, except the values no parser should be handed at all.
     *
     * @param mixed $point
     * @param callable(string,string):Throwable $refuse builds the caller's own refusal from its
     *        message and the pointer of the bad value
     */
    public static function requirePoint($point, string $where, string $field, callable $refuse): void
    {
        if (!JsonValue::isObject($point)) {
            throw $refuse("{$where} is a point object", $field);
        }
        $unknown = JsonValue::namesOutside($point, self::AXES);
        if ($unknown !== []) {
            throw $refuse("{$where} cannot carry " . CanonicalJson::spelling($unknown), $field);
        }
        foreach (self::AXES as $axis) {
            $value = JsonValue::get($point, $axis);
            if (JsonValue::has($point, $axis) && ($value === null || \is_bool($value) || JsonValue::isList($value))) {
                throw $refuse("{$where}.{$axis} is a measure", "{$field}/{$axis}");
            }
        }
    }

    /** @param mixed $entry */
    private static function requireEntry($entry, string $where, string $field, string $unit): void
    {
        if (!JsonValue::isObject($entry)) {
            throw self::malformed("{$where} is an object", $field);
        }
        $unknown = JsonValue::namesOutside($entry, self::FIELDS);
        if ($unknown !== []) {
            throw self::malformed("{$where} cannot carry " . CanonicalJson::spelling($unknown), $field);
        }
        $missing = \array_values(\array_filter(self::REQUIRED, static fn(string $name): bool => !JsonValue::has($entry, $name)));
        if ($missing !== []) {
            throw self::malformed("{$where} needs " . CanonicalJson::spelling($missing), $field);
        }
        foreach (['item_type', 'container_type'] as $name) {
            $value = JsonValue::get($entry, $name);
            if (!\is_string($value) || $value === '') {
                throw self::malformed("{$where}.{$name} is a non-empty string", "{$field}/{$name}");
            }
        }
        if (!\in_array(JsonValue::get($entry, 'orientation'), self::ORIENTATIONS, true)) {
            throw self::malformed("{$where}.orientation is one of the six codes", "{$field}/orientation");
        }
        $instance = JsonValue::has($entry, 'container_instance') ? JsonValue::integer(JsonValue::get($entry, 'container_instance')) : 1;
        if ($instance === null || $instance < 1) {
            throw self::malformed("{$where}.container_instance counts from 1", "{$field}/container_instance");
        }
        $position = JsonValue::has($entry, 'position') ? JsonValue::get($entry, 'position') : new stdClass();
        $position = $position === [] ? new stdClass() : $position;
        self::requirePoint($position, "{$where}.position", "{$field}/position",
            static fn(string $message, string $at): Throwable => self::malformed($message, $at));
        foreach (self::AXES as $axis) {
            if (JsonValue::has($position, $axis)) {
                self::requireCoordinate(JsonValue::get($position, $axis), "{$where}.position.{$axis}", "{$field}/position/{$axis}", $unit);
            }
        }
    }

    /**
     * A coordinate is read in the request's length unit here, so a negative or unreadable one
     * is refused as a malformed placement rather than surfacing later as a bare parse error.
     *
     * @param mixed $value
     */
    private static function requireCoordinate($value, string $where, string $field, string $unit): void
    {
        $verdict = MeasureReading::verdict($value, Length::class, $unit);
        if ($verdict === MeasureReading::NEGATIVE) {
            throw self::malformed("{$where} cannot be negative", $field);
        }
        if ($verdict === MeasureReading::UNREADABLE) {
            throw self::malformed("{$where} is a measure", $field);
        }
    }

    private static function malformed(string $detail, string $field): FixedPlacementException
    {
        return new FixedPlacementException($detail, 'malformed', $field);
    }
}
