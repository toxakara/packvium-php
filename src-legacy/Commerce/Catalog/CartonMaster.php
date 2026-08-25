<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/** A first-party carton (box) master record one catalog version pins. */
final class CartonMaster
{
    /**
     * @readonly
     * @var string
     */
    public $id;
    /**
     * @var array{0: int, 1: int, 2: int}
     * @readonly
     */
    public $innerDimensionsMm;
    /**
     * @readonly
     * @var int
     */
    public $maxPayloadG;
    /**
     * @readonly
     * @var int
     */
    public $costMinor = 0;
    /** @param array{0:int,1:int,2:int} $innerDimensionsMm */
    public function __construct(string $id, array $innerDimensionsMm, int $maxPayloadG, int $costMinor = 0)
    {
        $this->id = $id;
        $this->innerDimensionsMm = $innerDimensionsMm;
        $this->maxPayloadG = $maxPayloadG;
        $this->costMinor = $costMinor;
        if ($id === '') { throw new \InvalidArgumentException('carton id is required'); }
        if (count($innerDimensionsMm) !== 3) { throw new \InvalidArgumentException('carton dimensions must have exactly three axes'); }
        foreach ($innerDimensionsMm as $axis) {
            if ($axis <= 0) { throw new \InvalidArgumentException('carton dimensions must be positive'); }
        }
        if ($maxPayloadG <= 0) { throw new \InvalidArgumentException('carton max_payload_g must be positive'); }
        if ($costMinor < 0) { throw new \InvalidArgumentException('cost_minor cannot be negative'); }
    }
}
