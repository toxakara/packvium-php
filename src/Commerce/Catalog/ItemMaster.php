<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/** A first-party SKU master record one catalog version pins. */
final readonly class ItemMaster
{
    /** @param array{0:int,1:int,2:int} $dimensionsMm */
    public function __construct(public string $id, public array $dimensionsMm, public int $weightG, public string $description = '')
    {
        if ($id === '') { throw new \InvalidArgumentException('item id is required'); }
        if (count($dimensionsMm) !== 3) { throw new \InvalidArgumentException('item dimensions must have exactly three axes'); }
        foreach ($dimensionsMm as $axis) {
            if ($axis <= 0) { throw new \InvalidArgumentException('item dimensions must be positive'); }
        }
        if ($weightG <= 0) { throw new \InvalidArgumentException('item weight must be positive'); }
    }
}
