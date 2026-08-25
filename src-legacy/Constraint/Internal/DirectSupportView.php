<?php
declare(strict_types=1);
namespace Packvium\Constraint\Internal;
use Packvium\Domain\AxisAlignedBox;

/** Candidate support surfaces plus canonical positive-area direct supporters. */
final class DirectSupportView
{
    /**
     * @var list<AxisAlignedBox>
     * @readonly
     */
    public $surfaces;
    /**
     * @var list<int>
     * @readonly
     */
    public $supporterIndexes;
    /**
     * @var list<AxisAlignedBox>
     * @readonly
     */
    public $supporterBoxes;
    /**
     * @var list<int>
     * @readonly
     */
    public $supporterAreas;
    /**
     * @readonly
     * @var int
     */
    public $supportingArea;
    /**
     * @param list<AxisAlignedBox> $surfaces
     * @param list<int> $supporterIndexes
     * @param list<AxisAlignedBox> $supporterBoxes
     * @param list<int> $supporterAreas
     */
    public function __construct(array $surfaces, array $supporterIndexes, array $supporterBoxes, array $supporterAreas, int $supportingArea)
    {
        $this->surfaces = $surfaces;
        $this->supporterIndexes = $supporterIndexes;
        $this->supporterBoxes = $supporterBoxes;
        $this->supporterAreas = $supporterAreas;
        $this->supportingArea = $supportingArea;
    }
}
