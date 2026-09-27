<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Domain\{ItemInstance,PackedContainer};
/**
 * What the solve starts from: the fixed containers, and the items still to place.
 *
 * `$containers` are in opening order -- request container order, then instance -- each
 * holding only its fixed placements. `$free` is every instance a fixed placement did not
 * take, in request order.
 */
final class FixedLoad
{
    /**
     * @var list<PackedContainer>
     * @readonly
     */
    public $containers;
    /**
     * @readonly
     * @var mixed[]
     */
    public $free;
    /** @param list<PackedContainer> $containers @param list<ItemInstance> $free */
    public function __construct(array $containers, array $free)
    {
        $this->containers = $containers;
        $this->free = $free;
    }
}
