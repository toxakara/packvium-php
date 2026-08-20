<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/** A first-party carton (box) master record one catalog version pins. */
final readonly class CartonMaster
{
    /** @param array{0:int,1:int,2:int} $innerDimensionsMm */
    public function __construct(public string $id, public array $innerDimensionsMm, public int $maxPayloadG, public int $costMinor = 0)
    {
        if ($id === '') { throw new \InvalidArgumentException('carton id is required'); }
        if (count($innerDimensionsMm) !== 3) { throw new \InvalidArgumentException('carton dimensions must have exactly three axes'); }
        foreach ($innerDimensionsMm as $axis) {
            if ($axis <= 0) { throw new \InvalidArgumentException('carton dimensions must be positive'); }
        }
        if ($maxPayloadG <= 0) { throw new \InvalidArgumentException('carton max_payload_g must be positive'); }
        if ($costMinor < 0) { throw new \InvalidArgumentException('cost_minor cannot be negative'); }
    }
}
