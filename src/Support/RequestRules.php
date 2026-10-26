<?php
declare(strict_types=1);
namespace Packvium\Support;

use Packvium\Domain\FlatBag;
use Packvium\Domain\MadeToMeasure;
use Packvium\Serialization\InvalidRequestException;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;
use stdClass;

/**
 * The request schema's rules, walked over the decoded JSON before any model is built.
 *
 * Held to `packvium.request_errors.check_request`: the first violation wins, in the order every
 * engine follows -- units, output, configuration, items, containers -- and is refused with the same
 * reason, JSON Pointer and message the reference raises.
 *
 * The request arrives from `json_decode(..., true)`, where `{}` and `[]` are the same empty
 * array, so an empty array is accepted wherever an object or a list is expected; a non-empty
 * list where an object is expected is the wrong type. O(size of the request).
 */
final class RequestRules
{
    public const SOLVER_PROFILES = ['fast', 'balanced', 'quality', 'exact_small'];
    public const OBJECTIVES = ['default', 'lowest_cost', 'shipping_cost', 'lowest_landed_cost', 'open_dimension_height', 'maximum_value', 'maximum_volume'];
    public const ACCESS_DIRECTIONS = ['+x', '-x', '+y', '-y', '+z', '-z'];
    /** The schema's closed vocabularies, each refused as `not_allowed` like the two above. */
    public const SOLVERS = ['grid', 'extreme_points', 'homogeneous_blocks', 'layer', 'maximal_spaces', 'exact_small'];
    public const ROTATIONS = ['LWH', 'LHW', 'WLH', 'WHL', 'HLW', 'HWL'];
    public const LENGTH_UNITS = ['mm', 'cm', 'm', 'in', 'ft'];
    public const WEIGHT_UNITS = ['mg', 'g', 'kg', 'oz', 'lb'];
    public const GROUND_CONTACT_RULES = ['free', 'covered', 'single', 'multiple'];
    public const SHAPE_TYPES = ['rigid_cuboid', 'convex_hull', 'compressible'];

    /** Shared with `RequestObjects`, which walks the same fields over a request of objects. */
    public const CONFIGURATION_INTEGERS = [
        'time_limit_ms' => 1, 'alternatives' => 1, 'max_containers' => 1, 'exact_item_limit' => 1,
        'multi_start_orders' => 1, 'max_candidates_per_item' => 1, 'max_candidate_points' => 16,
        'container_plan_beam_width' => 1, 'container_plan_node_limit' => 1, 'dimensional_weight_divisor' => 1,
    ];
    public const EFFORT_LIMITS = ['max_candidates_evaluated', 'max_placement_attempts', 'max_search_nodes', 'max_restarts'];
    /** Every key the request schema's `configuration` declares; it sets `additionalProperties: false`. */
    private const CONFIGURATION_FIELDS = [
        'alternatives', 'clearance', 'container_plan_beam_width', 'container_plan_node_limit',
        'dimensional_weight_divisor', 'dimensional_weight_length_unit', 'dimensional_weight_weight_unit',
        'effort_budget', 'exact_item_limit', 'max_candidate_points', 'max_candidates_per_item', 'max_containers',
        'minimum_support_ratio', 'multi_start_orders', 'objective', 'require_placement_coordinates', 'seed',
        'solver_profile', 'solvers', 'time_limit_ms',
    ];
    private const SIDES = ['length', 'width', 'height'];
    /** The item fields the request schema types `boolean`, in schema order. */
    private const ITEM_BOOLEANS = ['keep_upright', 'stackable', 'must_be_on_floor'];
    /** Container fields a flat bag refuses, in reporting order (docs/DEFORMABLE-CONTAINERS.md). */
    public const BAG_EXCLUSIONS = ['obstacles', 'axles', 'access_directions', 'max_stack_density',
        'outer_dimensions', 'void_fill_reserve_ratio', 'made_to_measure'];
    /** Every key the request schema's `made_to_measure` declares; it sets `additionalProperties: false`. */
    private const MADE_TO_MEASURE_FIELDS = ['cost_per_square_metre_minor', 'increment', 'minimum', 'outer_allowance',
        'tare_per_square_metre'];
    private const WEIGHT_UNIT = 'g';

    /**
     * Refuse the request with its first violation, or return.
     *
     * @param mixed $data
     * @throws InvalidRequestException
     */
    public static function check($data): void
    {
        $request = self::objectAt($data, '');
        $unit = self::units(JsonValue::get($request, 'units'));
        self::output(JsonValue::get($request, 'output'));
        self::configuration(JsonValue::get($request, 'configuration'), $unit);
        $items = self::requiredList($request, 'items', '');
        foreach ($items as $index => $raw) {
            self::item($raw, "/items/{$index}", $unit);
        }
        self::requireUniqueIds(self::identifiers($items), 'items');
        $containers = self::requiredList($request, 'containers', '');
        foreach ($containers as $index => $raw) {
            self::container($raw, "/containers/{$index}", $unit);
        }
        self::requireUniqueIds(self::identifiers($containers), 'containers');
    }

    /** The RFC 6901 pointer to one member, escaping `~` and `/` in its name. */
    public static function pointer(string $base, string $name): string
    {
        return $base . '/' . \str_replace(['~', '/'], ['~0', '~1'], $name);
    }

    // -------------------------------------------------------------------------- the rules

    /** @param mixed $raw */
    private static function units($raw): string
    {
        if ($raw === null) {
            return 'mm';
        }
        $length = JsonValue::get(self::objectAt($raw, '/units'), 'length');
        if ($length === null) {
            return 'mm';
        }
        if (!\is_string($length) || !MeasureReading::knowsUnit(Length::class, $length)) {
            throw self::refuse('invalid_unit', '/units/length', 'has an unknown unit ' . CanonicalJson::spelling($length));
        }
        return $length;
    }

    /** @param mixed $raw */
    private static function output($raw): void
    {
        if ($raw === null) {
            return;
        }
        $output = self::objectAt($raw, '/output');
        self::optionalOneOf($output, 'length_unit', '/output', self::LENGTH_UNITS);
        self::optionalOneOf($output, 'weight_unit', '/output', self::WEIGHT_UNITS);
    }

    /** @param mixed $raw */
    private static function configuration($raw, string $unit): void
    {
        if ($raw === null) {
            return;
        }
        $where = '/configuration';
        $configuration = self::objectAt($raw, $where);
        self::knownFields($configuration, $where, self::CONFIGURATION_FIELDS);
        self::optionalOneOf($configuration, 'solver_profile', $where, self::SOLVER_PROFILES);
        self::optionalOneOf($configuration, 'objective', $where, self::OBJECTIVES);
        self::optionalEachOneOf($configuration, 'solvers', $where, self::SOLVERS);
        self::optionalOneOf($configuration, 'dimensional_weight_length_unit', $where, self::LENGTH_UNITS);
        self::optionalOneOf($configuration, 'dimensional_weight_weight_unit', $where, self::WEIGHT_UNITS);
        foreach (self::CONFIGURATION_INTEGERS as $name => $minimum) {
            self::optionalInteger($configuration, $name, $where, $minimum);
        }
        self::optionalRatio($configuration, 'minimum_support_ratio', $where, 1);
        self::optionalMeasure($configuration, 'clearance', $where, Length::class, $unit);
        self::optionalBoolean($configuration, 'require_placement_coordinates', $where);
        $effort = JsonValue::get($configuration, 'effort_budget');
        if ($effort !== null) {
            $budget = self::objectAt($effort, "{$where}/effort_budget");
            self::knownFields($budget, "{$where}/effort_budget", self::EFFORT_LIMITS);
            foreach (self::EFFORT_LIMITS as $name) {
                self::optionalInteger($budget, $name, "{$where}/effort_budget", 1);
            }
        }
    }

    /**
     * A nested copy sinks by less than its own height, or it would vanish into the one below
     *. Both measures have passed their own rules already. O(1).
     *
     * @param stdClass|array<array-key,mixed> $item
     */
    private static function nestingBelowHeight($item, string $where, string $unit): void
    {
        $nesting = JsonValue::get($item, 'nesting_height');
        if ($nesting === null) {
            return;
        }
        $height = JsonValue::get(JsonValue::get($item, 'dimensions'), 'height');
        if (MeasureReading::ticks($nesting, Length::class, $unit) >= MeasureReading::ticks($height, Length::class, $unit)) {
            throw self::refuse('invalid_value', "{$where}/nesting_height", "must be less than the item's height");
        }
    }

    /** @param mixed $raw */
    private static function item($raw, string $where, string $unit): void
    {
        $item = self::objectAt($raw, $where);
        self::requiredString($item, 'id', $where);
        self::optionalInteger($item, 'quantity', $where, 1);
        self::dimensions(self::required($item, 'dimensions', $where), "{$where}/dimensions", $unit);
        self::optionalMeasure($item, 'weight', $where, Weight::class, self::WEIGHT_UNIT);
        self::optionalMeasure($item, 'max_top_load', $where, Weight::class, self::WEIGHT_UNIT);
        self::optionalMeasure($item, 'nesting_height', $where, Length::class, $unit);
        self::nestingBelowHeight($item, $where, $unit);
        self::optionalInteger($item, 'max_stacked_items', $where, 1);
        self::optionalInteger($item, 'stop_index', $where, 0);
        self::optionalInteger($item, 'value', $where, 0);
        self::optionalInteger($item, 'max_compression_pressure_kpa', $where, 0);
        self::optionalRatio($item, 'minimum_support_ratio', $where, 1);
        self::optionalRatio($item, 'compression_ratio', $where, null);
        $stackingGroup = JsonValue::get($item, 'stacking_group');
        if ($stackingGroup !== null) {
            self::stackingGroupName($stackingGroup, "{$where}/stacking_group");
        }
        $accepts = JsonValue::get($item, 'accepts_stacking_groups');
        if ($accepts !== null) {
            foreach (self::listAt($accepts, "{$where}/accepts_stacking_groups") as $index => $name) {
                self::stackingGroupName($name, "{$where}/accepts_stacking_groups/{$index}");
            }
        }
        self::optionalEachOneOf($item, 'allowed_rotations', $where, self::ROTATIONS);
        self::optionalOneOf($item, 'ground_contact_rule', $where, self::GROUND_CONTACT_RULES);
        self::optionalOneOf($item, 'shape_type', $where, self::SHAPE_TYPES);
        foreach (self::ITEM_BOOLEANS as $name) {
            self::optionalBoolean($item, $name, $where);
        }
    }

    /** @param mixed $value */
    public static function stackingGroupName($value, string $field): void
    {
        if (!\is_string($value)) {
            throw self::refuse('wrong_type', $field, 'must be a string');
        }
        if ($value === '') {
            throw self::refuse('invalid_value', $field, 'must not be empty');
        }
    }

    /** @param mixed $raw */
    private static function container($raw, string $where, string $unit): void
    {
        $container = self::objectAt($raw, $where);
        self::requiredString($container, 'id', $where);
        self::optionalInteger($container, 'quantity', $where, 1);
        self::dimensions(self::required($container, 'inner_dimensions', $where), "{$where}/inner_dimensions", $unit);
        $outer = JsonValue::get($container, 'outer_dimensions');
        if ($outer !== null) {
            self::dimensions($outer, "{$where}/outer_dimensions", $unit);
        }
        foreach (['tare_weight', 'max_payload', 'max_stack_density'] as $name) {
            self::optionalMeasure($container, $name, $where, Weight::class, self::WEIGHT_UNIT);
        }
        self::optionalInteger($container, 'max_items', $where, 1);
        self::optionalInteger($container, 'cost_minor', $where, 0);
        self::optionalRatio($container, 'void_fill_reserve_ratio', $where, 1);
        $accessDirections = JsonValue::get($container, 'access_directions');
        if ($accessDirections !== null) {
            self::accessDirections($accessDirections, "{$where}/access_directions");
        }
        $tagLimits = JsonValue::get($container, 'tag_limits');
        if ($tagLimits !== null) {
            self::tagLimits($tagLimits, "{$where}/tag_limits");
        }
        $rateTable = JsonValue::get($container, 'rate_table');
        if ($rateTable !== null) {
            self::rateTable($rateTable, "{$where}/rate_table");
        }
        $obstacles = JsonValue::get($container, 'obstacles');
        if ($obstacles !== null) {
            self::obstacles($obstacles, "{$where}/obstacles", $unit);
        }
        $boundary = JsonValue::get($container, 'deformable_boundary');
        if ($boundary !== null) {
            self::deformableBoundary($container, $boundary, $where);
        }
        $machine = JsonValue::get($container, 'made_to_measure');
        if ($machine !== null) {
            self::madeToMeasure($container, $machine, $where, $unit);
        }
    }

    /**
     * The made-to-measure block's shape top to bottom, then each axis's four rules,
     * then the price ceiling. Run after every other container field, so an existing error
     * always wins. O(1).
     *
     * @param stdClass|array<array-key,mixed> $container
     * @param mixed $raw
     */
    private static function madeToMeasure($container, $raw, string $where, string $unit): void
    {
        $field = "{$where}/made_to_measure";
        $machine = self::objectAt($raw, $field);
        self::knownFields($machine, $field, self::MADE_TO_MEASURE_FIELDS);
        foreach (['minimum', 'increment'] as $name) {
            self::required($machine, $name, $field);
        }
        foreach (['minimum', 'increment', 'outer_allowance'] as $name) {
            $sides = JsonValue::get($machine, $name);
            if ($sides !== null) {
                self::dimensions($sides, "{$field}/{$name}", $unit);
            }
        }
        self::optionalInteger($machine, 'cost_per_square_metre_minor', $field, 0);
        self::optionalMeasure($machine, 'tare_per_square_metre', $field, Weight::class, self::WEIGHT_UNIT);
        if (JsonValue::get($container, 'outer_dimensions') !== null) {
            throw self::refuse('not_allowed', "{$where}/outer_dimensions", 'cannot be combined with made_to_measure');
        }
        $inner = self::sideTicks(JsonValue::get($container, 'inner_dimensions'), $unit);
        $minimum = self::sideTicks(JsonValue::get($machine, 'minimum'), $unit);
        $increment = self::sideTicks(JsonValue::get($machine, 'increment'), $unit);
        foreach (self::SIDES as $axis => $side) {
            if ($minimum[$axis] === 0) {
                throw self::refuse('invalid_value', "{$field}/minimum/{$side}", 'must be positive');
            }
            if ($increment[$axis] === 0) {
                throw self::refuse('invalid_value', "{$field}/increment/{$side}", 'must be positive');
            }
            if ($minimum[$axis] > $inner[$axis]) {
                throw self::refuse('invalid_value', "{$field}/minimum/{$side}", 'exceeds inner_dimensions');
            }
            if (($inner[$axis] - $minimum[$axis]) % $increment[$axis] !== 0) {
                throw self::refuse('invalid_value', "{$field}/increment/{$side}", 'does not divide inner_dimensions minus minimum');
            }
        }
        $price = JsonValue::integer(JsonValue::get($machine, 'cost_per_square_metre_minor') ?? 0);
        $fee = JsonValue::integer(JsonValue::get($container, 'cost_minor') ?? 0);
        if (BigInt::compare(MadeToMeasure::boxCostFor((int) $price, (int) $fee, $inner), MadeToMeasure::MAX_EXACT_INTEGER) > 0) {
            throw self::refuse('invalid_value', "{$field}/cost_per_square_metre_minor", 'prices the largest box above ' . CanonicalJson::MAX_EXACT_MAGNITUDE);
        }
    }

    /**
     * @param stdClass|array<array-key,mixed> $sides
     * @return array{0:int,1:int,2:int}
     */
    private static function sideTicks($sides, string $unit): array
    {
        $ticks = [];
        foreach (self::SIDES as $side) {
            $ticks[] = MeasureReading::ticks(JsonValue::get($sides, $side), Length::class, $unit);
        }
        return $ticks;
    }

    /**
     * The bag's own shape, then every container field a flat bag refuses, in the
     * order of `BAG_EXCLUSIONS`.
     *
     * @param stdClass|array<array-key,mixed> $container
     * @param mixed $raw
     */
    private static function deformableBoundary($container, $raw, string $where): void
    {
        $field = "{$where}/deformable_boundary";
        $boundary = self::objectAt($raw, $field);
        if (!\in_array(self::required($boundary, 'kind', $field), FlatBag::KINDS, true)) {
            throw self::refuse('not_allowed', "{$field}/kind", 'must be one of ' . CanonicalJson::spelling(FlatBag::KINDS));
        }
        self::knownFields($boundary, $field, ['kind']);
        foreach (self::BAG_EXCLUSIONS as $name) {
            if (self::setBesideABag($name, JsonValue::get($container, $name))) {
                throw self::refuse('not_allowed', "{$where}/{$name}", 'cannot be combined with deformable_boundary');
            }
        }
    }

    /**
     * An empty obstacle list and a zero reserve change nothing, so only they are let through.
     *
     * @param mixed $value
     */
    public static function setBesideABag(string $name, $value): bool
    {
        if ($name === 'obstacles') {
            return $value !== null && $value !== [];
        }
        if ($name === 'void_fill_reserve_ratio') {
            return $value !== null && $value > 0;
        }
        return $value !== null;
    }

    /** @param mixed $raw */
    private static function accessDirections($raw, string $where): void
    {
        self::eachOneOf($raw, $where, self::ACCESS_DIRECTIONS);
    }

    /**
     * @param stdClass|array<array-key,mixed> $object
     * @param list<string> $allowed
     */
    private static function optionalOneOf($object, string $name, string $where, array $allowed): void
    {
        $value = JsonValue::get($object, $name);
        if ($value !== null) {
            self::oneOf($value, "{$where}/{$name}", $allowed);
        }
    }

    /**
     * @param stdClass|array<array-key,mixed> $object
     * @param list<string> $allowed
     */
    private static function optionalEachOneOf($object, string $name, string $where, array $allowed): void
    {
        $value = JsonValue::get($object, $name);
        if ($value !== null) {
            self::eachOneOf($value, "{$where}/{$name}", $allowed);
        }
    }

    /**
     * @param mixed $raw
     * @param list<string> $allowed
     */
    private static function eachOneOf($raw, string $where, array $allowed): void
    {
        foreach (self::listAt($raw, $where) as $index => $value) {
            self::oneOf($value, "{$where}/{$index}", $allowed);
        }
    }

    /**
     * @param mixed $value
     * @param list<string> $allowed
     */
    private static function oneOf($value, string $field, array $allowed): void
    {
        if (!\in_array($value, $allowed, true)) {
            throw self::refuse('not_allowed', $field, 'must be one of ' . CanonicalJson::spelling($allowed));
        }
    }

    /** @param mixed $raw */
    private static function dimensions($raw, string $where, string $unit): void
    {
        $sides = self::objectAt($raw, $where);
        foreach (self::SIDES as $side) {
            self::measure(self::required($sides, $side, $where), "{$where}/{$side}", Length::class, $unit);
        }
    }

    /**
     * Code-point order, as every engine walks them: a JavaScript object puts integer-like keys
     * first, so insertion order would name a different bad tag there. `strcmp` on the names'
     * strings -- PHP made `"10"` the key 10 -- is UTF-8 byte order, which is code-point order.
     * O(t log t) for t tags.
     *
     * @param mixed $raw
     */
    private static function tagLimits($raw, string $where): void
    {
        $members = JsonValue::members(self::objectAt($raw, $where));
        \usort($members, static fn(array $left, array $right): int => \strcmp($left[0], $right[0]));
        foreach ($members as [$tag, $limit]) {
            self::integer($limit, self::pointer($where, $tag), 1);
        }
    }

    /** @param mixed $raw */
    private static function rateTable($raw, string $where): void
    {
        $table = self::objectAt($raw, $where);
        foreach (['weight_brackets_g' => 1, 'prices_minor' => 0] as $name => $minimum) {
            $values = JsonValue::get($table, $name);
            if ($values === null) {
                continue;
            }
            foreach (self::listAt($values, "{$where}/{$name}") as $index => $value) {
                self::integer($value, "{$where}/{$name}/{$index}", $minimum);
            }
        }
        self::optionalInteger($table, 'minimum_charge_minor', $where, 0);
        self::optionalInteger($table, 'fuel_surcharge_permille', $where, 0);
    }

    /** @param mixed $raw */
    private static function obstacles($raw, string $where, string $unit): void
    {
        foreach (self::listAt($raw, $where) as $index => $entry) {
            $at = "{$where}/{$index}";
            $obstacle = self::objectAt($entry, $at);
            $origin = JsonValue::get($obstacle, 'origin');
            if ($origin !== null) {
                $point = self::objectAt($origin, "{$at}/origin");
                foreach (['x', 'y', 'z'] as $axis) {
                    self::optionalMeasure($point, $axis, "{$at}/origin", Length::class, $unit);
                }
            }
            self::dimensions(self::required($obstacle, 'dimensions', $at), "{$at}/dimensions", $unit);
        }
    }

    // ------------------------------------------------------------------------- primitives

    /** @param mixed $value */
    public static function integer($value, string $field, int $minimum): void
    {
        $number = JsonValue::integer($value);
        if ($number === null) {
            self::refuseNonInteger($value, $field, $minimum);
        }
        if ($number < $minimum) {
            throw self::refuse('below_minimum', $field, "must be at least {$minimum}");
        }
    }

    /**
     * A whole number past 2^53 - 1 is out of range, not mistyped: say which way. `json_decode`
     * keeps such a number an integer up to PHP_INT_MAX and makes it a float beyond, and both
     * are the same whole number to the caller.
     *
     * @param mixed $value
     * @return never
     */
    private static function refuseNonInteger($value, string $field, int $minimum): void
    {
        $whole = \is_int($value) || (\is_float($value) && \is_finite($value) && \floor($value) === $value);
        if ($whole && $value < $minimum) {
            throw self::refuse('below_minimum', $field, "must be at least {$minimum}");
        }
        if ($whole) {
            throw self::refuse('above_maximum', $field, 'must be at most ' . CanonicalJson::MAX_EXACT_MAGNITUDE);
        }
        throw self::refuse('wrong_type', $field, 'must be an integer');
    }

    /** @param mixed $value */
    public static function ratio($value, string $field, ?int $maximum): void
    {
        if (!\is_int($value) && !\is_float($value)) {
            throw self::refuse('wrong_type', $field, 'must be a number');
        }
        if ($value < 0) {
            throw self::refuse('below_minimum', $field, 'must be at least 0');
        }
        if ($maximum !== null && $value > $maximum) {
            throw self::refuse('above_maximum', $field, "must be at most {$maximum}");
        }
    }

    /**
     * A measure is an integer, a string or `{value, unit}`, and never negative.
     *
     * @param mixed $value
     * @param class-string<Length>|class-string<Weight> $kind
     */
    private static function measure($value, string $field, string $kind, string $unit): void
    {
        $scalar = \is_int($value) || \is_string($value) || (\is_float($value) && JsonValue::integer($value) !== null);
        if (!$scalar && !self::isMeasureObject($value)) {
            throw self::refuse('wrong_type', $field, 'must be a measure');
        }
        if (!$scalar) {
            if (!JsonValue::has($value, 'value')) {
                throw self::refuse('wrong_type', $field, 'must be a measure');
            }
            $stated = JsonValue::has($value, 'unit') ? JsonValue::get($value, 'unit') : $unit;
            if (!\is_string($stated) || !MeasureReading::knowsUnit($kind, $stated)) {
                throw self::refuse('invalid_unit', $field, 'has an unknown unit ' . CanonicalJson::spelling($stated));
            }
        }
        $verdict = MeasureReading::verdict($value, $kind, $unit);
        if ($verdict === MeasureReading::NEGATIVE) {
            throw self::refuse('negative_measure', $field, 'cannot be negative');
        }
        if ($verdict === MeasureReading::UNREADABLE) {
            throw self::refuse('wrong_type', $field, 'must be a measure');
        }
    }

    /**
     * An object measure; the empty array is `{}` here, which has no `value` and is refused
     * as a measure either way.
     *
     * @param mixed $value
     */
    private static function isMeasureObject($value): bool
    {
        return $value instanceof stdClass || (\is_array($value) && !JsonValue::isList($value));
    }

    /**
     * The schema closes this object: a key it does not name is refused, never ignored. The
     * first unknown key in code-point order is named, the order every engine can share.
     *
     * @param stdClass|array<array-key,mixed> $object
     * @param list<string> $known
     */
    private static function knownFields($object, string $where, array $known): void
    {
        $unknown = JsonValue::namesOutside($object, $known);
        if ($unknown !== []) {
            throw self::refuse('not_allowed', self::pointer($where, $unknown[0]), 'is not a known field');
        }
    }

    /**
     * @param mixed $value
     * @return stdClass|array<array-key,mixed>
     */
    private static function objectAt($value, string $field)
    {
        if ($value instanceof stdClass || $value === [] || (\is_array($value) && !JsonValue::isList($value))) {
            return $value;
        }
        throw self::refuse('wrong_type', $field, 'must be an object');
    }

    /**
     * @param mixed $value
     * @return list<mixed>
     */
    private static function listAt($value, string $field): array
    {
        if (!JsonValue::isList($value)) {
            throw self::refuse('wrong_type', $field, 'must be a list');
        }
        return $value;
    }

    // --------------------------------------------------------------------------- plumbing

    /**
     * An optional member: absent and null are the default; anything else must pass.
     *
     * @param stdClass|array<array-key,mixed> $object
     */
    private static function optionalInteger($object, string $name, string $where, int $minimum): void
    {
        $value = JsonValue::get($object, $name);
        if ($value !== null) {
            self::integer($value, "{$where}/{$name}", $minimum);
        }
    }

    /**
     * Only JSON `true`/`false`: a `(bool)` cast would pack `"no"` as yes. O(1).
     *
     * @param stdClass|array<array-key,mixed> $object
     */
    private static function optionalBoolean($object, string $name, string $where): void
    {
        $value = JsonValue::get($object, $name);
        if ($value !== null && !\is_bool($value)) {
            throw self::refuse('wrong_type', "{$where}/{$name}", 'must be a boolean');
        }
    }

    /** @param stdClass|array<array-key,mixed> $object */
    private static function optionalRatio($object, string $name, string $where, ?int $maximum): void
    {
        $value = JsonValue::get($object, $name);
        if ($value !== null) {
            self::ratio($value, "{$where}/{$name}", $maximum);
        }
    }

    /**
     * @param stdClass|array<array-key,mixed> $object
     * @param class-string<Length>|class-string<Weight> $kind
     */
    private static function optionalMeasure($object, string $name, string $where, string $kind, string $unit): void
    {
        $value = JsonValue::get($object, $name);
        if ($value !== null) {
            self::measure($value, "{$where}/{$name}", $kind, $unit);
        }
    }

    /**
     * @param stdClass|array<array-key,mixed> $object
     * @return mixed
     */
    private static function required($object, string $name, string $where)
    {
        $value = JsonValue::get($object, $name);
        if ($value === null) {
            throw self::refuse('missing_field', "{$where}/{$name}", 'is required');
        }
        return $value;
    }

    /**
     * @param stdClass|array<array-key,mixed> $object
     * @return list<mixed>
     */
    private static function requiredList($object, string $name, string $where): array
    {
        return self::listAt(self::required($object, $name, $where), "{$where}/{$name}");
    }

    /** @param stdClass|array<array-key,mixed> $object */
    private static function requiredString($object, string $name, string $where): void
    {
        if (!\is_string(self::required($object, $name, $where))) {
            throw self::refuse('wrong_type', "{$where}/{$name}", 'must be a string');
        }
    }

    /**
     * Checked once every entry is well formed, so the later of two equal ids is named.
     *
     * @param list<string> $identifiers
     */
    public static function requireUniqueIds(array $identifiers, string $key): void
    {
        $seen = [];
        foreach ($identifiers as $index => $identifier) {
            if (isset($seen[$identifier])) {
                throw self::refuse('duplicate_id', "/{$key}/{$index}/id", 'repeats the id ' . CanonicalJson::spelling($identifier));
            }
            $seen[$identifier] = true;
        }
    }

    /**
     * @param list<mixed> $entries
     * @return list<string>
     */
    private static function identifiers(array $entries): array
    {
        return \array_map(static fn($entry): string => (string) JsonValue::get($entry, 'id'), $entries);
    }

    private static function refuse(string $reason, string $field, string $detail): InvalidRequestException
    {
        return new InvalidRequestException($reason, $field, $detail);
    }
}
