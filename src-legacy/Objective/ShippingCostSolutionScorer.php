<?php
declare(strict_types=1);
namespace Packvium\Objective;

use Packvium\Algorithm\RawSolution;
use Packvium\Config\PackingConfig;

/**
 * Ranks by total carrier-billable weight ahead of container count, unused volume and
 * stack height.
 *
 * Each container's billable weight is `max(actual gross weight, dimensional weight)`
 * -- the standard carrier rule that a light-but-bulky shipment is billed by volume,
 * not scale weight. Dimensional weight is computed from the container's *outer*
 * (shipped-package) dimensions when declared, falling back to *inner* (usable)
 * dimensions only when no outer size was given -- a carrier measures the box that
 * moves, not its usable interior, and the two can differ once wall thickness or
 * packaging is involved. Exact throughout via `Dimensions::dimensionalWeight`, using
 * the caller-supplied divisor -- there is no library-chosen default, since a wrong
 * guess would silently misprice every shipment. Every input (each container's
 * reported gross weight, its request-declared dimensions, and the caller's own
 * divisor) is already public in the request/result pair, so this number is meant to
 * be reproduced by hand against a carrier's own calculator, the same audit property
 * `Dimensions::dimensionalWeight` itself documents.
 */
class ShippingCostSolutionScorer implements SolutionScorer
{
    /**
     * @readonly
     * @var int
     */
    protected $divisor;
    /**
     * @readonly
     * @var string
     */
    protected $lengthUnit = 'in';
    /**
     * @readonly
     * @var string
     */
    protected $weightUnit = 'lb';
    /** Named in the divisor error, so a subclass reports its own objective, not this one. */
    protected const OBJECTIVE_NAME = 'shipping_cost';

    // Protected rather than private so LandedCostSolutionScorer can price the *same*
    // billed weight this scorer computes instead of deriving a second one.
    public function __construct(int $divisor, string $lengthUnit = 'in', string $weightUnit = 'lb')
    {
        $this->divisor = $divisor;
        $this->lengthUnit = $lengthUnit;
        $this->weightUnit = $weightUnit;
    }

    /** @return static Late static binding: the subclass inherits the same divisor rule. */
    public static function fromConfig(?PackingConfig $config)
    {
        if ($config === null || $config->dimensionalWeightDivisor === null) {
            throw new UnknownObjectiveException(
                'the ' . static::OBJECTIVE_NAME . ' objective requires configuration.dimensional_weight_divisor',
            );
        }
        return new static(
            $config->dimensionalWeightDivisor,
            $config->dimensionalWeightLengthUnit,
            $config->dimensionalWeightWeightUnit,
        );
    }

    public function score(RawSolution $solution): ObjectiveScore
    {
        [$unpacked, $containers, , $unused, $height] = (new DefaultSolutionScorer())->score($solution)->components;

        $billable = 0;
        foreach ($solution->containers as $container) {
            $dimensions = $container->container->outerDimensions ?? $container->container->innerDimensions;
            $dimensionalWeight = $dimensions->dimensionalWeight($this->divisor, $this->lengthUnit, $this->weightUnit);
            $billable += max($container->grossWeight()->ticks, $dimensionalWeight->ticks);
        }

        return new ObjectiveScore([$unpacked, $billable, $containers, $unused, $height]);
    }
}
