<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Domain\{PackedContainer,UnpackedItem};
final readonly class RawSolution
{
    /** @param list<PackedContainer> $containers @param list<UnpackedItem> $unpacked */
    public function __construct(public string $solverName,public array $containers,public array $unpacked,public SearchStats $stats,public bool $timeLimitReached=false,public bool $effortLimitReached=false,public bool $exhaustive=false){}
}
