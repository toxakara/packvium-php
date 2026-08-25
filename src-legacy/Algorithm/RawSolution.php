<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Domain\{PackedContainer,UnpackedItem};
final class RawSolution
{
    /**
     * @readonly
     * @var string
     */
    public $solverName;
    /**
     * @var list<PackedContainer>
     * @readonly
     */
    public $containers;
    /**
     * @readonly
     * @var mixed[]
     */
    public $unpacked;
    /**
     * @readonly
     * @var \Packvium\Algorithm\SearchStats
     */
    public $stats;
    /**
     * @readonly
     * @var bool
     */
    public $timeLimitReached = false;
    /**
     * @readonly
     * @var bool
     */
    public $effortLimitReached = false;
    /**
     * @readonly
     * @var bool
     */
    public $exhaustive = false;
    /** @param list<PackedContainer> $containers @param list<UnpackedItem> $unpacked */
    public function __construct(string $solverName, array $containers, array $unpacked, SearchStats $stats, bool $timeLimitReached=false, bool $effortLimitReached=false, bool $exhaustive=false)
    {
        $this->solverName = $solverName;
        $this->containers = $containers;
        $this->unpacked = $unpacked;
        $this->stats = $stats;
        $this->timeLimitReached = $timeLimitReached;
        $this->effortLimitReached = $effortLimitReached;
        $this->exhaustive = $exhaustive;
    }
}
