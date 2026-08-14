<?php
declare(strict_types=1);
namespace Packvium\Result;
final readonly class AlgorithmReport
{
    public function __construct(
        public string $profile,
        public string $solver,
        public int $durationMs,
        public int $seed,
        public bool $timeLimitReached=false,
        public bool $effortLimitReached=false,
        public int $candidatesEvaluated=0,
        public int $placementsAttempted=0,
        public SolverMetrics $metrics=new SolverMetrics(),
    ){}
}
