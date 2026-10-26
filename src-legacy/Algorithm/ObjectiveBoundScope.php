<?php
declare(strict_types=1);
namespace Packvium\Algorithm;

use Packvium\Domain\Container;

/** Request-only bound eligibility; keeps numeric bound code lazy. @internal */
final class ObjectiveBoundScope
{
    public const OBJECTIVE_ORDERS = [
        'default' => [0, 1, 2, 3, 4],
        'lowest_cost' => [0, 2, 1, 3, 4],
    ];

    /**
     * O(containers) time, O(1) space; no instances or arithmetic are needed to refuse.
     * @param list<Container> $containers
     * @return list<int>|null
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
