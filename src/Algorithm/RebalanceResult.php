<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Domain\PackedContainer;
final readonly class RebalanceResult
{
    /** @param list<PackedContainer> $containers @param list<WeightMove> $moves */
    public function __construct(public array $containers, public array $moves) {}
    public function improved(): bool { return $this->moves !== []; }
}
