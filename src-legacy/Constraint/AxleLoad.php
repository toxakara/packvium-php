<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Domain\Axle;
use Packvium\Support\BigInt;

/**
 * Two-axle weight distribution.
 *
 * Two-point beam statics: taking moments about each axle in turn gives an exact
 * fraction for what the other axle carries, with no assumption weaker than "the
 * container behaves like a rigid beam resting on exactly two supports" -- true by
 * construction since `Container::$axles` only ever has two entries.
 */
final class AxleLoad
{
    /**
     * Whether either axle bears more than its own limit, as [code, detail].
     *
     * Takes `LoadUnit`s, the same "box + weight, real or hypothetical" abstraction
     * `LoadCalculator::topLoads`/`stackDensityExceeded` already share, rather than
     * requiring a real `Placement` -- a placement-time candidate does not have one
     * yet. A unit's own box is already its envelope; the envelope's centre
     * coincides exactly with the physical item's centre because clearance pads
     * both sides of every axis equally, so no separate physical/envelope
     * distinction is needed here.
     *
     * Kept exact throughout via `BigInt`: weight ticks (eighth-micrograms) times
     * length ticks routinely exceeds a 64-bit int for a heavy, metre-scale load --
     * a single multi-tonne item can already overflow it -- the same risk
     * `VolumeReserve`, `stackDensityExceeded` and `CentreOfMass` already guard
     * against. `BigInt` models non-negative integers only, so each numerator's
     * sign is resolved by comparison before subtracting, rather than computed
     * directly, and never rounded to an intermediate axle load -- both loads are
     * compared to their limits by cross-multiplying the same exact fraction.
     *
     * @param array{0:Axle,1:Axle} $axles
     * @param list<LoadUnit> $units
     * @return array{0:string,1:string}|null
     */
    public static function reactions(array $axles, array $units, int $tareWeightTicks=0, int $tareDoubledCenterX=0): array
    {
        [$front, $rear] = $axles;
        $frontX = (string)$front->position->ticks;
        $rearX = (string)$rear->position->ticks;
        $span = BigInt::subtract($rearX, $frontX); // positive: enforced by Container::__construct

        $totalWeight = (string)$tareWeightTicks;
        foreach ($units as $unit) {
            $totalWeight = BigInt::add($totalWeight, (string)$unit->weightTicks);
        }

        $doubledWeightedX = BigInt::multiply((string)$tareWeightTicks, (string)$tareDoubledCenterX);
        foreach ($units as $unit) {
            $doubledCenterX = (string)(2 * $unit->box->origin->x + $unit->box->dimensions->length->ticks);
            $doubledWeightedX = BigInt::add($doubledWeightedX, BigInt::multiply((string)$unit->weightTicks, $doubledCenterX));
        }

        $denominator = BigInt::multiply('2', $span);
        $twoTotalRearX = BigInt::multiply(BigInt::multiply('2', $totalWeight), $rearX);
        $twoTotalFrontX = BigInt::multiply(BigInt::multiply('2', $totalWeight), $frontX);
        return [
            'denominator' => $denominator,
            'front_numerator' => self::signedSubtract($twoTotalRearX, $doubledWeightedX),
            'rear_numerator' => self::signedSubtract($doubledWeightedX, $twoTotalFrontX),
        ];
    }

    public static function exceeded(array $axles, array $units, int $tareWeightTicks=0, int $tareDoubledCenterX=0): ?array
    {
        [$front, $rear] = $axles;
        $reaction = self::reactions($axles, $units, $tareWeightTicks, $tareDoubledCenterX);
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

    /**
     * The tightest x-origins that seat this item exactly on either axle's limit.
     *
     * `CandidateFinder` only ever proposes positions flush against the container
     * wall or another placed box's own corner -- correct for plain volume packing,
     * where nothing is ever gained by leaving a gap, but incomplete once axle
     * limits are in play: sometimes the only feasible spot for an item is floating
     * away from every wall and every other box, specifically to keep this item's
     * own moment from tipping one axle over its limit. A floor-level item needs no
     * lateral contact for support (see `SupportConstraint`), so that position is
     * otherwise unreachable by any point this module already generates.
     *
     * Solving the boundary equation from `reactions()` for this item's centre --
     * "where would the front/rear reaction land exactly on its limit if this
     * item's centre were here" -- turns the two axle limits into two extra
     * x-origins worth trying, on top of the ordinary extreme points. Both are
     * exact integer ticks, biased toward the safe side of their own limit (never
     * past it) since a placement that lands exactly on the boundary is still
     * allowed by `exceeded()`'s own strict `>` comparison; the caller runs every
     * candidate through the same collision and constraint checks regardless, so a
     * boundary that turns out unreachable (blocked, out of bounds, or infeasible
     * for the other axle) is simply rejected same as any other candidate.
     *
     * @param array{0:Axle,1:Axle} $axles
     * @param list<LoadUnit> $otherUnits
     * @return list<int>
     */
    public static function axleBalancedOrigins(array $axles, array $otherUnits, int $tareWeightTicks, int $tareDoubledCenterX, int $itemWeightTicks, int $itemLengthTicks): array
    {
        if ($itemWeightTicks <= 0) { return []; }
        [$front, $rear] = $axles;
        $frontX = (string)$front->position->ticks;
        $rearX = (string)$rear->position->ticks;
        $denominator = BigInt::multiply('2', BigInt::subtract($rearX, $frontX));

        $otherTotal = (string)$tareWeightTicks;
        foreach ($otherUnits as $unit) {
            $otherTotal = BigInt::add($otherTotal, (string)$unit->weightTicks);
        }
        $otherDoubledX = BigInt::multiply((string)$tareWeightTicks, (string)$tareDoubledCenterX);
        foreach ($otherUnits as $unit) {
            $doubledCenterX = (string)(2 * $unit->box->origin->x + $unit->box->dimensions->length->ticks);
            $otherDoubledX = BigInt::add($otherDoubledX, BigInt::multiply((string)$unit->weightTicks, $doubledCenterX));
        }
        $total = BigInt::add($otherTotal, (string)$itemWeightTicks);
        $itemWeight = (string)$itemWeightTicks;
        $itemLength = (string)$itemLengthTicks;

        $origins = [];
        if ($front->maxLoad !== null) {
            // Smallest doubled centre for which front_numerator <= front limit * denominator.
            $required = self::sSub(
                self::sSub(BigInt::multiply(BigInt::multiply('2', $total), $rearX), BigInt::multiply((string)$front->maxLoad->ticks, $denominator)),
                $otherDoubledX,
            );
            $doubledCentre = self::sCeilDiv($required, $itemWeight); // ceil: never understate what front needs
            $origins[] = (int)self::sCeilDiv(self::sSub($doubledCentre, $itemLength), '2'); // ceil: stay on the safe side
        }
        if ($rear->maxLoad !== null) {
            // Largest doubled centre for which rear_numerator <= rear limit * denominator.
            $allowed = self::sSub(
                self::sAdd(BigInt::multiply((string)$rear->maxLoad->ticks, $denominator), BigInt::multiply(BigInt::multiply('2', $total), $frontX)),
                $otherDoubledX,
            );
            $doubledCentre = self::sFloorDiv($allowed, $itemWeight); // floor: never overstate what rear allows
            $origins[] = (int)self::sFloorDiv(self::sSub($doubledCentre, $itemLength), '2'); // floor: stay on the safe side
        }
        return $origins;
    }

    private static function sNeg(string $v): string
    {
        if ($v === '0') { return '0'; }
        return strncmp($v, '-', strlen('-')) === 0 ? (string) substr($v, 1) : "-$v";
    }

    private static function sAbs(string $v): string
    {
        return strncmp($v, '-', strlen('-')) === 0 ? (string) substr($v, 1) : $v;
    }

    private static function sAdd(string $a, string $b): string
    {
        $aNeg = strncmp($a, '-', strlen('-')) === 0; $bNeg = strncmp($b, '-', strlen('-')) === 0;
        $aMag = self::sAbs($a); $bMag = self::sAbs($b);
        if ($aNeg === $bNeg) {
            $sum = BigInt::add($aMag, $bMag);
            return $aNeg ? self::sNeg($sum) : $sum;
        }
        $comparison = BigInt::compare($aMag, $bMag);
        if ($comparison === 0) { return '0'; }
        return $comparison > 0
            ? ($aNeg ? self::sNeg(BigInt::subtract($aMag, $bMag)) : BigInt::subtract($aMag, $bMag))
            : ($bNeg ? self::sNeg(BigInt::subtract($bMag, $aMag)) : BigInt::subtract($bMag, $aMag));
    }

    private static function sSub(string $a, string $b): string
    {
        return self::sAdd($a, self::sNeg($b));
    }

    /** Floor division by a strictly positive (unsigned) `$denominator`. */
    private static function sFloorDiv(string $numerator, string $denominator): string
    {
        if (strncmp($numerator, '-', strlen('-')) !== 0) { return BigInt::divide($numerator, $denominator); }
        $mag = self::sAbs($numerator);
        $quotient = BigInt::divide($mag, $denominator);
        $remainder = BigInt::subtract($mag, BigInt::multiply($quotient, $denominator));
        return $remainder === '0' ? self::sNeg($quotient) : self::sNeg(BigInt::add($quotient, '1'));
    }

    /** Ceiling division by a strictly positive (unsigned) `$denominator`. */
    private static function sCeilDiv(string $numerator, string $denominator): string
    {
        return self::sNeg(self::sFloorDiv(self::sNeg($numerator), $denominator));
    }
}
