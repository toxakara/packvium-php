<?php
declare(strict_types=1);
namespace Packvium\Objective;

use Packvium\Config\PackingConfig;

/**
 * Named objectives a caller may select through `PackingConfig::$objective`.
 *
 * Each factory takes the active `PackingConfig` (possibly `null`) so a parametrized
 * objective like `shipping_cost` can pull its own inputs from it without widening the
 * `SolutionScorer::score()` interface every extension author already implements
 * against.
 */
final class ObjectiveRegistry
{
    /** @var array<string, callable(?PackingConfig):SolutionScorer> */
    private static $factories;

    public static function resolve(string $name, ?PackingConfig $config = null): SolutionScorer
    {
        self::$factories = self::$factories ?? [
            'default' => static function (?PackingConfig $config) {
                return new DefaultSolutionScorer();
            },
            'lowest_cost' => static function (?PackingConfig $config) {
                return new LowestCostSolutionScorer();
            },
            'shipping_cost' => \Closure::fromCallable([ShippingCostSolutionScorer::class, 'fromConfig']),
            // Inherits the divisor requirement: landed cost is priced off billed
            // weight, so it needs the same dimensional-weight inputs.
            'lowest_landed_cost' => \Closure::fromCallable([LandedCostSolutionScorer::class, 'fromConfig']),
            'open_dimension_height' => static function (?PackingConfig $config) {
                return new OpenDimensionSolutionScorer();
            },
            'maximum_value' => static function (?PackingConfig $config) {
                return new MaximumValueSolutionScorer();
            },
        ];
        $factory = self::$factories[$name] ?? null;
        if ($factory === null) {
            throw new UnknownObjectiveException(
                'unknown objective ' . $name . '; expected one of ' . implode(', ', array_keys(self::$factories)),
            );
        }
        return $factory($config);
    }
}
