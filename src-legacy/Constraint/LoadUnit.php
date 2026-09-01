<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Domain\AxisAlignedBox;
/** The only facts load propagation needs about a box, real or hypothetical. */
final class LoadUnit
{
    /**
     * @readonly
     * @var \Packvium\Domain\AxisAlignedBox
     */
    public $box;
    /**
     * @readonly
     * @var int
     */
    public $weightTicks;
    /**
     * @readonly
     * @var int|null
     */
    public $maxTopLoadTicks;
    /**
     * @readonly
     * @var int|null
     */
    public $maxStackedItems;
    /**
     * @readonly
     * @var string
     */
    public $label;
    /**
     * @readonly
     * @var string|null
     */
    public $nestingItemId;
    /**
     * @readonly
     * @var int|null
     */
    public $nestingHeightTicks;
    /**
     * @readonly
     * @var int|null
     */
    public $compressionRatioPpm;
    /**
     * @readonly
     * @var int|null
     */
    public $maxCompressionPressureKpa;
    public function __construct(AxisAlignedBox $box, int $weightTicks, ?int $maxTopLoadTicks, ?int $maxStackedItems, string $label, ?string $nestingItemId=null, ?int $nestingHeightTicks=null, ?int $compressionRatioPpm=null, ?int $maxCompressionPressureKpa=null)
    {
        $this->box = $box;
        $this->weightTicks = $weightTicks;
        $this->maxTopLoadTicks = $maxTopLoadTicks;
        $this->maxStackedItems = $maxStackedItems;
        $this->label = $label;
        $this->nestingItemId = $nestingItemId;
        $this->nestingHeightTicks = $nestingHeightTicks;
        // Set only for a `compressible` item. Load propagation already computes the
        // cumulative mass above every unit, which is exactly the numerator the pressure model
        // needs, so the crush check rides the graph that is built anyway.
        $this->compressionRatioPpm = $compressionRatioPpm;
        $this->maxCompressionPressureKpa = $maxCompressionPressureKpa;
    }
}
