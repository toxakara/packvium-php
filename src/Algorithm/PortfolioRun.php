<?php
declare(strict_types=1);

namespace Packvium\Algorithm;

use Packvium\Result\StartRecord;

final readonly class PortfolioRun
{
    /** @param list<RawSolution> $solutions @param list<StartRecord> $starts */
    public function __construct(public array $solutions, public array $starts) {}
}
