<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Result\SolverMetrics;
final class SearchStats
{
    public int $candidatesEvaluated=0;
    public int $placementsAttempted=0;
    public int $candidatePointsConsidered=0;
    public int $collisionChecks=0;
    public int $supportChecks=0;
    public int $spacePartitions=0;
    public int $searchNodesExpanded=0;

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
