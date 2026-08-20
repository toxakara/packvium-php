<?php
declare(strict_types=1);
namespace Packvium\Commerce\Rating;

use Packvium\Support\Arithmetic;

/**
 * One named accessorial (residential delivery, liftgate, signature-required), either a
 * flat charge or a permille-of-base charge -- never both.
 */
final readonly class AccessorialCharge
{
    public function __construct(
        public string $accessorialId,
        public ?int $flatChargeMinor = null,
        public ?int $permilleOfBase = null,
    ) {
        if ($accessorialId === '') { throw new \InvalidArgumentException('accessorial_id is required'); }
        if (($flatChargeMinor === null) === ($permilleOfBase === null)) {
            throw new \InvalidArgumentException('an accessorial must set exactly one of flat_charge_minor or permille_of_base');
        }
        if ($flatChargeMinor !== null && $flatChargeMinor < 0) { throw new \InvalidArgumentException('flat_charge_minor cannot be negative'); }
        if ($permilleOfBase !== null && $permilleOfBase < 0) { throw new \InvalidArgumentException('permille_of_base cannot be negative'); }
    }

    public function chargeMinor(int $baseChargeMinor): int
    {
        return $this->flatChargeMinor ?? Arithmetic::mulDivCeil($baseChargeMinor, (int)$this->permilleOfBase, 1000);
    }
}
