<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Domain\AxisAlignedBox;
/** The only facts load propagation needs about a box, real or hypothetical. */
final readonly class LoadUnit
{
    public function __construct(
        public AxisAlignedBox $box,
        public int $weightTicks,
        public ?int $maxTopLoadTicks,
        public ?int $maxStackedItems,
        public string $label,
        public ?string $nestingItemId=null,
        public ?int $nestingHeightTicks=null,
        // Set only for a `compressible` item. Load propagation already computes the
        // cumulative mass above every unit, which is exactly the numerator the pressure model
        // needs, so the crush check rides the graph that is built anyway.
        public ?int $compressionRatioPpm=null,
        public ?int $maxCompressionPressureKpa=null,
    ){}
}
