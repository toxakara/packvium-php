<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/** A first-party pallet master record one catalog version pins. */
final class PalletMaster
{
    /**
     * @readonly
     * @var string
     */
    public $id;
    /**
     * @var array{0: int, 1: int}
     * @readonly
     */
    public $deckDimensionsMm;
    /**
     * @readonly
     * @var int
     */
    public $maxPayloadG;
    /**
     * @readonly
     * @var int|null
     */
    public $maxStackHeightMm;
    /** @param array{0:int,1:int} $deckDimensionsMm */
    public function __construct(string $id, array $deckDimensionsMm, int $maxPayloadG, ?int $maxStackHeightMm = null)
    {
        $this->id = $id;
        $this->deckDimensionsMm = $deckDimensionsMm;
        $this->maxPayloadG = $maxPayloadG;
        $this->maxStackHeightMm = $maxStackHeightMm;
        if ($id === '') { throw new \InvalidArgumentException('pallet id is required'); }
        if (count($deckDimensionsMm) !== 2) { throw new \InvalidArgumentException('pallet deck dimensions must have exactly two axes'); }
        foreach ($deckDimensionsMm as $axis) {
            if ($axis <= 0) { throw new \InvalidArgumentException('pallet dimensions must be positive'); }
        }
        if ($maxPayloadG <= 0) { throw new \InvalidArgumentException('pallet max_payload_g must be positive'); }
        if ($maxStackHeightMm !== null && $maxStackHeightMm <= 0) { throw new \InvalidArgumentException('max_stack_height_mm must be positive'); }
    }
}
