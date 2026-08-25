<?php
declare(strict_types=1);
namespace Packvium\Commerce\Rating;

/**
 * The fully itemized, auditable result of one rating call. Every component that
 * contributed to $totalMinor is named individually, alongside the exact tariff version
 * that produced it.
 */
final class RateBreakdown
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
    public $tariffVersion;
    /**
     * @readonly
     * @var string
     */
    public $zone;
    /**
     * @readonly
     * @var int
     */
    public $actualWeightG;
    /**
     * @readonly
     * @var int
     */
    public $dimensionalWeightG;
    /**
     * @readonly
     * @var int
     */
    public $billedWeightG;
    /**
     * @readonly
     * @var int
     */
    public $baseChargeMinor;
    /**
     * @readonly
     * @var bool
     */
    public $minimumChargeApplied;
    /**
     * @readonly
     * @var int
     */
    public $fuelSurchargeMinor;
    /**
     * @var list<array{0: string, 1: int}>
     * @readonly
     */
    public $accessorialChargesMinor;
    /**
     * @readonly
     * @var int
     */
    public $totalMinor;
    /** @param list<array{0:string,1:int}> $accessorialChargesMinor */
    public function __construct(string $carrierId, string $serviceId, int $tariffVersion, string $zone, int $actualWeightG, int $dimensionalWeightG, int $billedWeightG, int $baseChargeMinor, bool $minimumChargeApplied, int $fuelSurchargeMinor, array $accessorialChargesMinor, int $totalMinor)
    {
        $this->carrierId = $carrierId;
        $this->serviceId = $serviceId;
        $this->tariffVersion = $tariffVersion;
        $this->zone = $zone;
        $this->actualWeightG = $actualWeightG;
        $this->dimensionalWeightG = $dimensionalWeightG;
        $this->billedWeightG = $billedWeightG;
        $this->baseChargeMinor = $baseChargeMinor;
        $this->minimumChargeApplied = $minimumChargeApplied;
        $this->fuelSurchargeMinor = $fuelSurchargeMinor;
        $this->accessorialChargesMinor = $accessorialChargesMinor;
        $this->totalMinor = $totalMinor;
    }

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
