<?php
declare(strict_types=1);
namespace Packvium\Commerce\Rating;

/**
 * One immutable, numbered version of one (carrier_id, service_id) pair's rate card.
 * Append-only and effective-dated, never mutated in place.
 */
final class Tariff
{
    /**
     * @readonly
     * @var string
     */
    public $carrierId;
    /**
     * @readonly
     * @var string
     */
    public $serviceId;
    /**
     * @readonly
     * @var int
     */
    public $version;
    /**
     * @readonly
     * @var int
     */
    public $effectiveAt;
    /**
     * @readonly
     * @var int
     */
    public $dimensionalWeightDivisor;
    /**
     * @var array<string, int>
     * @readonly
     */
    public $costPerDimensionalKgMinor;
    /**
     * @readonly
     * @var int
     */
    public $minimumChargeMinor;
    /**
     * @readonly
     * @var int
     */
    public $fuelSurchargePermille;
    /**
     * @var array<string, AccessorialCharge>
     * @readonly
     */
    public $accessorials;
    /**
     * @param array<string,int>              $costPerDimensionalKgMinor zone -> minor cost per 1000 g
     * @param array<string,AccessorialCharge> $accessorials
     */
    public function __construct(
        string $carrierId,
        string $serviceId,
        int $version,
        int $effectiveAt,
        int $dimensionalWeightDivisor,
        array $costPerDimensionalKgMinor,
        int $minimumChargeMinor,
        int $fuelSurchargePermille,
        array $accessorials
    ) {
        $this->carrierId = $carrierId;
        $this->serviceId = $serviceId;
        $this->version = $version;
        $this->effectiveAt = $effectiveAt;
        $this->dimensionalWeightDivisor = $dimensionalWeightDivisor;
        $this->costPerDimensionalKgMinor = $costPerDimensionalKgMinor;
        $this->minimumChargeMinor = $minimumChargeMinor;
        $this->fuelSurchargePermille = $fuelSurchargePermille;
        $this->accessorials = $accessorials;
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
