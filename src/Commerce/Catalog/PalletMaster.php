<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/** A first-party pallet master record one catalog version pins. */
final readonly class PalletMaster
{
    /** @param array{0:int,1:int} $deckDimensionsMm */
    public function __construct(public string $id, public array $deckDimensionsMm, public int $maxPayloadG, public ?int $maxStackHeightMm = null)
    {
        if ($id === '') { throw new \InvalidArgumentException('pallet id is required'); }
        if (count($deckDimensionsMm) !== 2) { throw new \InvalidArgumentException('pallet deck dimensions must have exactly two axes'); }
        foreach ($deckDimensionsMm as $axis) {
            if ($axis <= 0) { throw new \InvalidArgumentException('pallet dimensions must be positive'); }
        }
        if ($maxPayloadG <= 0) { throw new \InvalidArgumentException('pallet max_payload_g must be positive'); }
        if ($maxStackHeightMm !== null && $maxStackHeightMm <= 0) { throw new \InvalidArgumentException('max_stack_height_mm must be positive'); }
    }
}
