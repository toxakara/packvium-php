<?php
declare(strict_types=1);
namespace Packvium\Algorithm;

use Packvium\Domain\Container;

/** Request-only bound eligibility; keeps numeric bound code lazy. @internal */
final class ObjectiveBoundScope
{
    /** A name rather than a position is the one key only that objective ranks by. */
    public const OBJECTIVE_ORDERS = [
        'default' => [0, 1, 2, 3, 4],
        'lowest_cost' => [0, 2, 1, 3, 4],
        'maximum_value' => [0, 'value_forgone', 1, 2, 3],
        'open_dimension_height' => [0, 'achieved_height', 1, 2, 3],
        'maximum_volume' => ['unpacked_volume_ppm', 0, 1, 2, 3],
    ];

    /**
     * O(containers) time, O(1) space; no instances or arithmetic are needed to refuse.
     * @param list<Container> $containers
     * @return list<int|string>|null
     */
    public static function order(array $containers, string $objective): ?array
    {
        $order = self::OBJECTIVE_ORDERS[$objective] ?? null;
        if ($order === null) {
            return null;
        }
        foreach ($containers as $container) {
            if ($container->madeToMeasure !== null) {
                return null;
            }
        }
        return $order;
    }
}
