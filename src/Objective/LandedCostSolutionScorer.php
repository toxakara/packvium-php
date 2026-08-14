<?php
declare(strict_types=1);
namespace Packvium\Objective;

use Packvium\Algorithm\RawSolution;
use Packvium\Unit\Weight;

/**
 * Ranks by the money a shipment actually costs, not by the grams it is billed at.
 *
 * `shipping_cost` already ranks by billable weight, and for one tariff whose price rises
 * with weight the two orderings agree -- which is why this exists only for the cases where
 * they do not. A bracket step means two packings a gram apart can cost the same or differ
 * by a whole band, and a minimum charge flattens every light shipment onto one price; in
 * both, the cheaper answer is not the lighter one. It extends
 * `ShippingCostSolutionScorer` so the billed weight is that scorer's, not a second
 * derivation: the two objectives must never disagree about *what* is being priced.
 *
 * Every container must carry a rate table. Rating some and not others would silently rank
 * a priced packing against an unpriced one as though the unpriced were free.
 */
final class LandedCostSolutionScorer extends ShippingCostSolutionScorer
{
    protected const OBJECTIVE_NAME = 'lowest_landed_cost';

    public function score(RawSolution $solution): ObjectiveScore
    {
        [$unpacked, $containers, , $unused, $height] = (new DefaultSolutionScorer())->score($solution)->components;

        $landed = 0;
        foreach ($solution->containers as $container) {
            $table = $container->container->rateTable;
            if ($table === null) {
                throw new UnknownObjectiveException(
                    'the lowest_landed_cost objective requires a rate_table on every container; '
                    . "'{$container->container->id}' has none",
                );
            }
            $dimensions = $container->container->outerDimensions ?? $container->container->innerDimensions;
            $dimensionalWeight = $dimensions->dimensionalWeight($this->divisor, $this->lengthUnit, $this->weightUnit);
            $billedTicks = max($container->grossWeight()->ticks, $dimensionalWeight->ticks);
            $landed += $table->chargeMinor(self::grams($billedTicks));
        }

        return new ObjectiveScore([$unpacked, $landed, $containers, $unused, $height]);
    }

    /**
     * Billed weight in whole grams, rounded up.
     *
     * The rate table is published in grams while the engine carries sub-gram ticks.
     * Rounding up matches how a carrier reads a scale: a shipment fractionally over a
     * bracket is in the next bracket, and rounding down would price it below what the
     * carrier charges.
     */
    private static function grams(int $weightTicks): int
    {
        return intdiv($weightTicks + Weight::TICKS_PER_G - 1, Weight::TICKS_PER_G);
    }
}
