<?php
declare(strict_types=1);

namespace Packvium\Algorithm;

use Packvium\Result\StartRecord;

final class PortfolioRun
{
    /**
     * @var list<RawSolution>
     * @readonly
     */
    public $solutions;
    /**
     * @readonly
     * @var mixed[]
     */
    public $starts;
    /** @param list<RawSolution> $solutions @param list<StartRecord> $starts */
    public function __construct(array $solutions, array $starts)
    {
        $this->solutions = $solutions;
        $this->starts = $starts;
    }
}
