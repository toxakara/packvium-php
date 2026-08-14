<?php
declare(strict_types=1);
namespace Packvium\Constraint\Internal;
use Packvium\Domain\AxisAlignedBox;

/** Candidate support surfaces plus canonical positive-area direct supporters. */
final readonly class DirectSupportView
{
    /**
     * @param list<AxisAlignedBox> $surfaces
     * @param list<int> $supporterIndexes
     * @param list<AxisAlignedBox> $supporterBoxes
     * @param list<int> $supporterAreas
     */
    public function __construct(
        public array $surfaces,
        public array $supporterIndexes,
        public array $supporterBoxes,
        public array $supporterAreas,
        public int $supportingArea,
    ){}
}
