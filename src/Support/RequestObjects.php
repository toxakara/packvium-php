<?php
declare(strict_types=1);
namespace Packvium\Support;

use Packvium\Config\PackingConfig;
use Packvium\Domain\Container;
use Packvium\Domain\FixedPlacement;
use Packvium\Domain\Item;
use Packvium\Domain\MadeToMeasure;
use Packvium\Domain\RateTable;
use Packvium\Serialization\InvalidRequestException;
use Packvium\Validation\FixedPlacementException;

/**
 * The request schema's rules, applied to a request built from objects.
 *
 * Held to `packvium.request_objects.check_objects`. `RequestRules::check` walks a JSON
 * request; `Packer::pack` takes objects, whose own constructors already refuse most of the
 * same values but cannot know where in a request they will sit. This walks the objects in the
 * JSON rules' order -- configuration, items, item ids, containers, container ids, fixed
 * placements -- with the same primitives, so a value the JSON path refuses is refused here with the same reason and
 * the same RFC 6901 field. Only rules `RequestRules::check` also applies are checked, so on
 * the JSON path, which runs that first, this never refuses anything.
 *
 * An id needs no type check here: the models declare it `string`. Unordered sets (accepted
 * stacking groups, tag limits) are walked in code-point order, so insertion order never
 * decides which value an error names. O(size of the request).
 */
final class RequestObjects
{
    /** JSON name => `PackingConfig` property, for each of `RequestRules::CONFIGURATION_INTEGERS`. */
    private const CONFIGURATION_PROPERTIES = [
        'time_limit_ms' => 'timeLimitMs', 'alternatives' => 'topK', 'max_containers' => 'maxContainers',
        'exact_item_limit' => 'exactItemLimit', 'multi_start_orders' => 'multiStartOrders',
        'max_candidates_per_item' => 'maxCandidatesPerItem', 'max_candidate_points' => 'maxCandidatePoints',
        'container_plan_beam_width' => 'containerPlanBeamWidth', 'container_plan_node_limit' => 'containerPlanNodeLimit',
        'dimensional_weight_divisor' => 'dimensionalWeightDivisor',
    ];
    /** JSON name => `EffortBudget` property, for each of `RequestRules::EFFORT_LIMITS`. */
    private const EFFORT_PROPERTIES = [
        'max_candidates_evaluated' => 'maxCandidatesEvaluated', 'max_placement_attempts' => 'maxPlacementAttempts',
        'max_search_nodes' => 'maxSearchNodes', 'max_restarts' => 'maxRestarts',
    ];

    /**
     * Refuse the request with its first violation, or return.
     *
     * @param list<Item> $items
     * @param list<Container> $containers
     * @param list<FixedPlacement> $fixedPlacements
     * @throws InvalidRequestException
     */
    public static function check(array $items, array $containers, PackingConfig $config, array $fixedPlacements = []): void
    {
        self::configuration($config);
        foreach ($items as $index => $item) {
            self::item($item, "/items/{$index}");
        }
        RequestRules::requireUniqueIds(\array_map(static fn(Item $item): string => $item->id, $items), 'items');
        foreach ($containers as $index => $container) {
            self::container($container, "/containers/{$index}");
        }
        RequestRules::requireUniqueIds(\array_map(static fn(Container $container): string => $container->id, $containers), 'containers');
        foreach ($fixedPlacements as $index => $placement) {
            self::fixedPlacement($placement, "fixed_placements[{$index}]", "/fixed_placements/{$index}");
        }
    }

    /**
     * `FixedPlacementShape`'s rules that an object can break, in its order and with its refusal;
     * the types hold the orientation and the position, and `Point` refuses a negative
     * coordinate. Whether the set can hold is `FixedPlacementAdmission`'s to say.
     */
    private static function fixedPlacement(FixedPlacement $placement, string $where, string $field): void
    {
        foreach (['item_type' => $placement->itemId, 'container_type' => $placement->containerId] as $name => $value) {
            if ($value === '') {
                throw new FixedPlacementException("{$where}.{$name} is a non-empty string", 'malformed', "{$field}/{$name}");
            }
        }
        $instance = JsonValue::integer($placement->containerInstance);
        if ($instance === null || $instance < 1) {
            throw new FixedPlacementException("{$where}.container_instance counts from 1", 'malformed', "{$field}/container_instance");
        }
    }

    /**
     * `objective` is not checked here: an unknown one keeps its own, older error on this path,
     * `UnknownObjectiveException`, thrown when the objective is resolved.
     */
    private static function configuration(PackingConfig $config): void
    {
        $where = '/configuration';
        foreach (RequestRules::CONFIGURATION_INTEGERS as $name => $minimum) {
            $property = self::CONFIGURATION_PROPERTIES[$name];
            self::optionalInteger($config->{$property}, "{$where}/{$name}", $minimum);
        }
        RequestRules::ratio($config->minimumSupportRatio, "{$where}/minimum_support_ratio", 1);
        $budget = $config->effortBudget;
        if ($budget === null) {
            return;
        }
        foreach (RequestRules::EFFORT_LIMITS as $name) {
            $property = self::EFFORT_PROPERTIES[$name];
            self::optionalInteger($budget->{$property}, "{$where}/effort_budget/{$name}", 1);
        }
    }

    private static function item(Item $item, string $where): void
    {
        RequestRules::integer($item->quantity, "{$where}/quantity", 1);
        self::optionalInteger($item->maxStackedItems, "{$where}/max_stacked_items", 1);
        self::optionalInteger($item->stopIndex, "{$where}/stop_index", 0);
        self::optionalInteger($item->value, "{$where}/value", 0);
        self::optionalInteger($item->maxCompressionPressureKpa, "{$where}/max_compression_pressure_kpa", 0);
        RequestRules::ratio($item->minimumSupportRatio, "{$where}/minimum_support_ratio", 1);
        if ($item->stackingGroup !== null) {
            RequestRules::stackingGroupName($item->stackingGroup, "{$where}/stacking_group");
        }
        foreach (self::codePointOrder($item->acceptsStackingGroups) as $index => $name) {
            RequestRules::stackingGroupName($name, "{$where}/accepts_stacking_groups/{$index}");
        }
    }

    private static function container(Container $container, string $where): void
    {
        self::optionalInteger($container->quantity, "{$where}/quantity", 1);
        self::optionalInteger($container->maxItems, "{$where}/max_items", 1);
        RequestRules::integer($container->costMinor, "{$where}/cost_minor", 0);
        RequestRules::ratio($container->voidFillReserveRatio, "{$where}/void_fill_reserve_ratio", 1);
        self::tagLimits($container->tagLimits, "{$where}/tag_limits");
        if ($container->rateTable !== null) {
            self::rateTable($container->rateTable, "{$where}/rate_table");
        }
        if ($container->deformableBoundary !== null) {
            self::flatBag($container, $where);
        }
        if ($container->madeToMeasure !== null) {
            self::madeToMeasure($container, $container->madeToMeasure, $where);
        }
    }

    /**
     * `strcmp` on the tags' strings -- PHP made `"10"` the key 10 -- is UTF-8 byte order,
     * which is code-point order. O(t log t) for t tags.
     *
     * @param array<array-key,mixed> $limits
     */
    private static function tagLimits(array $limits, string $where): void
    {
        $tags = \array_map('strval', \array_keys($limits));
        \usort($tags, 'strcmp');
        foreach ($tags as $tag) {
            RequestRules::integer($limits[$tag], RequestRules::pointer($where, $tag), 1);
        }
    }

    private static function rateTable(RateTable $table, string $where): void
    {
        foreach (['weight_brackets_g' => [$table->weightBracketsG, 1], 'prices_minor' => [$table->pricesMinor, 0]] as $name => [$values, $minimum]) {
            foreach ($values as $index => $value) {
                RequestRules::integer($value, "{$where}/{$name}/{$index}", $minimum);
            }
        }
        RequestRules::integer($table->minimumChargeMinor, "{$where}/minimum_charge_minor", 0);
        RequestRules::integer($table->fuelSurchargePermille, "{$where}/fuel_surcharge_permille", 0);
    }

    /**
     * The fields a flat bag refuses, in the JSON rules' order. The container's constructor
     * already refused an unknown kind and every field but `made_to_measure`, with no field
     * named; walking them all keeps the order the JSON path reports in. An empty list is how
     * an object leaves a list field out; JSON leaves it absent.
     */
    private static function flatBag(Container $container, string $where): void
    {
        $fields = [
            'obstacles' => $container->obstacles, 'axles' => $container->axles,
            'access_directions' => $container->accessDirections, 'max_stack_density' => $container->maxStackDensity,
            'outer_dimensions' => $container->outerDimensions, 'void_fill_reserve_ratio' => $container->voidFillReserveRatio,
            'made_to_measure' => $container->madeToMeasure,
        ];
        foreach (RequestRules::BAG_EXCLUSIONS as $name) {
            $value = $fields[$name] === [] ? null : $fields[$name];
            if (RequestRules::setBesideABag($name, $value)) {
                throw new InvalidRequestException('not_allowed', "{$where}/{$name}", 'cannot be combined with deformable_boundary');
            }
        }
    }

    private static function madeToMeasure(Container $container, MadeToMeasure $machine, string $where): void
    {
        $price = "{$where}/made_to_measure/cost_per_square_metre_minor";
        RequestRules::integer($machine->costPerSquareMetreMinor, $price, 0);
        if ($container->outerDimensions !== null) {
            throw new InvalidRequestException('not_allowed', "{$where}/outer_dimensions", 'cannot be combined with made_to_measure');
        }
        $inner = $container->innerDimensions;
        $largest = MadeToMeasure::boxCostFor($machine->costPerSquareMetreMinor, $container->costMinor,
            [$inner->length->ticks, $inner->width->ticks, $inner->height->ticks]);
        if (BigInt::compare($largest, MadeToMeasure::MAX_EXACT_INTEGER) > 0) {
            throw new InvalidRequestException('invalid_value', $price, 'prices the largest box above ' . CanonicalJson::MAX_EXACT_MAGNITUDE);
        }
    }

    private static function optionalInteger(?int $value, string $field, int $minimum): void
    {
        if ($value !== null) {
            RequestRules::integer($value, $field, $minimum);
        }
    }

    /**
     * Strings in code-point order; anything mistyped after them, so it is still checked.
     *
     * @param list<mixed> $values
     * @return list<mixed>
     */
    private static function codePointOrder(array $values): array
    {
        \usort($values, static fn($left, $right): int => (\is_string($right) <=> \is_string($left))
            ?: (\is_string($left) && \is_string($right) ? \strcmp($left, $right) : 0));
        return $values;
    }
}
