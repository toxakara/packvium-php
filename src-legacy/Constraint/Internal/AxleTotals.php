<?php
declare(strict_types=1);
namespace Packvium\Constraint\Internal;
use Packvium\Constraint\LoadUnit;
use Packvium\Domain\{AxisAlignedBox,Axle,Placement};
use Packvium\Support\BigInt;

/**
 * The exact sums behind the two-axle check: gross weight and doubled
 * longitudinal moment, and the verdict they give.
 *
 * Kept apart from `AxleLoad` so `AxleLoadConstraint` can sum a placed scene once per
 * search state and add each candidate in O(1) without the public `AxleLoad`
 * growing a method for it. Every sum is a `BigInt` string: weight ticks times length
 * ticks routinely exceed a 64-bit int.
 */
final class AxleTotals
{
    /**
     * Tare plus every placement, each at its envelope's centre. O(m).
     *
     * @param list<Placement> $placements
     * @return array{0:string,1:string} gross weight, doubled weighted x
     */
    public static function ofPlacements(array $placements, int $tareWeightTicks, int $tareDoubledCenterX): array
    {
        $totalWeight = (string)$tareWeightTicks;
        $doubledWeightedX = BigInt::multiply((string)$tareWeightTicks, (string)$tareDoubledCenterX);
        foreach ($placements as $placement) {
            $weight = (string)$placement->instance->weight()->ticks;
            $totalWeight = BigInt::add($totalWeight, $weight);
            $doubledCenterX = (string)(2 * $placement->envelopeOrigin->x + $placement->envelopeDimensions->length->ticks);
            $doubledWeightedX = BigInt::add($doubledWeightedX, BigInt::multiply($weight, $doubledCenterX));
        }
        return [$totalWeight, $doubledWeightedX];
    }

    /**
     * Tare plus every unit, each at its own box's centre. O(m).
     *
     * @param list<LoadUnit> $units
     * @return array{0:string,1:string} gross weight, doubled weighted x
     */
    public static function ofUnits(array $units, int $tareWeightTicks, int $tareDoubledCenterX): array
    {
        $totalWeight = (string)$tareWeightTicks;
        foreach ($units as $unit) {
            $totalWeight = BigInt::add($totalWeight, (string)$unit->weightTicks);
        }
        $doubledWeightedX = BigInt::multiply((string)$tareWeightTicks, (string)$tareDoubledCenterX);
        foreach ($units as $unit) {
            $doubledCenterX = (string)(2 * $unit->box->origin->x + $unit->box->dimensions->length->ticks);
            $doubledWeightedX = BigInt::add($doubledWeightedX, BigInt::multiply((string)$unit->weightTicks, $doubledCenterX));
        }
        return [$totalWeight, $doubledWeightedX];
    }

    /**
     * `$totals` with one more box of `$weightTicks` at its centre. O(1).
     *
     * @param array{0:string,1:string} $totals
     * @return array{0:string,1:string}
     */
    public static function plus(array $totals, AxisAlignedBox $box, int $weightTicks): array
    {
        $weight = (string)$weightTicks;
        $doubledCenterX = (string)(2 * $box->origin->x + $box->dimensions->length->ticks);
        return [BigInt::add($totals[0], $weight), BigInt::add($totals[1], BigInt::multiply($weight, $doubledCenterX))];
    }

    /**
     * What each axle carries, as numerators over one shared denominator.
     *
     * @param array{0:Axle,1:Axle} $axles
     * @param array{0:string,1:string} $totals
     * @return array{denominator:string,front_numerator:string,rear_numerator:string}
     */
    public static function reactions(array $axles, array $totals): array
    {
        [$front, $rear] = $axles;
        $frontX = (string)$front->position->ticks;
        $rearX = (string)$rear->position->ticks;
        $span = BigInt::subtract($rearX, $frontX); // positive: enforced by Container::__construct
        [$totalWeight, $doubledWeightedX] = $totals;
        $twoTotal = BigInt::multiply('2', $totalWeight);
        return [
            'denominator' => BigInt::multiply('2', $span),
            'front_numerator' => self::signedSubtract(BigInt::multiply($twoTotal, $rearX), $doubledWeightedX),
            'rear_numerator' => self::signedSubtract($doubledWeightedX, BigInt::multiply($twoTotal, $frontX)),
        ];
    }

    /**
     * The first axle over its own limit, as [code, detail], front before rear.
     *
     * @param array{0:Axle,1:Axle} $axles
     * @param array{0:string,1:string} $totals
     * @return array{0:string,1:string}|null
     */
    public static function exceeded(array $axles, array $totals): ?array
    {
        [$front, $rear] = $axles;
        $reaction = self::reactions($axles, $totals);
        if ($front->maxLoad !== null && self::overLimit($reaction['front_numerator'], $front->maxLoad->ticks, $reaction['denominator'])) {
            return ['axle_overloaded', 'front'];
        }
        if ($rear->maxLoad !== null && self::overLimit($reaction['rear_numerator'], $rear->maxLoad->ticks, $reaction['denominator'])) {
            return ['axle_overloaded', 'rear'];
        }
        return null;
    }

    private static function signedSubtract(string $left, string $right): string
    {
        $comparison = BigInt::compare($left, $right);
        if ($comparison === 0) { return '0'; }
        return $comparison > 0
            ? BigInt::subtract($left, $right)
            : '-'.BigInt::subtract($right, $left);
    }

    private static function overLimit(string $numerator, int $maxLoadTicks, string $denominator): bool
    {
        if (strncmp($numerator, '-', strlen('-')) === 0) { return false; }
        $threshold = BigInt::multiply((string)$maxLoadTicks, $denominator);
        return BigInt::compare($numerator, $threshold) > 0;
    }
}
