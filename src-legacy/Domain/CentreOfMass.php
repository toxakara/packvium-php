<?php
declare(strict_types=1);
namespace Packvium\Domain;
use Packvium\Support\BigInt;

/**
 * How far the weighted centre of mass sits from the container's own centre.
 *
 * Reported as parts per million of the half-extent along whichever of the two
 * horizontal axes (length, width) is worse -- the Chebyshev, not Euclidean, offset,
 * so the result stays exact: a Euclidean distance would need a square root, and this
 * library's whole premise is exact fixed-point arithmetic. 0% means
 * centred; 100% means the mass sits at the very edge along that axis. Needed for
 * axle load and side-to-side balance, both of which care about the worst axis, not a
 * single blended number that could hide either one.
 *
 * Every item contributes its own physical centre, weighted by its own mass -- an
 * item's clearance envelope is packing buffer, not part of what it weighs, so
 * `position`/`dimensions` are used rather than the (possibly larger) envelope.
 *
 * Kept exact throughout via `BigInt`: weight ticks (eighth-micrograms) times length
 * ticks routinely exceeds a 64-bit int for a heavy, metre-scale container, the same
 * risk `VolumeReserve` and `LoadCalculator::stackDensityExceeded` already guard
 * against. `BigInt` models non-negative integers only, so the numerator's sign is
 * resolved by comparison before subtracting, rather than computed directly.
 */
final class CentreOfMass
{
    /** @param list<Placement> $placements */
    public static function offsetPpm(Dimensions $inner, array $placements): int
    {
        $totalWeight = '0';
        foreach ($placements as $p) {
            $totalWeight = BigInt::add($totalWeight, (string)$p->instance->weight()->ticks);
        }
        if ($totalWeight === '0') {
            return 0;
        }

        $doubledWeightedX = '0';
        $doubledWeightedY = '0';
        foreach ($placements as $p) {
            $weight = (string)$p->instance->weight()->ticks;
            $doubledCenterX = (string)(2 * $p->position->x + $p->dimensions->length->ticks);
            $doubledCenterY = (string)(2 * $p->position->y + $p->dimensions->width->ticks);
            $doubledWeightedX = BigInt::add($doubledWeightedX, BigInt::multiply($weight, $doubledCenterX));
            $doubledWeightedY = BigInt::add($doubledWeightedY, BigInt::multiply($weight, $doubledCenterY));
        }

        $offsetXPpm = self::axisOffsetPpm($doubledWeightedX, $totalWeight, (string)$inner->length->ticks);
        $offsetYPpm = self::axisOffsetPpm($doubledWeightedY, $totalWeight, (string)$inner->width->ticks);
        return max($offsetXPpm, $offsetYPpm);
    }

    private static function axisOffsetPpm(string $doubledWeighted, string $totalWeight, string $extent): int
    {
        $reference = BigInt::multiply($totalWeight, $extent);
        $absNumerator = BigInt::compare($doubledWeighted, $reference) >= 0
            ? BigInt::subtract($doubledWeighted, $reference)
            : BigInt::subtract($reference, $doubledWeighted);
        return (int)BigInt::divide(BigInt::multiply($absNumerator, '1000000'), $reference);
    }
}
