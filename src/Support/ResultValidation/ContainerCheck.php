<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

use Packvium\Validation\MalformedResult;
use Packvium\Validation\ResultIssue;

/** One packed container: geometry, loads, accounting figures, and a made-to-measure box. */
final class ContainerCheck
{
    /** Ratios in the objective vector and the reserve are carried as parts per million. */
    public const SCORE_SCALE = 1000000;

    private const SIDES = ['length', 'width', 'height'];

    /**
     * The physical box grown by the clearance margin. A placement without integer tick
     * coordinates is the reference's contract refusal, worded as it words it.
     *
     * @param mixed $placement @param int|string $clearance
     */
    public static function envelope($placement, $clearance): Cuboid
    {
        try {
            $position = Json::at($placement, 'position');
            $dimensions = Json::at($placement, 'dimensions');
            $tick = static function ($block, string $key): int {
                return Json::int(Json::at(Json::at($block, $key), 'ticks'), 'placement ticks');
            };
            $margin = Exact::mul(2, $clearance);
            return new Cuboid(
                Exact::sub($tick($position, 'x'), $clearance),
                Exact::sub($tick($position, 'y'), $clearance),
                Exact::sub($tick($position, 'z'), $clearance),
                Exact::add($tick($dimensions, 'length'), $margin),
                Exact::add($tick($dimensions, 'width'), $margin),
                Exact::add($tick($dimensions, 'height'), $margin)
            );
        } catch (MalformedResult $missing) {
            throw MalformedResult::contract('placement is missing tick coordinates: ' . $missing->getMessage());
        }
    }

    /** The largest envelope x2, y2 and z2 over a container's placements. @param mixed $packed @param int|string $clearance @return array{0:int|string,1:int|string,2:int|string} */
    public static function achievedExtent($packed, $clearance): array
    {
        $extent = null;
        foreach (Json::items(Json::at($packed, 'placements'), 'placements') as $placement) {
            $envelope = self::envelope($placement, $clearance);
            $extent = $extent === null
                ? [$envelope->x2, $envelope->y2, $envelope->z2]
                : [Exact::max($extent[0], $envelope->x2), Exact::max($extent[1], $envelope->y2), Exact::max($extent[2], $envelope->z2)];
        }
        return $extent ?? [0, 0, 0];
    }

    /**
     * `achieved_dimensions` is present exactly when the container declares a soft size, and equals
     * the tight extent; a flat bag's extent must pass the girth rule.
     *
     * @param mixed $packed @param int|string $clearance @param list<ResultIssue> $issues
     */
    public static function checkAchievedExtent($packed, ContainerSpec $spec, $clearance, array &$issues): void
    {
        $reported = Json::get($packed, 'achieved_dimensions');
        $packedId = new Label($packed);
        if (!$spec->reportsAchievedExtent) {
            if ($reported !== null) {
                $issues[] = new ResultIssue('achieved_dimensions_mismatch', "{$packedId}: reported for a container of fixed size");
            }
            $packedId->verify();
            return;
        }
        [$a, $b, $c] = self::achievedExtent($packed, $clearance);
        $stated = null;
        if (Json::isDict($reported)) {
            $stated = [];
            foreach (self::SIDES as $side) {
                $stated[] = Json::get(Json::get($reported, $side, []), 'ticks');
            }
        }
        if (!self::matches($stated, [$a, $b, $c])) {
            $issues[] = new ResultIssue('achieved_dimensions_mismatch', "{$packedId}: reported " . self::optionalTuple($stated) . ', computed ' . PyFormat::intTuple([$a, $b, $c]));
        }
        if ($spec->flatBag && (Exact::compare(Exact::add($a, $c), $spec->inner[0]) > 0 || Exact::compare(Exact::add($b, $c), $spec->inner[1]) > 0)) {
            $issues[] = new ResultIssue('bag_girth_exceeded', "{$packedId}: a + c = " . Exact::add($a, $c) . ', b + c = ' . Exact::add($b, $c) . ", flat size {$spec->inner[0]} x {$spec->inner[1]}");
        }
        $packedId->verify();
    }

    /** @param list<mixed>|null $stated @param list<int|string> $expected */
    private static function matches(?array $stated, array $expected): bool
    {
        if ($stated === null) {
            return false;
        }
        foreach ($expected as $index => $value) {
            if (!Json::equalsInt($stated[$index], $value)) {
                return false;
            }
        }
        return true;
    }

    /** @param list<mixed>|null $values */
    private static function optionalTuple(?array $values): string
    {
        return $values === null ? 'None' : PyFormat::valueTuple($values);
    }

    /** `(value.get(key) or {}).get("ticks")` per side, or null when `value` is not an object. @param mixed $value @return list<mixed>|null */
    private static function reportedTicks($value): ?array
    {
        if (!Json::isDict($value)) {
            return null;
        }
        $ticks = [];
        foreach (self::SIDES as $side) {
            $ticks[] = self::nestedTicks($value, $side);
        }
        return $ticks;
    }

    /** `(block.get(key) or {}).get("ticks")`. @param mixed $block @return mixed */
    private static function nestedTicks($block, string $key)
    {
        $value = Json::get($block, $key);
        return Json::truthy($value) ? Json::get($value, 'ticks') : null;
    }

    /**
     * Recompute a made-to-measure container's box from its placements alone and return the spec
     * every later check uses: the recomputed box, never the reported one.
     *
     * @param mixed $packed @param Ordered<ItemSpec> $items @param int|string $clearance @param list<ResultIssue> $issues
     */
    public static function checkMadeToMeasure($packed, bool $compact, ContainerSpec $spec, Ordered $items, $clearance, array &$issues): ContainerSpec
    {
        $containerId = PyFormat::str(Json::get($packed, 'id', '?'));
        $block = Json::get($packed, 'made_to_measure');
        $machine = $spec->madeToMeasure;
        if ($machine === null) {
            if ($block !== null) {
                $issues[] = new ResultIssue('made_to_measure_unexpected', $containerId);
            }
            return $spec;
        }
        if (!Json::isDict($block)) {
            $issues[] = new ResultIssue('made_to_measure_missing', $containerId);
            $block = [];
        }
        if ($compact) {
            $issues[] = new ResultIssue('lattice_summary_on_made_to_measure', $containerId);
        }
        $achieved = self::achievedExtent($packed, $clearance);
        $fabricated = [];
        for ($axis = 0; $axis < 3; ++$axis) {
            $minimum = $machine->minimum[$axis];
            $increment = $machine->increment[$axis];
            $steps = Exact::ceilDiv(Exact::sub(Exact::max($achieved[$axis], $minimum), $minimum), $increment);
            $fabricated[] = Exact::add($minimum, Exact::mul($steps, $increment));
        }
        for ($axis = 0; $axis < 3; ++$axis) {
            if (Exact::compare($fabricated[$axis], $spec->inner[$axis]) > 0) {
                $issues[] = new ResultIssue('fabricated_dimensions_exceed_machine', "{$containerId}: " . PyFormat::intTuple($fabricated) . ' beyond ' . PyFormat::intTuple($spec->inner));
                break;
            }
        }
        $reportedInner = self::reportedTicks(Json::get($packed, 'inner_dimensions'));
        if (!self::matches($reportedInner, $fabricated)) {
            $issues[] = new ResultIssue('fabricated_dimensions_mismatch', "{$containerId}: reported " . self::optionalTuple($reportedInner) . ', computed ' . PyFormat::intTuple($fabricated));
        }
        $outer = [];
        for ($axis = 0; $axis < 3; ++$axis) {
            $outer[] = Exact::add($fabricated[$axis], $machine->allowance[$axis]);
        }
        $reportedOuter = self::reportedTicks(Json::get($packed, 'outer_dimensions'));
        if (!self::matches($reportedOuter, $outer)) {
            $issues[] = new ResultIssue('outer_dimensions_mismatch', "{$containerId}: reported " . self::optionalTuple($reportedOuter) . ', computed ' . PyFormat::intTuple($outer));
        }
        $cost = Exact::add($spec->costMinor, self::perSquareMetreCeiling($machine->pricePerSquareMetreMinor, $fabricated));
        $reportedCost = Json::get($block, 'cost_minor');
        if (!Json::equalsInt($reportedCost, $cost)) {
            $issues[] = new ResultIssue('made_to_measure_cost_mismatch', "{$containerId}: reported " . PyFormat::str($reportedCost) . ", computed {$cost}");
        }
        $tare = Exact::add($spec->tareWeight, self::perSquareMetreCeiling($machine->tarePerSquareMetre, $fabricated));
        $reportedTare = self::nestedTicks($block, 'tare_weight');
        if (!Json::equalsInt($reportedTare, $tare)) {
            $issues[] = new ResultIssue('made_to_measure_tare_mismatch', "{$containerId}: reported " . PyFormat::str($reportedTare) . ", computed {$tare}");
        }
        $gross = Exact::add($tare, self::payloadOf($packed, $items));
        $reportedGross = self::nestedTicks($packed, 'gross_weight');
        if (!Json::equalsInt($reportedGross, $gross)) {
            $issues[] = new ResultIssue('gross_weight_mismatch', "{$containerId}: reported " . PyFormat::str($reportedGross) . ", computed {$gross}");
        }
        $recomputed = clone $spec;
        $recomputed->inner = $fabricated;
        $recomputed->outer = $outer;
        $recomputed->costMinor = $cost;
        $recomputed->tareWeight = $tare;
        return $recomputed;
    }

    /** `ceil(rate * 2(LW + LH + WH) / 1 m^2)`. @param int|string $rate @param array<int,int|string> $sides @return int|string */
    private static function perSquareMetreCeiling($rate, array $sides)
    {
        [$length, $width, $height] = $sides;
        $area = Exact::mul(2, Exact::add(Exact::add(Exact::mul($length, $width), Exact::mul($length, $height)), Exact::mul($width, $height)));
        return Exact::ceilDiv(Exact::mul($rate, $area), Stacking::SQUARE_METRE_TICKS);
    }

    /** The summed weight of every placement whose type the request declares. @param mixed $packed @param Ordered<ItemSpec> $items @return int|string */
    public static function payloadOf($packed, Ordered $items)
    {
        $payload = 0;
        foreach (Json::items(Json::at($packed, 'placements'), 'placements') as $placement) {
            $spec = $items->get(Json::at($placement, 'item_type'));
            if ($spec !== null) {
                $payload = Exact::add($payload, $spec->weight);
            }
        }
        return $payload;
    }

    /** @param mixed $packed @param Ordered<ItemSpec> $items @param int|string $clearance @param list<ResultIssue> $issues */
    public static function check($packed, ContainerSpec $spec, Ordered $items, $clearance, Ratio $globalSupport, array &$issues): void
    {
        $packedId = new Label($packed);
        $placements = Json::items(Json::at($packed, 'placements'), 'placements');
        $boundary = new Cuboid(0, 0, 0, $spec->inner[0], $spec->inner[1], $spec->inner[2]);
        $byId = new Ordered();
        $payload = 0;
        foreach ($placements as $placement) {
            $itemId = Json::at($placement, 'item_id');
            $itemSpec = $items->get(Json::at($placement, 'item_type'));
            $envelope = self::envelope($placement, $clearance);
            $byId->set($itemId, new Entry($itemId, $envelope, $placement, $itemSpec));
            $label = PyFormat::str($itemId);
            if (!$boundary->contains($envelope)) {
                $issues[] = new ResultIssue('outside_container', "{$label} in {$packedId}");
            }
            foreach ($spec->obstacles as $obstacle) {
                if ($envelope->intersects($obstacle) && Geometry::solidsOverlap(Stacking::placedHull($envelope, $placement, $itemSpec), $obstacle->vertices())) {
                    $issues[] = new ResultIssue('obstacle_collision', "{$label} in {$packedId}");
                }
            }
            if ($itemSpec === null) {
                continue;
            }
            $payload = Exact::add($payload, $itemSpec->weight);
            self::checkRotation($placement, $itemSpec, $label, $issues);
            if ($itemSpec->eligibleContainerTags !== [] && \array_intersect_key($itemSpec->eligibleContainerTags, $spec->tags) === []) {
                $tags = \array_map('strval', \array_keys($itemSpec->eligibleContainerTags));
                \sort($tags, SORT_STRING);
                $issues[] = new ResultIssue('container_ineligible', "{$label}: " . PyFormat::str(Json::at($packed, 'container_type')) . ' has none of ' . PyFormat::listOf(\array_map([PyFormat::class, 'stringRepr'], $tags)));
            }
            if ($itemSpec->mustBeOnFloor && Exact::sign($envelope->z) !== 0) {
                $issues[] = new ResultIssue('floor_violation', "{$label} at z={$envelope->z}");
            }
        }
        $entries = $byId->values();
        self::checkPairs($entries, $packedId, $issues);
        [$supporters, $directAbove] = Stacking::supportGraph($entries);
        Stacking::checkSupportAndLoad($entries, $supporters, $directAbove, $globalSupport, $issues);
        $loads = Stacking::propagateLoad($entries, $supporters);
        if ($spec->maxStackDensity !== null) {
            Stacking::checkStackDensity($packedId, $entries, $loads, $spec->maxStackDensity, $issues);
        }
        Stacking::checkCrush($packedId, $entries, $loads, $issues);
        Stacking::checkRouteRemovalOrder($packedId, $entries, $directAbove, $spec->inner, $spec->accessDirections, $issues);
        self::checkLimits($packed, $packedId, $placements, $spec, $items, $payload, $issues);
        self::checkVolumes($packed, $packedId, $placements, $spec, $items, $byId, $loads, $issues);
        $totalWeight = self::payloadOf($packed, $items);
        self::checkCentreOfMass($packed, $packedId, $placements, $spec, $items, $totalWeight, $issues);
        self::checkAxles($packed, $packedId, $placements, $spec, $items, $totalWeight, $issues);
        $packedId->verify();
    }

    /** @param mixed $placement @param list<ResultIssue> $issues */
    private static function checkRotation($placement, ItemSpec $spec, string $itemId, array &$issues): void
    {
        $orientation = Json::at($placement, 'orientation');
        if (!Geometry::isRotation($orientation)) {
            $issues[] = new ResultIssue('unknown_orientation', "{$itemId}: " . PyFormat::str($orientation));
            return;
        }
        if (!$spec->allows($orientation)) {
            $issues[] = new ResultIssue('forbidden_rotation', "{$itemId}: {$orientation}");
        }
        $expected = $spec->rotated($orientation);
        $dimensions = Json::at($placement, 'dimensions');
        $actual = [];
        foreach (self::SIDES as $side) {
            $actual[] = Json::at(Json::at($dimensions, $side), 'ticks');
        }
        if (!self::matches($actual, $expected)) {
            $issues[] = new ResultIssue('dimension_mismatch', "{$itemId}: {$orientation} of " . PyFormat::intTuple($spec->dimensions) . ' is ' . PyFormat::intTuple($expected) . ', got ' . PyFormat::valueTuple($actual));
        }
    }

    /** @param list<Entry> $entries @param list<ResultIssue> $issues */
    private static function checkPairs(array $entries, Label $packedId, array &$issues): void
    {
        $count = \count($entries);
        for ($first = 0; $first < $count; ++$first) {
            $left = $entries[$first];
            for ($second = $first + 1; $second < $count; ++$second) {
                $right = $entries[$second];
                if ($left->envelope->intersects($right->envelope)
                    && Geometry::solidsOverlap(Stacking::placedHull($left->envelope, $left->placement, $left->spec), Stacking::placedHull($right->envelope, $right->placement, $right->spec))
                    && !Stacking::validNesting($left, $right)) {
                    $issues[] = new ResultIssue('collision', PyFormat::str($left->id) . ' with ' . PyFormat::str($right->id) . " in {$packedId}");
                }
                if ($left->spec !== null && $right->spec !== null
                    && (\array_intersect_key($left->spec->incompatibleTags, $right->spec->tags) !== []
                        || \array_intersect_key($right->spec->incompatibleTags, $left->spec->tags) !== [])) {
                    $issues[] = new ResultIssue('incompatible_items', PyFormat::str($left->id) . ' is incompatible with ' . PyFormat::str($right->id) . " in {$packedId}");
                }
            }
        }
    }

    /** @param mixed $packed @param list<mixed> $placements @param Ordered<ItemSpec> $items @param int|string $payload @param list<ResultIssue> $issues */
    private static function checkLimits($packed, Label $packedId, array $placements, ContainerSpec $spec, Ordered $items, $payload, array &$issues): void
    {
        if ($spec->maxPayload !== null && Exact::compare($payload, $spec->maxPayload) > 0) {
            $issues[] = new ResultIssue('payload_exceeded', "{$packedId}: {$payload} > {$spec->maxPayload} ticks");
        }
        $reported = Json::get(Json::get($packed, 'payload_weight', []), 'ticks');
        if ($reported !== null && !Json::equalsInt($reported, $payload)) {
            $issues[] = new ResultIssue('payload_weight_mismatch', "{$packedId}: reported " . PyFormat::str($reported) . ", computed {$payload}");
        }
        if ($spec->maxItems !== null && \count($placements) > $spec->maxItems) {
            $issues[] = new ResultIssue('max_items_exceeded', "{$packedId}: " . \count($placements) . " > {$spec->maxItems}");
        }
        foreach ($spec->tagLimits as [$tag, $limit]) {
            $count = 0;
            foreach ($placements as $placement) {
                $item = $items->get(Json::at($placement, 'item_type'));
                if ($item !== null && isset($item->tags[$tag])) {
                    ++$count;
                }
            }
            if (Exact::compare($count, $limit) > 0) {
                $issues[] = new ResultIssue('tag_count_exceeded', "{$packedId}: {$tag} count {$count} > limit {$limit}");
            }
        }
    }

    /**
     * @param mixed $packed @param list<mixed> $placements @param Ordered<ItemSpec> $items
     * @param Ordered<Entry> $byId @param list<int|string> $loads @param list<ResultIssue> $issues
     */
    private static function checkVolumes($packed, Label $packedId, array $placements, ContainerSpec $spec, Ordered $items, Ordered $byId, array $loads, array &$issues): void
    {
        // Every placement counts, through the envelope its item id resolves to -- a repeated id
        // resolves to its last placement, as the reference's dict does.
        $used = 0;
        foreach ($placements as $placement) {
            $index = $byId->position(Json::at($placement, 'item_id'));
            $entry = $byId->get(Json::at($placement, 'item_id'));
            $used = Exact::add($used, Stacking::occupiedVolume($entry->envelope, $placement, $items->get(Json::at($placement, 'item_type')), $loads[$index]));
        }
        $entries = $byId->values();
        for ($first = 0, $count = \count($entries); $first < $count; ++$first) {
            for ($second = $first + 1; $second < $count; ++$second) {
                if (Stacking::validNesting($entries[$first], $entries[$second])) {
                    $left = $entries[$first];
                    $used = Exact::sub($used, Exact::mul(Exact::mul($left->spec->nestingHeight, $left->envelope->length), $left->envelope->width));
                }
            }
        }
        $reportedVolume = Json::get($packed, 'used_volume_ticks3');
        if ($reportedVolume !== null && Exact::compare(Json::pyInt($reportedVolume, 'used_volume_ticks3'), $used) !== 0) {
            $issues[] = new ResultIssue('used_volume_mismatch', "{$packedId}: reported " . PyFormat::str($reportedVolume) . ", computed {$used}");
        }
        $volume = $spec->volume();
        $scaledReserve = self::ratioPpm($spec->voidFillReserveRatio);
        $reserved = Exact::add(
            Exact::mul(Exact::floorDiv($volume, self::SCORE_SCALE), $scaledReserve),
            Exact::floorDiv(Exact::mul(Exact::mod($volume, self::SCORE_SCALE), $scaledReserve), self::SCORE_SCALE)
        );
        $reportedReserve = Json::get($packed, 'void_fill_reserve_ticks3');
        if ($reportedReserve !== null && Exact::compare(Json::pyInt($reportedReserve, 'void_fill_reserve_ticks3'), $reserved) !== 0) {
            $issues[] = new ResultIssue('void_fill_reserve_mismatch', "{$packedId}: reported " . PyFormat::str($reportedReserve) . ", computed {$reserved}");
        } elseif (Exact::sign($reserved) !== 0 && $reportedReserve === null) {
            $issues[] = new ResultIssue('void_fill_reserve_mismatch', "{$packedId}: non-zero reserve {$reserved} is not reported");
        }
        $usable = Exact::sub($volume, $reserved);
        if (Exact::compare($used, $usable) > 0) {
            $issues[] = new ResultIssue('void_fill_reserve_exceeded', "{$packedId}: used {$used} > usable {$usable}");
        }
    }

    /**
     * A support, void-fill reserve or compression ratio in parts per million exactly as every solver
     * converts it: the IEEE double `floor(ratio * 10^6 + 0.5)` of docs/UNITS-AND-NUMERICS.md.
     * Rounding the exact rational instead disagrees on about one in a hundred seven-decimal
     * halves and would refuse placements, and reported reserves, every solver produces.
     */
    public static function ratioPpm(Ratio $ratio): int
    {
        return (int)($ratio->toFloat() * self::SCORE_SCALE + 0.5);
    }

    /** `sum(weight * (2 * position + extent))` along one axis, over declared item types. @param list<mixed> $placements @param Ordered<ItemSpec> $items @return int|string */
    private static function doubledMoment(array $placements, Ordered $items, string $axis, string $side)
    {
        $moment = 0;
        foreach ($placements as $placement) {
            $spec = $items->get(Json::at($placement, 'item_type'));
            if ($spec === null) {
                continue;
            }
            $position = Json::int(Json::at(Json::at(Json::at($placement, 'position'), $axis), 'ticks'), 'placement ticks');
            $extent = Json::int(Json::at(Json::at(Json::at($placement, 'dimensions'), $side), 'ticks'), 'placement ticks');
            $moment = Exact::add($moment, Exact::mul($spec->weight, Exact::add(Exact::mul(2, $position), $extent)));
        }
        return $moment;
    }

    /** @param mixed $packed @param list<mixed> $placements @param Ordered<ItemSpec> $items @param int|string $totalWeight @param list<ResultIssue> $issues */
    private static function checkCentreOfMass($packed, Label $packedId, array $placements, ContainerSpec $spec, Ordered $items, $totalWeight, array &$issues): void
    {
        $expected = 0;
        if (Exact::sign($totalWeight) !== 0) {
            $offset = static function ($doubled, $side) use ($totalWeight) {
                $centred = Exact::mul($totalWeight, $side);
                return Exact::floorDiv(Exact::mul(Exact::abs(Exact::sub($doubled, $centred)), self::SCORE_SCALE), $centred);
            };
            $expected = Exact::max(
                $offset(self::doubledMoment($placements, $items, 'x', 'length'), $spec->inner[0]),
                $offset(self::doubledMoment($placements, $items, 'y', 'width'), $spec->inner[1])
            );
        }
        $reported = Json::get($packed, 'centre_of_mass_offset_ppm');
        if (!Json::equalsInt($reported, $expected)) {
            $issues[] = new ResultIssue('centre_of_mass_mismatch', "{$packedId}: reported " . PyFormat::str($reported) . ", computed {$expected}");
        }
    }

    /** @param mixed $packed @param list<mixed> $placements @param Ordered<ItemSpec> $items @param int|string $totalWeight @param list<ResultIssue> $issues */
    private static function checkAxles($packed, Label $packedId, array $placements, ContainerSpec $spec, Ordered $items, $totalWeight, array &$issues): void
    {
        if ($spec->axles === null) {
            if (Json::has($packed, 'axle_reactions')) {
                $issues[] = new ResultIssue('axle_reaction_mismatch', "{$packedId}: reactions reported without configured axles");
            }
            return;
        }
        if (\count($spec->axles) !== 2) {
            throw MalformedResult::shape("{$packedId}: axles must name exactly a front and a rear axle");
        }
        [[$frontX, $frontLimit], [$rearX, $rearLimit]] = $spec->axles;
        $denominator = Exact::mul(2, Exact::sub($rearX, $frontX));
        $gross = Exact::add($spec->tareWeight, $totalWeight);
        $doubledWeightedX = Exact::add(Exact::mul($spec->tareWeight, $spec->inner[0]), self::doubledMoment($placements, $items, 'x', 'length'));
        $frontNumerator = Exact::sub(Exact::mul(Exact::mul(2, $gross), $rearX), $doubledWeightedX);
        $rearNumerator = Exact::sub($doubledWeightedX, Exact::mul(Exact::mul(2, $gross), $frontX));
        $expected = [
            'basis' => 'gross',
            'denominator' => (string)$denominator,
            'front_numerator' => (string)$frontNumerator,
            'rear_numerator' => (string)$rearNumerator,
        ];
        $reported = Json::get($packed, 'axle_reactions');
        if (!Json::equals($reported, $expected)) {
            $issues[] = new ResultIssue('axle_reaction_mismatch', "{$packedId}: reported " . PyFormat::str($reported) . ', computed ' . PyFormat::repr($expected));
        }
        $over = static function ($limit, $numerator) use ($denominator): bool {
            return $limit !== null && Exact::compare($numerator, Exact::mul($limit, $denominator)) > 0;
        };
        if ($over($frontLimit, $frontNumerator) || $over($rearLimit, $rearNumerator)) {
            $issues[] = new ResultIssue('axle_overloaded', (string)$packedId);
        }
    }
}
