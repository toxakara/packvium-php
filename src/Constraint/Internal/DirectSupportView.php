<?php
declare(strict_types=1);
namespace Packvium\Constraint\Internal;
use Packvium\Domain\AxisAlignedBox;

/** Positive-area direct supporters of one candidate, in placement-index order. */
final readonly class DirectSupportView
{
    /**
     * @param list<int> $supporterIndexes
     * @param list<AxisAlignedBox> $supporterBoxes
     * @param list<int> $supporterAreas
     */
    public function __construct(
        public array $supporterIndexes,
        public array $supporterBoxes,
        public array $supporterAreas,
        public int $supportingArea,
    ){}
}
