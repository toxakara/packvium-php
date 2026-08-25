<?php
declare(strict_types=1);
namespace Packvium\Result;
final class AlgorithmReport
{
    /**
     * @readonly
     * @var string
     */
    public $profile;
    /**
     * @readonly
     * @var string
     */
    public $solver;
    /**
     * @readonly
     * @var int
     */
    public $durationMs;
    /**
     * @readonly
     * @var int
     */
    public $seed;
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
     * @var int
     */
    public $candidatesEvaluated = 0;
    /**
     * @readonly
     * @var int
     */
    public $placementsAttempted = 0;
    /**
     * @readonly
     * @var \Packvium\Result\SolverMetrics
     */
    public $metrics;
    public function __construct(string $profile, string $solver, int $durationMs, int $seed, bool $timeLimitReached=false, bool $effortLimitReached=false, int $candidatesEvaluated=0, int $placementsAttempted=0, ?SolverMetrics $metrics=null)
    {
        $metrics = $metrics ?? new SolverMetrics();
        $this->profile = $profile;
        $this->solver = $solver;
        $this->durationMs = $durationMs;
        $this->seed = $seed;
        $this->timeLimitReached = $timeLimitReached;
        $this->effortLimitReached = $effortLimitReached;
        $this->candidatesEvaluated = $candidatesEvaluated;
        $this->placementsAttempted = $placementsAttempted;
        $this->metrics = $metrics;
    }
}
