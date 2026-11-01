<?php
declare(strict_types=1);
namespace Packvium\Support\Internal;

use Packvium\Domain\FlatBag;
use Packvium\Domain\MadeToMeasure;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;
use stdClass;
use Packvium\Support\{BigInt,CanonicalJson,JsonValue,MeasureReading,RequestRules};

/**
 * `RequestRules`' checks for fields an ordinary request does not carry: a made-to-measure
 * machine, a flat bag, a rate table and obstacles. Kept in their own file so PHP compiles
 * them only for a request that has them: without opcache every request compiles what it
 * loads (1.9.0 release frontier). `$call` reaches `RequestRules`' own private helpers.
 *
 * @internal
 */
final class RareRequestRules
{
    /** Every key the request schema's `made_to_measure` declares; it sets `additionalProperties: false`. */
    private const MADE_TO_MEASURE_FIELDS = ['cost_per_square_metre_minor', 'increment', 'minimum', 'outer_allowance',
        'tare_per_square_metre'];

    /**
     * The made-to-measure block's shape top to bottom, then each axis's four rules,
     * then the price ceiling. Run after every other container field, so an existing error
     * always wins. O(1).
     *
     * @param stdClass|array<array-key,mixed> $container
     * @param mixed $raw
     */
    public static function madeToMeasure(\Closure $call, $container, $raw, string $where, string $unit): void
    {
        $field = "{$where}/made_to_measure";
        $machine = $call('objectAt', $raw, $field);
        $call('knownFields', $machine, $field, self::MADE_TO_MEASURE_FIELDS);
        foreach (['minimum', 'increment'] as $name) {
            $call('required', $machine, $name, $field);
        }
        foreach (['minimum', 'increment', 'outer_allowance'] as $name) {
            $sides = JsonValue::get($machine, $name);
            if ($sides !== null) {
                $call('dimensions', $sides, "{$field}/{$name}", $unit, false);
            }
        }
        $call('optionalInteger', $machine, 'cost_per_square_metre_minor', $field, 0);
        $call('optionalMeasure', $machine, 'tare_per_square_metre', $field, Weight::class, RequestRules::WEIGHT_UNIT);
        if (JsonValue::get($container, 'outer_dimensions') !== null) {
            throw $call('refuse', 'not_allowed', "{$where}/outer_dimensions", 'cannot be combined with made_to_measure');
        }
        $inner = $call('sideTicks', JsonValue::get($container, 'inner_dimensions'), $unit);
        $minimum = $call('sideTicks', JsonValue::get($machine, 'minimum'), $unit);
        $increment = $call('sideTicks', JsonValue::get($machine, 'increment'), $unit);
        foreach (RequestRules::SIDES as $axis => $side) {
            if ($minimum[$axis] === 0) {
                throw $call('refuse', 'invalid_value', "{$field}/minimum/{$side}", 'must be positive');
            }
            if ($increment[$axis] === 0) {
                throw $call('refuse', 'invalid_value', "{$field}/increment/{$side}", 'must be positive');
            }
            if ($minimum[$axis] > $inner[$axis]) {
                throw $call('refuse', 'invalid_value', "{$field}/minimum/{$side}", 'exceeds inner_dimensions');
            }
            if (($inner[$axis] - $minimum[$axis]) % $increment[$axis] !== 0) {
                throw $call('refuse', 'invalid_value', "{$field}/increment/{$side}", 'does not divide inner_dimensions minus minimum');
            }
        }
        $price = JsonValue::integer(JsonValue::get($machine, 'cost_per_square_metre_minor') ?? 0);
        $fee = JsonValue::integer(JsonValue::get($container, 'cost_minor') ?? 0);
        if (BigInt::compare(MadeToMeasure::boxCostFor((int) $price, (int) $fee, $inner), MadeToMeasure::MAX_EXACT_INTEGER) > 0) {
            throw $call('refuse', 'invalid_value', "{$field}/cost_per_square_metre_minor", 'prices the largest box above ' . CanonicalJson::MAX_EXACT_MAGNITUDE);
        }
    }

    /**
     * The bag's own shape, then every container field a flat bag refuses, in the
     * order of `BAG_EXCLUSIONS`.
     *
     * @param stdClass|array<array-key,mixed> $container
     * @param mixed $raw
     */
    public static function deformableBoundary(\Closure $call, $container, $raw, string $where): void
    {
        $field = "{$where}/deformable_boundary";
        $boundary = $call('objectAt', $raw, $field);
        if (!\in_array($call('required', $boundary, 'kind', $field), FlatBag::KINDS, true)) {
            throw $call('refuse', 'not_allowed', "{$field}/kind", 'must be one of ' . CanonicalJson::spelling(FlatBag::KINDS));
        }
        $call('knownFields', $boundary, $field, ['kind']);
        foreach (RequestRules::BAG_EXCLUSIONS as $name) {
            if ($call('setBesideABag', $name, JsonValue::get($container, $name))) {
                throw $call('refuse', 'not_allowed', "{$where}/{$name}", 'cannot be combined with deformable_boundary');
            }
        }
    }

    /** @param mixed $raw */
    public static function rateTable(\Closure $call, $raw, string $where): void
    {
        $table = $call('objectAt', $raw, $where);
        foreach (['weight_brackets_g' => 1, 'prices_minor' => 0] as $name => $minimum) {
            $values = JsonValue::get($table, $name);
            if ($values === null) {
                continue;
            }
            foreach ($call('listAt', $values, "{$where}/{$name}") as $index => $value) {
                $call('integer', $value, "{$where}/{$name}/{$index}", $minimum);
            }
        }
        $call('optionalInteger', $table, 'minimum_charge_minor', $where, 0);
        $call('optionalInteger', $table, 'fuel_surcharge_permille', $where, 0);
    }

    /** @param mixed $raw @param array{0:int,1:int,2:int} $inner */
    public static function obstacles(\Closure $call, $raw, string $where, string $unit, array $inner): void
    {
        foreach ($call('listAt', $raw, $where) as $index => $entry) {
            $at = "{$where}/{$index}";
            $obstacle = $call('objectAt', $entry, $at);
            self::placedBox($call, $obstacle, $at, $unit, $inner);
            $extra = JsonValue::get($obstacle, 'additional_boxes');
            if ($extra !== null) {
                foreach ($call('listAt', $extra, "{$at}/additional_boxes") as $position => $box) {
                    $part = "{$at}/additional_boxes/{$position}";
                    self::placedBox($call, $call('objectAt', $box, $part), $part, $unit, $inner);
                }
            }
        }
    }

    /**
     * An optional `origin` and required `dimensions`: an obstacle, or one more box of it
     *, whose measures are read in the request's unit like every other length, and
     * which lies inside the container.
     *
     * @param stdClass|array<array-key,mixed> $box @param array{0:int,1:int,2:int} $inner
     */
    public static function placedBox(\Closure $call, $box, string $at, string $unit, array $inner): void
    {
        $origin = JsonValue::get($box, 'origin');
        if ($origin !== null) {
            $point = $call('objectAt', $origin, "{$at}/origin");
            foreach (['x', 'y', 'z'] as $axis) {
                $call('optionalMeasure', $point, $axis, "{$at}/origin", Length::class, $unit);
            }
        }
        $call('dimensions', $call('required', $box, 'dimensions', $at), "{$at}/dimensions", $unit);
        $sides = $call('sideTicks', JsonValue::get($box, 'dimensions'), $unit);
        foreach (['x', 'y', 'z'] as $index => $axis) {
            $start = $origin === null ? null : JsonValue::get($origin, $axis);
            $offset = $start === null ? 0 : MeasureReading::ticks($start, Length::class, $unit);
            if ($offset + $sides[$index] > $inner[$index]) {
                throw $call('refuse', 'invalid_value', $at, 'lies outside inner_dimensions');
            }
        }
    }

    // ------------------------------------------------------------------------- primitives
}
