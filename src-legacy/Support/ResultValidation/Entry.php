<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

/** One placement as the container checks see it: its envelope, the placement, its item type. */
final class Entry
{
    /** @var mixed the item id, as the result spells it */
    public $id;
    /** @var Cuboid */
    public $envelope;
    /** @var mixed */
    public $placement;
    /** @var ItemSpec|null */
    public $spec;

    /** @param mixed $id @param mixed $placement */
    public function __construct($id, Cuboid $envelope, $placement, ?ItemSpec $spec)
    {
        $this->id = $id;
        $this->envelope = $envelope;
        $this->placement = $placement;
        $this->spec = $spec;
    }
}
