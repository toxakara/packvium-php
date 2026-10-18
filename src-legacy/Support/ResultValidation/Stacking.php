<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

use Packvium\Validation\MalformedResult;
use Packvium\Validation\ResultIssue;

/**
 * What rests on what, and everything that follows from it: support, top load, stacking limits,
 * load propagation, crush, stack density and route unloading order. Entries are the container's
 * placements by item id, in the reference's dict order; edges refer to them by position.
 */
final class Stacking
{
    /** One square metre in length ticks squared. */
    public const SQUARE_METRE_TICKS = 256000000000000;

    /**
     * Exact direct-support edges. Ordinary face contacts keep placement order; nested instances of
     * one column are linked only to their adjacent predecessor, which replaces any shadowed face
     * contact from the same column.
     *
     * @param list<Entry> $entries
     * @return array{0:list<list<array{0:int,1:int|string}>>,1:list<array<int,true>>} supporters and the items directly above each
     */
    public static function supportGraph(array $entries): array
    {
        $byTop = [];
        foreach ($entries as $index => $entry) {
            $byTop[(string)$entry->envelope->z2][] = $index;
        }
        $supporters = \array_fill(0, \count($entries), []);
        foreach ($entries as $upper => $entry) {
            foreach ($byTop[(string)$entry->envelope->z] ?? [] as $lower) {
                if ($lower === $upper) {
                    continue;
                }
                $area = $entries[$lower]->envelope->overlapAreaXy($entry->envelope);
                if (Exact::sign($area) > 0) {
                    $supporters[$upper][] = [$lower, $area];
                }
            }
        }

        $groupKeys = [];
        $groups = [];
        foreach ($entries as $index => $entry) {
            if ($entry->spec === null || $entry->spec->nestingHeight === null) {
                continue;
            }
            $envelope = $entry->envelope;
            $key = Json::hashKey(Json::at($entry->placement, 'item_type')) . "\0" . \implode(',', [
                $envelope->x, $envelope->y, $envelope->x2, $envelope->y2, $entry->spec->nestingHeight,
            ]);
            $groupKeys[$index] = $key;
            $groups[$key][] = $index;
        }
        $predecessors = [];
        foreach ($groups as $group) {
            \usort($group, static function (int $a, int $b) use ($entries): int {
                return Exact::compare($entries[$a]->envelope->z, $entries[$b]->envelope->z) ?: $a <=> $b;
            });
            for ($position = 0; $position + 1 < \count($group); ++$position) {
                [$lower, $upper] = [$group[$position], $group[$position + 1]];
                $nesting = $entries[$lower]->spec->nestingHeight;
                if (Exact::compare(Exact::sub($entries[$lower]->envelope->z2, $entries[$upper]->envelope->z), $nesting) === 0) {
                    $predecessors[$upper] = $lower;
                }
            }
        }
        foreach ($predecessors as $upper => $lower) {
            $key = $groupKeys[$upper];
            $edges = \array_values(\array_filter($supporters[$upper], static function (array $edge) use ($groupKeys, $key): bool {
                return ($groupKeys[$edge[0]] ?? null) !== $key;
            }));
            $position = \count($edges);
            foreach ($edges as $at => $edge) {
                if ($edge[0] > $lower) {
                    $position = $at;
                    break;
                }
            }
            \array_splice($edges, $position, 0, [[$lower, $entries[$upper]->envelope->baseArea]]);
            $supporters[$upper] = $edges;
        }

        $directAbove = \array_fill(0, \count($entries), []);
        foreach ($supporters as $upper => $edges) {
            foreach ($edges as [$lower]) {
                $directAbove[$lower][$upper] = true;
            }
        }
        return [$supporters, $directAbove];
    }

    /**
     * @param list<Entry> $entries @param list<list<array{0:int,1:int|string}>> $supporters
     * @param list<array<int,true>> $directAbove @param list<ResultIssue> $issues
     */
    public static function checkSupportAndLoad(array $entries, array $supporters, array $directAbove, Ratio $globalSupport, array &$issues): void
    {
        foreach ($entries as $index => $entry) {
            $spec = $entry->spec;
            if ($spec === null || Exact::sign($entry->envelope->z) === 0) {
                continue;
            }
            $itemId = PyFormat::str($entry->id);
            $supporting = $supporters[$index];
            $required = $globalSupport->compare($spec->minimumSupportRatio) < 0 ? $spec->minimumSupportRatio : $globalSupport;
            self::checkGroundContact($itemId, $entry->envelope, $supporting, $entries, $spec->groundContactRule, $issues);
            if ($required->compareInt(0) > 0) {
                $area = Exact::sum(\array_map(static function (array $edge) { return $edge[1]; }, $supporting));
                if (Exact::compare($area, self::requiredSupportArea($entry->envelope->baseArea, ContainerCheck::ratioPpm($required))) < 0) {
                    $ratio = Ratio::of($area, $entry->envelope->baseArea);
                    $issues[] = new ResultIssue('insufficient_support', "{$itemId}: " . PyFormat::fixed6($ratio->toFloat()) . ' < ' . PyFormat::fixed6($required->toFloat()));
                }
            }
            foreach ($supporting as [$lower]) {
                $lowerSpec = $entries[$lower]->spec;
                if ($lowerSpec === null) {
                    continue;
                }
                $lowerId = PyFormat::str($entries[$lower]->id);
                if (!$lowerSpec->stackable) {
                    $issues[] = new ResultIssue('non_stackable', "{$itemId} rests on {$lowerId}");
                } elseif ($lowerSpec->acceptsStackingGroups !== [] && !Accounting::holds($lowerSpec->acceptsStackingGroups, $spec->stackingGroup)) {
                    $issues[] = new ResultIssue('stacking_group_incompatible', "{$itemId} rests on {$lowerId}");
                }
                if ($lowerSpec->maxTopLoad !== null && Exact::compare($spec->weight, $lowerSpec->maxTopLoad) > 0) {
                    $issues[] = new ResultIssue('top_load_exceeded', "{$itemId} ({$spec->weight} ticks) on {$lowerId} (limit {$lowerSpec->maxTopLoad})");
                }
            }
        }
        foreach ($entries as $index => $entry) {
            if ($entry->spec === null || $entry->spec->maxStackedItems === null) {
                continue;
            }
            $descendants = [];
            $pending = \array_keys($directAbove[$index]);
            while ($pending !== []) {
                $above = \array_pop($pending);
                if (isset($descendants[$above])) {
                    continue;
                }
                $descendants[$above] = true;
                foreach (\array_keys($directAbove[$above]) as $next) {
                    $pending[] = $next;
                }
            }
            if (\count($descendants) > $entry->spec->maxStackedItems) {
                $issues[] = new ResultIssue('max_stacked_items_exceeded', PyFormat::str($entry->id) . ': ' . \count($descendants) . " above > limit {$entry->spec->maxStackedItems}");
            }
        }
    }

    /** Exact `floor(baseArea * ppm / 10^6)`, the least supported area the ratio allows. @param int|string $baseArea @return int|string */
    private static function requiredSupportArea($baseArea, int $ppm)
    {
        return Exact::floorDiv(Exact::mul($baseArea, $ppm), ContainerCheck::SCORE_SCALE);
    }

    /** @param list<array{0:int,1:int|string}> $supporting @param list<Entry> $entries @param mixed $rule @param list<ResultIssue> $issues */
    private static function checkGroundContact(string $itemId, Cuboid $envelope, array $supporting, array $entries, $rule, array &$issues): void
    {
        if ($rule === null || Json::isText($rule, 'free')) {
            return;
        }
        if (Json::isText($rule, 'covered')) {
            $corners = [[$envelope->x, $envelope->y], [$envelope->x2, $envelope->y], [$envelope->x, $envelope->y2], [$envelope->x2, $envelope->y2]];
            foreach ($corners as [$x, $y]) {
                $covered = false;
                foreach ($supporting as [$lower]) {
                    $surface = $entries[$lower]->envelope;
                    if (Exact::compare($surface->x, $x) <= 0 && Exact::compare($x, $surface->x2) <= 0
                        && Exact::compare($surface->y, $y) <= 0 && Exact::compare($y, $surface->y2) <= 0) {
                        $covered = true;
                        break;
                    }
                }
                if (!$covered) {
                    $issues[] = new ResultIssue('ground_contact_violation', "{$itemId}: covered requires all four base corners");
                    return;
                }
            }
        } elseif (Json::isText($rule, 'single') && \count($supporting) !== 1) {
            $issues[] = new ResultIssue('ground_contact_violation', "{$itemId}: single has " . \count($supporting) . ' supporters');
        } elseif (Json::isText($rule, 'multiple') && \count($supporting) < 2) {
            $issues[] = new ResultIssue('ground_contact_violation', "{$itemId}: multiple has " . \count($supporting) . ' supporters');
        }
    }

    /**
     * Cumulative weight each item bears from everything resting on it, split across supporters by
     * contact area and conserved exactly (the remainder goes to the last supporter), top-down.
     *
     * @param list<Entry> $entries @param list<list<array{0:int,1:int|string}>> $supporters
     * @return list<int|string>
     */
    public static function propagateLoad(array $entries, array $supporters): array
    {
        $order = \array_keys($entries);
        \usort($order, static function (int $a, int $b) use ($entries): int {
            $left = $entries[$a]->envelope;
            $right = $entries[$b]->envelope;
            return Exact::compare($right->z2, $left->z2) ?: (Exact::compare($right->z, $left->z) ?: Json::compare($entries[$a]->id, $entries[$b]->id));
        });
        $loads = \array_fill(0, \count($entries), 0);
        foreach ($order as $upper) {
            $spec = $entries[$upper]->spec;
            if ($spec === null) {
                continue;
            }
            $supports = $supporters[$upper];
            $totalArea = Exact::sum(\array_map(static function (array $edge) { return $edge[1]; }, $supports));
            if (Exact::sign($totalArea) === 0) {
                continue;
            }
            $downward = Exact::add($spec->weight, $loads[$upper]);
            $assigned = 0;
            foreach ($supports as $position => [$lower, $area]) {
                $share = $position === \count($supports) - 1
                    ? Exact::sub($downward, $assigned)
                    : Exact::floorDiv(Exact::mul($downward, $area), $totalArea);
                $assigned = Exact::add($assigned, $share);
                $loads[$lower] = Exact::add($loads[$lower], $share);
            }
        }
        return $loads;
    }

    /**
     * The pressure a footprint carries, in kPa, exactly:
     * `load * g * (ticks per metre)^2 / (ticks per kg * g denominator * Pa per kPa * area)`.
     *
     * @param int|string $load @param int|string $baseArea
     */
    private static function pressure($load, $baseArea): Ratio
    {
        return Ratio::of(
            Exact::mul($load, Exact::mul(980665, self::SQUARE_METRE_TICKS)),
            Exact::mul(Exact::mul(Units::TICKS_PER_KG, 100000000), $baseArea)
        );
    }

    /**: no `compressible` item may carry more pressure than it declared. @param list<Entry> $entries @param list<int|string> $loads @param list<ResultIssue> $issues */
    public static function checkCrush(Label $packedId, array $entries, array $loads, array &$issues): void
    {
        foreach ($entries as $index => $entry) {
            $limit = $entry->spec === null ? null : $entry->spec->maxCompressionPressureKpa;
            if ($limit === null) {
                continue;
            }
            $pressure = self::pressure($loads[$index], $entry->envelope->baseArea);
            if ($pressure->compareInt($limit) > 0) {
                $issues[] = new ResultIssue('crush_violation', PyFormat::str($entry->id) . " in {$packedId}: " . PyFormat::fixed6($pressure->toFloat()) . " kPa exceeds {$limit} kPa");
            }
        }
    }

    /**
     * Occupied height under load, rounded up, never below one tick. A crushed item keeps its
     * height: `checkCrush` already reports it.
     *
     * @param int|string $load @return int|string
     */
    public static function compressedHeight(Cuboid $envelope, ItemSpec $spec, $load)
    {
        $limit = $spec->maxCompressionPressureKpa;
        if ($limit === null) {
            return $envelope->height;
        }
        if ($spec->compressionRatioPpm === null) {
            // The reference asserts the pair: a pressure limit without a ratio is not a request it reads.
            throw MalformedResult::shape("{$spec->id}: max_compression_pressure_kpa without compression_ratio");
        }
        $pressure = self::pressure($load, $envelope->baseArea);
        if ($pressure->compareInt($limit) > 0 || $limit === 0) {
            return $envelope->height;
        }
        $divisor = Exact::mul(Exact::mul($limit, 1000000), $pressure->denominator);
        $retained = Exact::sub($divisor, Exact::mul($spec->compressionRatioPpm, $pressure->numerator));
        return Exact::max(1, Exact::ceilDiv(Exact::mul($envelope->height, $retained), $divisor));
    }

    /**: weight per square metre of an item's own base, at every level of the stack. @param list<Entry> $entries @param list<int|string> $loads @param int|string $maxDensity @param list<ResultIssue> $issues */
    public static function checkStackDensity(Label $packedId, array $entries, array $loads, $maxDensity, array &$issues): void
    {
        foreach ($entries as $index => $entry) {
            if ($entry->spec === null) {
                continue;
            }
            $total = Exact::add($entry->spec->weight, $loads[$index]);
            if (Exact::compare(Exact::mul($total, self::SQUARE_METRE_TICKS), Exact::mul($maxDensity, $entry->envelope->baseArea)) > 0) {
                $issues[] = new ResultIssue('stack_density_exceeded', PyFormat::str($entry->id) . " in {$packedId}: {$total} ticks over {$entry->envelope->baseArea} ticks^2 exceeds {$maxDensity} ticks/m^2");
            }
        }
    }

    /**
     * Items due at an earlier stop must all be removable -- unblocked from
     * above and slidable out a door (all six walls when the container names none) -- before the
     * route moves on.
     *
     * @param list<Entry> $entries @param list<array<int,true>> $directAbove
     * @param array<int,int|string> $container @param list<string> $doors @param list<ResultIssue> $issues
     */
    public static function checkRouteRemovalOrder(Label $packedId, array $entries, array $directAbove, array $container, array $doors, array &$issues): void
    {
        $stops = [];
        foreach ($entries as $index => $entry) {
            if ($entry->spec !== null && $entry->spec->stopIndex !== null) {
                $stops[$index] = $entry->spec->stopIndex;
            }
        }
        if ($stops === []) {
            return;
        }
        $directions = $doors === [] ? Spec::EXIT_DIRECTIONS : $doors;
        $present = \array_fill_keys(\array_keys($entries), true);
        $distinct = \array_values(\array_unique($stops));
        \sort($distinct);
        foreach ($distinct as $stop) {
            $due = [];
            foreach ($stops as $index => $value) {
                if ($value === $stop && isset($present[$index])) {
                    $due[$index] = true;
                }
            }
            while ($due !== []) {
                $chosen = null;
                foreach (\array_keys($due) as $item) {
                    if (\array_intersect_key($directAbove[$item], $present) !== []) {
                        continue;
                    }
                    if (self::hasClearExit($item, $entries, $present, $container, $directions)
                        && ($chosen === null || Json::compare($entries[$item]->id, $entries[$chosen]->id) < 0)) {
                        $chosen = $item;
                    }
                }
                if ($chosen === null) {
                    $names = \array_map(static function (int $index) use ($entries) { return $entries[$index]->id; }, \array_keys($due));
                    Json::sort($names);
                    $listed = PyFormat::listOf(\array_map([PyFormat::class, 'repr'], $names));
                    $issues[] = new ResultIssue('unloading_order_violation', "{$packedId}: stop {$stop} cannot fully unload {$listed} before the route moves on");
                    return;
                }
                unset($present[$chosen], $due[$chosen]);
            }
        }
    }

    /** @param list<Entry> $entries @param array<int,true> $present @param array<int,int|string> $container @param list<string> $directions */
    private static function hasClearExit(int $item, array $entries, array $present, array $container, array $directions): bool
    {
        foreach ($directions as $direction) {
            $region = self::sweptRegion($entries[$item]->envelope, $container, $direction);
            $blocked = false;
            foreach (\array_keys($present) as $other) {
                if ($other !== $item && $region->intersects($entries[$other]->envelope)) {
                    $blocked = true;
                    break;
                }
            }
            if (!$blocked) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,int|string> $container */
    private static function sweptRegion(Cuboid $envelope, array $container, string $direction): Cuboid
    {
        [$x1, $y1, $z1, $x2, $y2, $z2] = [$envelope->x, $envelope->y, $envelope->z, $envelope->x2, $envelope->y2, $envelope->z2];
        if ($direction === '+x') {
            [$x1, $x2] = [$x2, $container[0]];
        } elseif ($direction === '-x') {
            [$x1, $x2] = [0, $x1];
        } elseif ($direction === '+y') {
            [$y1, $y2] = [$y2, $container[1]];
        } elseif ($direction === '-y') {
            [$y1, $y2] = [0, $y1];
        } elseif ($direction === '+z') {
            [$z1, $z2] = [$z2, $container[2]];
        } else {
            [$z1, $z2] = [0, $z1];
        }
        return new Cuboid($x1, $y1, $z1, Exact::sub($x2, $x1), Exact::sub($y2, $y1), Exact::sub($z2, $z1));
    }

    /** Whether two stacked instances form a valid nested column. */
    public static function validNesting(Entry $left, Entry $right): bool
    {
        if ($left->spec === null || $right->spec === null || $left->spec->nestingHeight === null) {
            return false;
        }
        $nesting = $left->spec->nestingHeight;
        if ($left->spec->id !== $right->spec->id
            || !Json::equals(Json::at($left->placement, 'item_type'), Json::at($right->placement, 'item_type'))
            || $right->spec->nestingHeight === null || Exact::compare($right->spec->nestingHeight, $nesting) !== 0) {
            return false;
        }
        $a = $left->envelope;
        $b = $right->envelope;
        foreach (['x', 'y', 'x2', 'y2'] as $edge) {
            if (Exact::compare($a->{$edge}, $b->{$edge}) !== 0) {
                return false;
            }
        }
        [$lower, $upper] = Exact::compare($a->z, $b->z) <= 0 ? [$a, $b] : [$b, $a];
        return Exact::compare($lower->z, $upper->z) !== 0 && Exact::compare(Exact::sub($lower->z2, $upper->z), $nesting) === 0;
    }

    /** Space a placement actually takes: its compressed box, its hull, or its box. @param mixed $placement @param int|string $load @return int|string */
    public static function occupiedVolume(Cuboid $envelope, $placement, ?ItemSpec $spec, $load)
    {
        if ($spec !== null && $spec->maxCompressionPressureKpa !== null) {
            return Exact::mul($envelope->baseArea, self::compressedHeight($envelope, $spec, $load));
        }
        if ($spec !== null && $spec->isConvexHull() && $spec->hullVertices !== null) {
            return Geometry::hullVolume(Geometry::rotateHull($spec->hullVertices, Geometry::axes(Json::text(Json::at($placement, 'orientation'), 'orientation'))));
        }
        $solid = self::placedHull($envelope, $placement, $spec);
        if ($solid === $envelope->vertices()) {
            $dimensions = Json::at($placement, 'dimensions');
            $side = static function (string $name) use ($dimensions): int {
                return Json::int(Json::at(Json::at($dimensions, $name), 'ticks'), 'a placement dimension');
            };
            return Exact::mul(Exact::mul($side('length'), $side('width')), $side('height'));
        }
        return Geometry::hullVolume($solid);
    }

    /**
     * The solid a placement occupies, as vertices in container coordinates: the exact hull when the
     * request allows it and the reported box matches, otherwise the envelope's corners.
     *
     * @param mixed $placement @return list<array>
     */
    public static function placedHull(Cuboid $envelope, $placement, ?ItemSpec $spec): array
    {
        if ($spec === null || $spec->hullVertices === null || !$spec->isConvexHull() || $spec->stopIndex !== null) {
            return $envelope->vertices();
        }
        $axes = Geometry::axes(Json::text(Json::at($placement, 'orientation'), 'orientation'));
        $rotated = [$spec->dimensions[$axes[0]], $spec->dimensions[$axes[1]], $spec->dimensions[$axes[2]]];
        if (Exact::compare($envelope->length, $rotated[0]) !== 0 || Exact::compare($envelope->width, $rotated[1]) !== 0
            || Exact::compare($envelope->height, $rotated[2]) !== 0) {
            return $envelope->vertices();
        }
        return \array_map(static function (array $vertex) use ($envelope): array {
            return [Exact::add($envelope->x, $vertex[0]), Exact::add($envelope->y, $vertex[1]), Exact::add($envelope->z, $vertex[2])];
        }, Geometry::rotateHull($spec->hullVertices, $axes));
    }
}
