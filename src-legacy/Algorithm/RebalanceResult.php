<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Domain\PackedContainer;
final class RebalanceResult
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
    public $moves;
    /** @param list<PackedContainer> $containers @param list<WeightMove> $moves */
    public function __construct(array $containers, array $moves)
    {
        $this->containers = $containers;
        $this->moves = $moves;
    }
    public function improved(): bool { return $this->moves !== []; }
}
