<?php
declare(strict_types=1);
namespace Packvium\Support;

use Packvium\Domain\Placement;
use Packvium\Domain\Point;
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
    private const REQUIRED = ['item_type', 'container_type', 'orientation'];
    private const FIELDS = ['item_type', 'container_type', 'orientation', 'container_instance', 'position', 'placement_id'];
    private const AXES = ['x', 'y', 'z'];

    /**
     * The entries as JSON gave them. O(f) for f entries; an absent list never reaches here.
     *
     * Entries arrive from `json_decode(..., true)`, where `{}` is the empty array, so an empty
     * array stands for an empty object wherever an object is required. `$hullTypes` names the
     * item types that are hulls, the only ones a signed orientation may fix (1.10).
     *
     * @param mixed $raw
     * @param list<string> $hullTypes
     * @return list<stdClass|array<string,mixed>>
     * @throws FixedPlacementException
     */
    public static function requireEntries($raw, string $unit = 'mm', array $hullTypes = []): array
    {
        if (!JsonValue::isList($raw)) {
            throw self::malformed('fixed_placements is a list', '/fixed_placements');
        }
        foreach ($raw as $index => $entry) {
            self::requireEntry($entry === [] ? new stdClass() : $entry, "fixed_placements[{$index}]", "/fixed_placements/{$index}", $unit, $hullTypes);
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

    /**
     * The ids of the requested item types that are `convex_hull`, read from JSON items as
     * written; anything that is not an item object names no hull. O(items).
     *
     * @param mixed $items
     * @return list<string>
     */
    public static function hullItemTypes($items): array
    {
        if (!JsonValue::isList($items)) {
            return [];
        }
        $hulls = [];
        foreach ($items as $item) {
            if (JsonValue::isObject($item) && JsonValue::get($item, 'shape_type') === 'convex_hull' && \is_string(JsonValue::get($item, 'id'))) {
                $hulls[] = JsonValue::get($item, 'id');
            }
        }
        return $hulls;
    }

    /**
     * A fixed orientation from its item's vocabulary (1.10): an unknown code is `malformed` with
     * the vocabulary listed -- six codes for a box, byte-identical to every release before -- and
     * a signed code fixing a non-hull is `invalid_value`. Whether the item allows it is
     * `FixedPlacementAdmission`'s rule. O(1).
     *
     * @param mixed $value
     * @throws FixedPlacementException
     */
    public static function requireOrientation($value, bool $hull, string $where, string $field): void
    {
        $refusal = RequestRules::orientationRefusal($value, $hull, "{$where}.orientation");
        if ($refusal !== null) {
            throw new FixedPlacementException($refusal[1], $refusal[0] === 'not_allowed' ? 'malformed' : $refusal[0], $field);
        }
    }

    /**
     * @param mixed $entry
     * @param list<string> $hullTypes
     */
    private static function requireEntry($entry, string $where, string $field, string $unit, array $hullTypes): void
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
        self::requireOrientation(JsonValue::get($entry, 'orientation'), \in_array(JsonValue::get($entry, 'item_type'), $hullTypes, true), $where, "{$field}/orientation");
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
        $ticks = \array_map(static fn(string $axis): int => JsonValue::has($position, $axis) ? MeasureReading::ticks(JsonValue::get($position, $axis), Length::class, $unit) : 0, self::AXES);
        self::requirePlacementId(JsonValue::get($entry, 'placement_id'), JsonValue::get($entry, 'container_type') . "#{$instance}", new Point(...$ticks),
            JsonValue::get($entry, 'item_type'), JsonValue::get($entry, 'orientation'), $where, $field);
    }

    /**
     * A fixed placement's optional `placement_id` (1.10) restates the id its result placement
     * will carry, the canonical JSON of `[<container_type>#<container_instance>, x, y, z,
     * <item_type>, <orientation>]` with the position in ticks; null is absent. A non-string is
     * `malformed`, like every other shape error here; a string naming another placement is
     * `invalid_value`, the reason a well-typed value outside its rule carries. O(1).
     *
     * @param mixed $value
     * @throws FixedPlacementException
     */
    public static function requirePlacementId($value, string $containerId, Point $position, string $itemType, string $orientation, string $where, string $field): void
    {
        if ($value === null) {
            return;
        }
        $at = "{$field}/placement_id";
        if (!\is_string($value)) {
            throw self::malformed("{$where}.placement_id is a string", $at);
        }
        // A coordinate past 2^53 - 1 has no canonical spelling, and no container holds it: the
        // admission check refuses such an entry as `outside_container`, so the id is not judged.
        if (\max($position->x, $position->y, $position->z) > JsonValue::MAX_EXACT_MAGNITUDE) {
            return;
        }
        $expected = Placement::identifier($containerId, $position, $itemType, $orientation);
        if ($value !== $expected) {
            throw new FixedPlacementException("{$where}.placement_id must be " . CanonicalJson::spelling($expected), 'invalid_value', $at);
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
        if ($verdict !== MeasureReading::READABLE) {
            throw self::malformed("{$where} is a measure", $field);
        }
    }

    private static function malformed(string $detail, string $field): FixedPlacementException
    {
        return new FixedPlacementException($detail, 'malformed', $field);
    }
}
