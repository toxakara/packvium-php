<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Domain\AxisAlignedBox;
/** The only facts load propagation needs about a box, real or hypothetical. */
final readonly class LoadUnit
{
    public function __construct(public AxisAlignedBox $box,public int $weightTicks,public ?int $maxTopLoadTicks,public ?int $maxStackedItems,public string $label,public ?string $nestingItemId=null,public ?int $nestingHeightTicks=null){}
}
