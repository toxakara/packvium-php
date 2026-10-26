<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

use Packvium\Validation\MalformedResult;

/** The request, read independently of the engine's own model. */
final class Spec
{
    /** The six axis-aligned exit directions a placement could be slid out through. */
    public const EXIT_DIRECTIONS = ['+x', '-x', '+y', '-y', '+z', '-z'];

    private const UPRIGHT_ROTATIONS = ['LWH', 'WLH'];

    /** @param mixed $request @return Ordered<ItemSpec> */
    public static function items($request, string $unit): Ordered
    {
        $items = new Ordered();
        foreach (Json::items(Json::at($request, 'items'), 'request.items') as $index => $raw) {
            $where = "items[{$index}]";
            $keepUpright = Json::flag($raw, 'keep_upright', false, $where);
            $stackable = Json::flag($raw, 'stackable', true, $where);
            $mustBeOnFloor = Json::flag($raw, 'must_be_on_floor', false, $where);
            $rotations = Json::get($raw, 'allowed_rotations');
            if ($rotations === null) {
                $rotations = $keepUpright ? self::UPRIGHT_ROTATIONS : \array_keys(Geometry::ROTATIONS);
            } else {
                $rotations = Json::items($rotations, 'allowed_rotations');
            }
            $id = Json::at($raw, 'id');
            $spec = new ItemSpec();
            $spec->id = Json::text($id, 'an item id');
            $spec->dimensions = Units::dimensions(Json::at($raw, 'dimensions'), $unit);
            $spec->weight = Units::toTicks(Json::get($raw, 'weight', 0), 'g', Units::WEIGHT);
            $spec->quantity = Json::get($raw, 'quantity', 1);
            $spec->allowedRotations = \array_values($rotations);
            $spec->stackable = $stackable;
            $spec->mustBeOnFloor = $mustBeOnFloor;
            $spec->maxTopLoad = self::measured($raw, 'max_top_load', 'g', Units::WEIGHT);
            $spec->minimumSupportRatio = Units::ratioOf(Json::get($raw, 'minimum_support_ratio', 0));
            $spec->tags = self::tagSet(Json::get($raw, 'tags', []), 'tags');
            $spec->eligibleContainerTags = self::tagSet(Json::get($raw, 'eligible_container_tags', []), 'eligible_container_tags');
            $spec->maxStackedItems = Json::optionalInt(Json::get($raw, 'max_stacked_items'), 'max_stacked_items');
            $spec->groundContactRule = Json::get($raw, 'ground_contact_rule');
            $spec->nestingHeight = self::measured($raw, 'nesting_height', $unit, Units::LENGTH);
            $spec->incompatibleTags = self::tagSet(Json::get($raw, 'incompatible_tags', []), 'incompatible_tags');
            $spec->stopIndex = Json::optionalInt(Json::get($raw, 'stop_index'), 'stop_index');
            $spec->value = Json::get($raw, 'value');
            $spec->shapeType = Json::get($raw, 'shape_type', 'rigid_cuboid');
            $spec->hullVertices = self::hull($raw, $unit);
            $ratio = Json::get($raw, 'compression_ratio');
            // The solvers' ratio rule, applied once: floor(ratio * 1000000 + 0.5) on the double.
            $spec->compressionRatioPpm = $ratio === null ? null
                : ContainerCheck::ratioPpm(Units::ratioOf($ratio));
            $spec->maxCompressionPressureKpa = Json::optionalInt(Json::get($raw, 'max_compression_pressure_kpa'), 'max_compression_pressure_kpa');
            $spec->stackingGroup = Json::get($raw, 'stacking_group');
            $accepted = Json::get($raw, 'accepts_stacking_groups');
            $spec->acceptsStackingGroups = Json::truthy($accepted) ? self::tagSet($accepted, 'accepts_stacking_groups') : [];
            $items->set($id, $spec);
        }
        return $items;
    }

    /** @param mixed $request @return Ordered<ContainerSpec> */
    public static function containers($request, string $unit): Ordered
    {
        $containers = new Ordered();
        foreach (Json::items(Json::at($request, 'containers'), 'request.containers') as $raw) {
            $spec = new ContainerSpec();
            $spec->id = Json::at($raw, 'id');
            $spec->inner = Units::dimensions(Json::at($raw, 'inner_dimensions'), $unit);
            $spec->accessDirections = self::accessDirections($raw);
            $outer = Json::get($raw, 'outer_dimensions');
            $spec->outer = $outer === null ? null : Units::dimensions($outer, $unit);
            $spec->costMinor = Json::int(Json::get($raw, 'cost_minor', 0), 'cost_minor');
            $spec->maxPayload = self::measured($raw, 'max_payload', 'g', Units::WEIGHT);
            $spec->maxItems = Json::optionalInt(Json::get($raw, 'max_items'), 'max_items');
            $spec->quantity = Json::optionalInt(Json::get($raw, 'quantity'), 'quantity');
            $spec->obstacles = self::obstacles($raw, $unit);
            $spec->tags = self::tagSet(Json::get($raw, 'tags', []), 'tags');
            $spec->tagLimits = self::tagLimits($raw);
            $spec->voidFillReserveRatio = Units::ratioOf(Json::get($raw, 'void_fill_reserve_ratio', 0));
            $spec->tareWeight = Units::toTicks(Json::get($raw, 'tare_weight', 0), 'g', Units::WEIGHT);
            $spec->axles = self::axles($raw, $unit);
            $spec->maxStackDensity = self::measured($raw, 'max_stack_density', 'g', Units::WEIGHT);
            $spec->rateTable = Json::get($raw, 'rate_table');
            $spec->flatBag = Json::get($raw, 'deformable_boundary') !== null;
            $spec->reportsAchievedExtent = $spec->flatBag || Json::get($raw, 'made_to_measure') !== null;
            $machine = Json::get($raw, 'made_to_measure');
            $spec->madeToMeasure = $machine === null ? null : self::machine($machine, $unit);
            $containers->set($spec->id, $spec);
        }
        return $containers;
    }

    /** `None if raw.get(key) is None else to_ticks(...)`. @param mixed $raw @param array<string,int> $table @return int|string|null */
    private static function measured($raw, string $key, string $unit, array $table)
    {
        $value = Json::get($raw, $key);
        return $value === null ? null : Units::toTicks($value, $unit, $table);
    }

    /** `frozenset(value)` of a request's tag list; the contract's tags are strings. @param mixed $value @return array<string,true> */
    private static function tagSet($value, string $what): array
    {
        $tags = [];
        foreach (Json::items($value, $what) as $tag) {
            $tags[Json::text($tag, $what)] = true;
        }
        return $tags;
    }

    /** @param mixed $raw @return list<array{0:int|string,1:int|string,2:int|string}>|null */
    private static function hull($raw, string $unit): ?array
    {
        $declared = Json::get($raw, 'hull_vertices');
        if ($declared === null) {
            return null;
        }
        $vertices = [];
        foreach (Json::items($declared, 'hull_vertices') as $vertex) {
            $vertices[] = [
                Units::toTicks(Json::at($vertex, 'x'), $unit, Units::LENGTH),
                Units::toTicks(Json::at($vertex, 'y'), $unit, Units::LENGTH),
                Units::toTicks(Json::at($vertex, 'z'), $unit, Units::LENGTH),
            ];
        }
        return $vertices;
    }

    /** @param mixed $raw @return list<string> */
    private static function accessDirections($raw): array
    {
        $declared = Json::items(Json::get($raw, 'access_directions', []), 'access_directions');
        foreach ($declared as $direction) {
            Json::hashKey($direction);
        }
        return \array_values(\array_filter(self::EXIT_DIRECTIONS, static function (string $direction) use ($declared): bool {
            foreach ($declared as $named) {
                if (Json::isText($named, $direction)) {
                    return true;
                }
            }
            return false;
        }));
    }

    /** @param mixed $raw @return list<Cuboid> */
    private static function obstacles($raw, string $unit): array
    {
        $boxes = [];
        foreach (Json::items(Json::get($raw, 'obstacles', []), 'obstacles') as $obstacle) {
            $additional = Json::items(Json::get($obstacle, 'additional_boxes', []), 'additional_boxes');
            foreach (\array_merge([$obstacle], $additional) as $component) {
                $origin = Json::get($component, 'origin', []);
                [$length, $width, $height] = Units::dimensions(Json::at($component, 'dimensions'), $unit);
                $boxes[] = new Cuboid(
                    Units::toTicks(Json::get($origin, 'x', 0), $unit, Units::LENGTH),
                    Units::toTicks(Json::get($origin, 'y', 0), $unit, Units::LENGTH),
                    Units::toTicks(Json::get($origin, 'z', 0), $unit, Units::LENGTH),
                    $length,
                    $width,
                    $height
                );
            }
        }
        return $boxes;
    }

    /** `{str(tag): int(limit)}`, sorted by tag as the reference iterates it. @param mixed $raw @return list<array{0:string,1:int|string}> */
    private static function tagLimits($raw): array
    {
        $declared = Json::get($raw, 'tag_limits', []);
        if (!Json::isDict($declared)) {
            throw MalformedResult::shape('tag_limits must be an object');
        }
        $limits = [];
        foreach ($declared as $tag => $limit) {
            $limits[] = [(string)$tag, Json::pyInt($limit, 'tag_limits')];
        }
        \usort($limits, static function (array $a, array $b): int { return \strcmp($a[0], $b[0]); });
        return $limits;
    }

    /** @param mixed $raw @return list<array{0:int|string,1:int|string|null}>|null */
    private static function axles($raw, string $unit): ?array
    {
        $declared = Json::get($raw, 'axles');
        if ($declared === null) {
            return null;
        }
        $axles = [];
        foreach (Json::items($declared, 'axles') as $axle) {
            $axles[] = [
                Units::toTicks(Json::at($axle, 'position'), $unit, Units::LENGTH),
                self::measured($axle, 'max_load', 'g', Units::WEIGHT),
            ];
        }
        return $axles;
    }

    /** @param mixed $raw */
    private static function machine($raw, string $unit): MachineSpec
    {
        $sides = static function (string $name) use ($raw, $unit): array {
            $value = Json::get($raw, $name);
            return $value === null ? [0, 0, 0] : Units::dimensions($value, $unit);
        };
        $machine = new MachineSpec();
        $machine->minimum = $sides('minimum');
        $machine->increment = $sides('increment');
        $price = Json::get($raw, 'cost_per_square_metre_minor');
        $machine->pricePerSquareMetreMinor = Json::truthy($price) ? Json::int($price, 'cost_per_square_metre_minor') : 0;
        $tare = Json::get($raw, 'tare_per_square_metre');
        $machine->tarePerSquareMetre = $tare === null ? 0 : Units::toTicks($tare, 'g', Units::WEIGHT);
        $machine->allowance = $sides('outer_allowance');
        return $machine;
    }
}
