<?php
declare(strict_types=1);
namespace Packvium\Objective;

use Packvium\Algorithm\RawSolution;
use Packvium\Config\PackingConfig;
use Packvium\Domain\PackedContainer;
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
 *
 * A billed weight past the last bracket is different: it is a property of how the search
 * happened to fill the box, not of the request, so it loses a candidate rather than
 * aborting a run that has a perfectly shippable alternative. Scoring it UNPRICEABLE_MINOR
 * is what makes the priceable alternative win; the packer refuses if that sentinel is
 * still standing when an answer is about to be returned.
 */
final class LandedCostSolutionScorer extends ShippingCostSolutionScorer
{
    protected const OBJECTIVE_NAME = 'lowest_landed_cost';

    /**
     * Ranks a packing the tariff cannot price behind every priceable one during search.
     * A search device, never an answer. Matches Rust's and Python's sentinel so the
     * engines order candidates identically.
     */
    public const UNPRICEABLE_MINOR = PHP_INT_MAX;

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
            $charge = $table->chargeMinorOrNull(self::grams($billedTicks));
            if ($charge === null) {
                $landed = self::UNPRICEABLE_MINOR;
                break;
            }
            $landed += $charge;
        }

        return new ObjectiveScore([$unpacked, $landed, $containers, $unused, $height]);
    }

    /**
     * The first container in a finished answer its own rate table cannot price, as
     * [container id, billed grams, last bracket], or null when every one prices.
     *
     * Ranking an unpriceable candidate worst is what lets a priceable alternative win the
     * round. This is the guard that stops the sentinel from surfacing: returning a packing
     * the tariff cannot price would quote a number the carrier never published.
     *
     * @param list<PackedContainer> $containers
     * @return array{0:string,1:int,2:int}|null
     */
    public static function unpriceableContainer(array $containers, PackingConfig $config): ?array
    {
        if ($config->objective !== self::OBJECTIVE_NAME || $config->dimensionalWeightDivisor === null) {
            return null;
        }
        foreach ($containers as $container) {
            $dimensions = $container->container->outerDimensions ?? $container->container->innerDimensions;
            $dimensional = $dimensions->dimensionalWeight(
                $config->dimensionalWeightDivisor,
                $config->dimensionalWeightLengthUnit,
                $config->dimensionalWeightWeightUnit,
            )->ticks;
            $grams = self::grams(max($container->grossWeight()->ticks, $dimensional));
            $table = $container->container->rateTable;
            if ($table === null) {
                return [$container->container->id, $grams, 0];
            }
            if ($table->chargeMinorOrNull($grams) === null) {
                return [$container->container->id, $grams, $table->lastBracketG()];
            }
        }

        return null;
    }

    /**
     * Billed weight in whole grams, rounded up.
     *
     * The rate table is published in grams while the engine carries sub-gram ticks.
     * Rounding up matches how a carrier reads a scale: a shipment fractionally over a
     * bracket is in the next bracket, and rounding down would price it below what the
     * carrier charges.
     *
     * Public because the per-round container choice and the packer's final priceability
     * guard must round exactly as the score does; a second copy could drift a bracket.
     */
    public static function grams(int $weightTicks): int
    {
        return intdiv($weightTicks + Weight::TICKS_PER_G - 1, Weight::TICKS_PER_G);
    }
}
