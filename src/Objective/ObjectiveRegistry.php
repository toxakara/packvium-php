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
    private static array $factories;

    public static function resolve(string $name, ?PackingConfig $config = null): SolutionScorer
    {
        self::$factories ??= [
            'default' => static fn(?PackingConfig $config) => new DefaultSolutionScorer(),
            'lowest_cost' => static fn(?PackingConfig $config) => new LowestCostSolutionScorer(),
            // Arrow functions rather than first-class callables, which load their class at once:
            // without opcache every request compiles what it loads (1.9.0 release frontier).
            'shipping_cost' => static fn(?PackingConfig $config) => ShippingCostSolutionScorer::fromConfig($config),
            // Inherits the divisor requirement: landed cost is priced off billed
            // weight, so it needs the same dimensional-weight inputs.
            'lowest_landed_cost' => static fn(?PackingConfig $config) => LandedCostSolutionScorer::fromConfig($config),
            'open_dimension_height' => static fn(?PackingConfig $config) => new OpenDimensionSolutionScorer(),
            'maximum_value' => static fn(?PackingConfig $config) => new MaximumValueSolutionScorer(),
            'maximum_volume' => static fn(?PackingConfig $config) => new MaximumVolumeSolutionScorer(),
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
