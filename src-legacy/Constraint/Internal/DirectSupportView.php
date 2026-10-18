<?php
declare(strict_types=1);
namespace Packvium\Constraint\Internal;
use Packvium\Domain\AxisAlignedBox;

/** Positive-area direct supporters of one candidate, in placement-index order. */
final class DirectSupportView
{
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
     * @param list<int> $supporterIndexes
     * @param list<AxisAlignedBox> $supporterBoxes
     * @param list<int> $supporterAreas
     */
    public function __construct(array $supporterIndexes, array $supporterBoxes, array $supporterAreas, int $supportingArea)
    {
        $this->supporterIndexes = $supporterIndexes;
        $this->supporterBoxes = $supporterBoxes;
        $this->supporterAreas = $supporterAreas;
        $this->supportingArea = $supportingArea;
    }
}
