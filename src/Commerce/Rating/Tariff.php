<?php
declare(strict_types=1);
namespace Packvium\Commerce\Rating;

/**
 * One immutable, numbered version of one (carrier_id, service_id) pair's rate card.
 * Append-only and effective-dated, never mutated in place.
 */
final readonly class Tariff
{
    /**
     * @param array<string,int>              $costPerDimensionalKgMinor zone -> minor cost per 1000 g
     * @param array<string,AccessorialCharge> $accessorials
     */
    public function __construct(
        public string $carrierId,
        public string $serviceId,
        public int $version,
        public int $effectiveAt,
        public int $dimensionalWeightDivisor,
        public array $costPerDimensionalKgMinor,
        public int $minimumChargeMinor,
        public int $fuelSurchargePermille,
        public array $accessorials,
    ) {
        if ($carrierId === '') { throw new \InvalidArgumentException('carrier_id is required'); }
        if ($serviceId === '') { throw new \InvalidArgumentException('service_id is required'); }
        if ($version <= 0) { throw new \InvalidArgumentException('version must be positive'); }
        if ($effectiveAt < 0) { throw new \InvalidArgumentException('effective_at cannot be negative'); }
        if ($dimensionalWeightDivisor <= 0) { throw new \InvalidArgumentException('dimensional_weight_divisor must be positive'); }
        if ($minimumChargeMinor < 0) { throw new \InvalidArgumentException('minimum_charge_minor cannot be negative'); }
        if ($fuelSurchargePermille < 0) { throw new \InvalidArgumentException('fuel_surcharge_permille cannot be negative'); }
        foreach ($costPerDimensionalKgMinor as $cost) {
            if ($cost < 0) { throw new \InvalidArgumentException('cost_per_dimensional_kg_minor entries cannot be negative'); }
        }
        foreach ($accessorials as $key => $charge) {
            if ($key !== $charge->accessorialId) {
                throw new \InvalidArgumentException('accessorials key must match its own accessorial_id: ' . $key);
            }
        }
    }
}
