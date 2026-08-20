<?php
declare(strict_types=1);
namespace Packvium\Commerce\Rating;

/**
 * The fully itemized, auditable result of one rating call. Every component that
 * contributed to $totalMinor is named individually, alongside the exact tariff version
 * that produced it.
 */
final readonly class RateBreakdown
{
    /** @param list<array{0:string,1:int}> $accessorialChargesMinor */
    public function __construct(
        public string $carrierId,
        public string $serviceId,
        public int $tariffVersion,
        public string $zone,
        public int $actualWeightG,
        public int $dimensionalWeightG,
        public int $billedWeightG,
        public int $baseChargeMinor,
        public bool $minimumChargeApplied,
        public int $fuelSurchargeMinor,
        public array $accessorialChargesMinor,
        public int $totalMinor,
    ) {}

    /** @return array<string,mixed> The wire shape docs/COMMERCE-API.md specifies. */
    public function toArray(): array
    {
        return [
            'carrier_id' => $this->carrierId,
            'service_id' => $this->serviceId,
            'tariff_version' => $this->tariffVersion,
            'zone' => $this->zone,
            'actual_weight_g' => $this->actualWeightG,
            'dimensional_weight_g' => $this->dimensionalWeightG,
            'billed_weight_g' => $this->billedWeightG,
            'base_charge_minor' => $this->baseChargeMinor,
            'minimum_charge_applied' => $this->minimumChargeApplied,
            'fuel_surcharge_minor' => $this->fuelSurchargeMinor,
            'accessorial_charges_minor' => $this->accessorialChargesMinor,
            'total_minor' => $this->totalMinor,
        ];
    }
}
