<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/** A first-party SKU master record one catalog version pins. */
final class ItemMaster
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
    public $dimensionsMm;
    /**
     * @readonly
     * @var int
     */
    public $weightG;
    /**
     * @readonly
     * @var string
     */
    public $description = '';
    /** @param array{0:int,1:int,2:int} $dimensionsMm */
    public function __construct(string $id, array $dimensionsMm, int $weightG, string $description = '')
    {
        $this->id = $id;
        $this->dimensionsMm = $dimensionsMm;
        $this->weightG = $weightG;
        $this->description = $description;
        if ($id === '') { throw new \InvalidArgumentException('item id is required'); }
        if (count($dimensionsMm) !== 3) { throw new \InvalidArgumentException('item dimensions must have exactly three axes'); }
        foreach ($dimensionsMm as $axis) {
            if ($axis <= 0) { throw new \InvalidArgumentException('item dimensions must be positive'); }
        }
        if ($weightG <= 0) { throw new \InvalidArgumentException('item weight must be positive'); }
    }
}
