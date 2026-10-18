<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

/** The parts of the request every per-item check reads. */
final class Context
{
    /** @var Ordered<ItemSpec> */
    public $items;
    /** @var Ordered<ContainerSpec> */
    public $containers;
    /** @var int|string */
    public $clearance;
    /** @var list<mixed> the participating `require_container_tag` rules */
    public $rules;

    /** @param Ordered<ItemSpec> $items @param Ordered<ContainerSpec> $containers @param int|string $clearance @param list<mixed> $rules */
    public function __construct(Ordered $items, Ordered $containers, $clearance, array $rules)
    {
        $this->items = $items;
        $this->containers = $containers;
        $this->clearance = $clearance;
        $this->rules = $rules;
    }
}
