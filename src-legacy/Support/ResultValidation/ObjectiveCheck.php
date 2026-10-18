<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

use Packvium\Validation\MalformedResult;
use Packvium\Validation\ResultIssue;

/**
 * The objective vector recomputed from the result alone, and the request-level rules only the
 * whole result can answer: fixed placements, groups and solver selection.
 */
final class ObjectiveCheck
{
    /** @param mixed $request @param mixed $result @param Ordered<ContainerSpec> $fabricated @param list<ResultIssue> $issues */
    public static function check($request, $result, Context $context, Ordered $fabricated, array &$issues): void
    {
        $configuration = Json::get($request, 'configuration', []);
        $selected = Json::get($configuration, 'objective', 'default');
        $reportedName = Json::get($result, 'objective');
        if ((Json::has($configuration, 'objective') || $reportedName !== null) && !Json::equals($reportedName, $selected)) {
            $issues[] = new ResultIssue('objective_mismatch', 'reported ' . PyFormat::repr($reportedName) . ', requested ' . PyFormat::repr($selected));
        }
        $reported = \array_map(static function ($component) { return Json::pyInt($component, 'score'); }, Json::items(Json::at($result, 'score'), 'score'));
        $billing = [
            'divisor' => Json::get($configuration, 'dimensional_weight_divisor'),
            'length_unit' => Json::get($configuration, 'dimensional_weight_length_unit', 'in'),
            'weight_unit' => Json::get($configuration, 'dimensional_weight_weight_unit', 'lb'),
        ];
        $recomputed = self::objective($result, $context, $fabricated, $selected, $billing);
        $same = \count($reported) === \count($recomputed);
        foreach ($recomputed as $index => $value) {
            $same = $same && Exact::compare($reported[$index], $value) === 0;
        }
        if (!$same) {
            $issues[] = new ResultIssue('score_mismatch', 'reported ' . PyFormat::intList($reported) . ', recomputed ' . PyFormat::intList($recomputed));
        }
    }

    /**
     * @param mixed $result @param Ordered<ContainerSpec> $fabricated @param mixed $name
     * @param array{divisor:mixed,length_unit:mixed,weight_unit:mixed} $billing
     * @return list<int|string>
     */
    private static function objective($result, Context $context, Ordered $fabricated, $name, array $billing): array
    {
        $cost = $unused = $height = $billable = $achievedHeight = $landed = 0;
        $billed = Json::isText($name, 'shipping_cost') || Json::isText($name, 'lowest_landed_cost');
        $containers = Json::items(Json::at($result, 'containers'), 'result.containers');
        foreach ($containers as $packed) {
            $spec = $fabricated->get(Json::get($packed, 'id')) ?? $context->containers->get(Json::at($packed, 'container_type'));
            if ($spec === null) {
                continue;
            }
            $cost = Exact::add($cost, $spec->costMinor);
            $volume = $spec->volume();
            if (Exact::sign($volume) === 0) {
                continue;
            }
            $used = Json::pyInt(Json::at($packed, 'used_volume_ticks3'), 'used_volume_ticks3');
            $unused = Exact::add($unused, Exact::floorDiv(Exact::mul(Exact::sub($volume, $used), ContainerCheck::SCORE_SCALE), $volume));
            $top = null;
            foreach (Json::items(Json::at($packed, 'placements'), 'placements') as $placement) {
                $z = Json::int(Json::at(Json::at(Json::at($placement, 'position'), 'z'), 'ticks'), 'placement ticks');
                $extent = Json::int(Json::at(Json::at(Json::at($placement, 'dimensions'), 'height'), 'ticks'), 'placement ticks');
                $reach = Exact::add(Exact::add($z, $extent), $context->clearance);
                $top = $top === null ? $reach : Exact::max($top, $reach);
            }
            $top = $top ?? 0;
            $height = Exact::add($height, Exact::floorDiv(Exact::mul($top, ContainerCheck::SCORE_SCALE), $spec->inner[2]));
            $achievedHeight = Exact::add($achievedHeight, $top);
            if ($billed && $billing['divisor'] !== null) {
                $gross = Json::pyInt(Json::get(Json::get($packed, 'gross_weight', []), 'ticks', 0), 'gross_weight');
                $size = $spec->flatBag ? ContainerCheck::achievedExtent($packed, $context->clearance) : ($spec->outer ?? $spec->inner);
                $dimensional = self::dimensionalWeight(
                    $size,
                    Json::int($billing['divisor'], 'dimensional_weight_divisor'),
                    Json::text($billing['length_unit'], 'dimensional_weight_length_unit'),
                    Json::text($billing['weight_unit'], 'dimensional_weight_weight_unit')
                );
                $charged = Exact::max($gross, $dimensional);
                $billable = Exact::add($billable, $charged);
                if (Json::isText($name, 'lowest_landed_cost')) {
                    $landed = Exact::add($landed, self::landedCharge($spec, $charged));
                }
            }
        }
        $unpackedItems = Json::items(Json::at($result, 'unpacked_items'), 'result.unpacked_items');
        $unpacked = \count($unpackedItems);
        $opened = \count($containers);
        $requiresDivisor = static function (string $objective): MalformedResult {
            return MalformedResult::contract("{$objective} objective requires configuration.dimensional_weight_divisor");
        };
        if (!\is_string($name)) {
            throw MalformedResult::contract('unknown requested objective ' . PyFormat::repr($name));
        }
        switch ($name) {
            case 'default':
                return [$unpacked, $opened, $cost, $unused, $height];
            case 'lowest_cost':
                return [$unpacked, $cost, $opened, $unused, $height];
            case 'shipping_cost':
                if ($billing['divisor'] === null) {
                    throw $requiresDivisor('shipping_cost');
                }
                return [$unpacked, $billable, $opened, $unused, $height];
            case 'lowest_landed_cost':
                if ($billing['divisor'] === null) {
                    throw $requiresDivisor('lowest_landed_cost');
                }
                return [$unpacked, $landed, $opened, $unused, $height];
            case 'open_dimension_height':
                return [$unpacked, $achievedHeight, $opened, $cost, $unused];
            case 'maximum_value':
                $forgone = 0;
                foreach ($unpackedItems as $item) {
                    $spec = $context->items->get(Json::at($item, 'item_type'));
                    if ($spec !== null && Json::truthy($spec->value)) {
                        $forgone = Exact::add($forgone, Json::int($spec->value, 'value'));
                    }
                }
                return [$unpacked, $forgone, $opened, $cost, $unused];
            case 'maximum_volume':
                $nominal = static function (ItemSpec $spec) {
                    return Exact::mul(Exact::mul($spec->dimensions[0], $spec->dimensions[1]), $spec->dimensions[2]);
                };
                $unpackedVolume = 0;
                foreach ($unpackedItems as $item) {
                    $spec = $context->items->get(Json::at($item, 'item_type'));
                    if ($spec !== null) {
                        $unpackedVolume = Exact::add($unpackedVolume, $nominal($spec));
                    }
                }
                $requested = 0;
                foreach ($context->items->values() as $spec) {
                    $requested = Exact::add($requested, Exact::mul($nominal($spec), Json::int($spec->quantity, 'quantity')));
                }
                $unpackedPpm = Exact::sign($requested) === 0 ? 0 : Exact::ceilDiv(Exact::mul($unpackedVolume, ContainerCheck::SCORE_SCALE), $requested);
                return [$unpackedPpm, $unpacked, $opened, $cost, $unused];
        }
        throw MalformedResult::contract('unknown requested objective ' . PyFormat::repr($name));
    }

    /** `floor(volume * weight ticks per unit / (length ticks per unit ^ 3 * divisor))`. @param array<int,int|string> $size @return int|string */
    private static function dimensionalWeight(array $size, int $divisor, string $lengthUnit, string $weightUnit)
    {
        $length = Units::perUnit(Units::LENGTH, $lengthUnit);
        $weight = Units::perUnit(Units::WEIGHT, $weightUnit);
        $volume = Exact::mul(Exact::mul($size[0], $size[1]), $size[2]);
        return Exact::floorDiv(Exact::mul($volume, $weight), Exact::mul(Exact::mul(Exact::mul($length, $length), $length), $divisor));
    }

    /**
     * One container's carrier charge from the request's own rate card: billed weight up to whole
     * grams, the minimum charge, then the fuel surcharge rounded up.
     *
     * @param int|string $billedTicks @return int|string
     */
    private static function landedCharge(ContainerSpec $spec, $billedTicks)
    {
        if ($spec->rateTable === null) {
            throw MalformedResult::contract('container ' . PyFormat::repr($spec->id) . ' has no rate_table, so lowest_landed_cost cannot price it');
        }
        $table = $spec->rateTable;
        $read = static function ($value, string $what) { return Json::pyInt($value, $what); };
        $brackets = \array_map(static function ($bound) use ($read) { return $read($bound, 'bracket'); }, Json::items(Json::at($table, 'weight_brackets_g'), 'weight_brackets_g'));
        $prices = \array_map(static function ($price) use ($read) { return $read($price, 'price'); }, Json::items(Json::at($table, 'prices_minor'), 'prices_minor'));
        $grams = Exact::ceilDiv($billedTicks, Units::TICKS_PER_GRAM);
        foreach ($brackets as $index => $bound) {
            if (!\array_key_exists($index, $prices)) {
                break;
            }
            if (Exact::compare($grams, $bound) <= 0) {
                $base = Exact::max($prices[$index], $read(Json::get($table, 'minimum_charge_minor', 0), 'minimum_charge_minor'));
                $permille = $read(Json::get($table, 'fuel_surcharge_permille', 0), 'fuel_surcharge_permille');
                return Exact::add($base, Exact::ceilDiv(Exact::mul($base, $permille), 1000));
            }
        }
        if ($brackets === []) {
            throw MalformedResult::shape('an empty rate table');
        }
        $last = $brackets[\count($brackets) - 1];
        throw MalformedResult::contract('container ' . PyFormat::repr($spec->id) . " bills at {$grams} g, above its rate table's last bracket ({$last} g); the shipment has no published price");
    }

    /** @param mixed $request @param mixed $result @param list<ResultIssue> $issues */
    public static function checkSolverSelection($request, $result, array &$issues): void
    {
        $selected = Json::get(Json::get($request, 'configuration', []), 'solvers', []);
        if (!Json::truthy($selected)) {
            return;
        }
        $listed = Json::items($selected, 'configuration.solvers');
        $reported = Json::get(Json::at($result, 'algorithm'), 'solver');
        $base = \is_string($reported) ? \explode(':', $reported, 2)[0] : $reported;
        foreach ($listed as $solver) {
            if (Json::equals($solver, $base)) {
                return;
            }
        }
        $issues[] = new ResultIssue('solver_selection_mismatch', 'reported ' . PyFormat::repr($reported) . ', requested one of ' . PyFormat::repr($selected));
    }

    /** A group travels together or not at all: one container, and never half-packed. @param mixed $request @param mixed $result @param list<ResultIssue> $issues */
    public static function checkGroups($request, $result, array &$issues): void
    {
        $rawItems = Json::items(Json::at($request, 'items'), 'request.items');
        $groups = new Ordered();
        foreach ($rawItems as $raw) {
            if (Json::get($raw, 'group') !== null) {
                $groups->set(Json::at($raw, 'id'), Json::at($raw, 'group'));
            }
        }
        if ($groups->count() === 0) {
            return;
        }
        $located = new Ordered();
        $packed = new Ordered();
        foreach (Json::items(Json::at($result, 'containers'), 'result.containers') as $container) {
            foreach (Json::items(Json::at($container, 'placements'), 'placements') as $placement) {
                $group = $groups->get(Json::at($placement, 'item_type'));
                if ($group === null) {
                    continue;
                }
                $places = $located->get($group) ?? new Ordered();
                $places->set(Json::at($container, 'id'), true);
                $located->set($group, $places);
                $packed->set($group, $packed->get($group, 0) + 1);
            }
        }
        $expected = new Ordered();
        foreach ($rawItems as $raw) {
            $group = Json::get($raw, 'group');
            if ($group !== null) {
                $expected->set($group, Exact::add($expected->get($group, 0), Json::int(Json::get($raw, 'quantity', 1), 'quantity')));
            }
        }
        $names = $located->keys();
        Json::sort($names);
        foreach ($names as $group) {
            $places = $located->get($group);
            if ($places->count() > 1) {
                $where = \array_map(static function ($place): string { return Json::text($place, 'a container id'); }, $places->keys());
                \sort($where, SORT_STRING);
                $issues[] = new ResultIssue('group_split', PyFormat::str($group) . ': ' . \implode(', ', $where));
            }
            $placed = $packed->get($group, 0);
            $wanted = $expected->get($group, 0);
            if (Exact::compare($placed, $wanted) !== 0) {
                $issues[] = new ResultIssue('group_partially_packed', PyFormat::str($group) . ": {$placed} of {$wanted} placed");
            }
        }
    }

    /** Every fixed placement is reported where the request put it, and nothing else claims to be. @param mixed $request @param mixed $result @param list<ResultIssue> $issues */
    public static function checkFixedPlacements($request, $result, string $unit, array &$issues): void
    {
        $requested = [];
        foreach (Json::items(Json::get($request, 'fixed_placements', []), 'fixed_placements') as $entry) {
            $containerId = PyFormat::str(Json::at($entry, 'container_type')) . '#' . PyFormat::str(Json::get($entry, 'container_instance', 1));
            $position = Json::get($entry, 'position', []);
            $key = [$containerId, Json::at($entry, 'item_type')];
            foreach (['x', 'y', 'z'] as $axis) {
                $key[] = Units::toTicks(Json::get($position, $axis, 0), $unit, Units::LENGTH);
            }
            $key[] = Json::at($entry, 'orientation');
            $requested[self::keyHash($key)] = $key;
        }
        $reported = [];
        $containerTypes = new Ordered();
        foreach (Json::items(Json::at($result, 'containers'), 'result.containers') as $container) {
            $containerId = Json::get($container, 'id', '');
            $containerTypes->set($containerId, Json::at($container, 'container_type'));
            foreach (Json::items(Json::at($container, 'placements'), 'placements') as $placement) {
                if (Json::get($placement, 'fixed') !== true) {
                    continue;
                }
                $position = Json::at($placement, 'position');
                $key = [$containerId, Json::at($placement, 'item_type')];
                foreach (['x', 'y', 'z'] as $axis) {
                    $key[] = Json::at(Json::at($position, $axis), 'ticks');
                }
                $key[] = Json::at($placement, 'orientation');
                $reported[self::keyHash($key)] = $key;
            }
        }
        foreach (self::sorted(\array_values($requested)) as $key) {
            $containerId = $key[0];
            $hash = \strrpos($containerId, '#');
            $declaredType = $hash === false ? $containerId : (string) \substr($containerId, 0, $hash);
            if (!Json::isText($containerTypes->get($containerId), $declaredType)) {
                $issues[] = new ResultIssue('fixed_container_missing', $containerId);
            } elseif (!\array_key_exists(self::keyHash($key), $reported)) {
                $issues[] = new ResultIssue('fixed_placement_moved', PyFormat::str($key[1]) . " fixed in {$containerId} at " . PyFormat::valueTuple([$key[2], $key[3], $key[4]]) . ' ' . PyFormat::str($key[5]));
            }
        }
        $unexpected = \array_values(\array_diff_key($reported, $requested));
        foreach (self::sorted($unexpected) as $key) {
            $issues[] = new ResultIssue('unexpected_fixed_placement', PyFormat::str($key[1]) . ' in ' . PyFormat::str($key[0]) . ' at ' . PyFormat::valueTuple([$key[2], $key[3], $key[4]]) . ' ' . PyFormat::str($key[5]));
        }
    }

    /** @param list<mixed> $key */
    private static function keyHash(array $key): string
    {
        return \implode("\0", \array_map([Json::class, 'hashKey'], $key));
    }

    /** @param list<list<mixed>> $keys @return list<list<mixed>> */
    private static function sorted(array $keys): array
    {
        Json::sort($keys);
        return $keys;
    }
}
