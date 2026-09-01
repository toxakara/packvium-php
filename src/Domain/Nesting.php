<?php
declare(strict_types=1);
namespace Packvium\Domain;
use Packvium\Support\{BigInt,StableSorter};

/**
 * Nesting overlap accounting.
 *
 * A naive sum of each placement's own volume overstates how much space a nested
 * column actually fills, since neighbouring nested layers share part of the same
 * physical space.
 */
final class Nesting
{
    /**
     * Whether `$a` and `$b` are exactly the nested-column relationship
     * `Item::$nestingHeight` describes: the same item type, the same footprint, one
     * sunk into the other by precisely its declared nesting height -- narrow and
     * exact, not a blanket "same item type never collides" bypass.
     */
    public static function isValidNesting(Placement $a, Placement $b): bool
    {
        if ($a->instance->item->id !== $b->instance->item->id) { return false; }
        $nesting = $a->instance->item->nestingHeight;
        if ($nesting === null) { return false; }
        $boxA = $a->envelopeBox(); $boxB = $b->envelopeBox();
        if ([$boxA->origin->x, $boxA->origin->y, $boxA->x2(), $boxA->y2()] !== [$boxB->origin->x, $boxB->origin->y, $boxB->x2(), $boxB->y2()]) { return false; }
        [$low, $high] = $boxA->origin->z <= $boxB->origin->z ? [$boxA, $boxB] : [$boxB, $boxA];
        if ($low->origin->z === $high->origin->z) { return false; }
        return $low->z2() - $high->origin->z === $nesting->ticks;
    }

    /**
     * Total double-counted volume between adjacent nested layers, as a decimal
     * string -- nesting depth times base area can overflow a 64-bit int for a
     * metre-scale footprint, the same risk `VolumeReserve` already guards against.
     *
     * Grouped by (item, footprint) and checked only against the immediate
     * z-neighbour -- O(n log n), not the O(n^2) an all-pairs scan would cost --
     * since a valid nest can only ever involve two immediately adjacent layers of
     * an identical footprint.
     *
     * @param list<Placement> $placements
     */
    private static function overlapVolume(array $placements): string
    {
        $groups = [];
        foreach ($placements as $p) {
            if ($p->instance->item->nestingHeight === null) { continue; }
            $box = $p->envelopeBox();
            $key = $p->instance->item->id . '|' . $box->origin->x . ',' . $box->origin->y . ',' . $box->x2() . ',' . $box->y2();
            $groups[$key][] = $p;
        }
        $overlap = '0';
        foreach ($groups as $group) {
            if (count($group) < 2) { continue; }
            $group = StableSorter::sortBy($group, static fn(Placement $p): array => [$p->envelopeBox()->origin->z]);
            for ($i = 0; $i < count($group) - 1; $i++) {
                $lower = $group[$i]; $upper = $group[$i + 1];
                if (self::isValidNesting($lower, $upper)) {
                    $slice = BigInt::multiply((string)$lower->instance->item->nestingHeight->ticks, (string)$lower->envelopeDimensions->baseAreaTicks());
                    $overlap = BigInt::add($overlap, $slice);
                }
            }
        }
        return $overlap;
    }

    /**
     * Space one placement actually takes, which is its box only if it is one.
     *
     * A `convex_hull` item occupies its hull: counting the bounding box is not a conservative
     * approximation of utilisation but a wrong number, putting two interlocking wedges at
     * 200% of a crate. A `compressible` item occupies the height left after the load it
     * reports, which is what makes `compression_ratio` observable at all.
     */
    public static function occupiedVolume(Placement $placement): string
    {
        $item = $placement->instance->item;
        // Route and clearance rules may conservatively use the envelope for collision, but
        // they do not change the physical solid reported in utilisation/reserve accounting.
        if ($item->shapeType === ShapeType::CONVEX_HULL) {
            return HullShape::shapeFor($item->hullVertices, $placement->rotation)->volume;
        }
        if ($item->maxCompressionPressureKpa === null) { return $placement->dimensions->volumeString(); }
        $dimensions = $placement->dimensions;
        $footprint = $dimensions->baseAreaTicks();
        $pressure = Compression::pressure($placement->topLoad->ticks, $footprint);
        // A crushed item has no meaningful occupied volume, and the arrangement is already
        // invalid -- the crush check refuses it and the validator reports it. Reporting the
        // uncompressed figure keeps that a reported issue rather than a second, quieter one.
        if (Compression::exceeds($pressure, $item->maxCompressionPressureKpa)) {
            return $dimensions->volumeString();
        }
        $height = Compression::effectiveHeight(
            $dimensions->height->ticks,
            (int)$item->compressionRatioPpm,
            $item->maxCompressionPressureKpa,
            $pressure,
        );
        return BigInt::multiply((string)$footprint, (string)$height);
    }

    /**
     * Physical volume actually occupied by `$placements`, nesting overlap removed.
     *
     * @param list<Placement> $placements
     */
    public static function usedVolume(array $placements): string
    {
        $total = '0';
        foreach ($placements as $p) { $total = BigInt::add($total, self::occupiedVolume($p)); }
        return BigInt::subtract($total, self::overlapVolume($placements));
    }

    /**
     * Exact physical-volume delta for appending one placement.
     *
     * Existing overlap pairs do not change. The ordinary non-nesting path is O(1);
     * a nesting-aware append scans the existing placements once in O(n) time and
     * O(1) additional space.
     *
     * @param list<Placement> $placements
     */
    public static function usedVolumeDelta(array $placements, Placement $placement): string
    {
        $nesting = $placement->instance->item->nestingHeight;
        if ($nesting === null) { return self::occupiedVolume($placement); }
        $overlap = '0';
        foreach ($placements as $existing) {
            if (!self::isValidNesting($existing, $placement)) { continue; }
            $slice = BigInt::multiply((string)$nesting->ticks, (string)$placement->envelopeDimensions->baseAreaTicks());
            $overlap = BigInt::add($overlap, $slice);
        }
        return BigInt::subtract($placement->dimensions->volumeString(), $overlap);
    }
}
