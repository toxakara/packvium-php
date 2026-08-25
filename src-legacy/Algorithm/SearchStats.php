<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Result\SolverMetrics;
final class SearchStats
{
    /**
     * @var int
     */
    public $candidatesEvaluated=0;
    /**
     * @var int
     */
    public $placementsAttempted=0;
    /**
     * @var int
     */
    public $candidatePointsConsidered=0;
    /**
     * @var int
     */
    public $collisionChecks=0;
    /**
     * @var int
     */
    public $supportChecks=0;
    /**
     * @var int
     */
    public $spacePartitions=0;
    /**
     * @var int
     */
    public $searchNodesExpanded=0;

    public function metrics():SolverMetrics
    {
        return new SolverMetrics(
            $this->candidatePointsConsidered,
            $this->placementsAttempted,
            $this->candidatesEvaluated,
            $this->collisionChecks,
            $this->supportChecks,
            $this->spacePartitions,
            $this->searchNodesExpanded,
        );
    }
}
