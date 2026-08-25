<?php
declare(strict_types=1);
namespace Packvium\Result;

final class SolverMetrics
{
    /**
     * @readonly
     * @var int
     */
    public $candidatePointsConsidered = 0;
    /**
     * @readonly
     * @var int
     */
    public $orientationsConsidered = 0;
    /**
     * @readonly
     * @var int
     */
    public $feasibleCandidates = 0;
    /**
     * @readonly
     * @var int
     */
    public $collisionChecks = 0;
    /**
     * @readonly
     * @var int
     */
    public $supportChecks = 0;
    /**
     * @readonly
     * @var int
     */
    public $spacePartitions = 0;
    /**
     * @readonly
     * @var int
     */
    public $searchNodesExpanded = 0;
    public function __construct(int $candidatePointsConsidered=0, int $orientationsConsidered=0, int $feasibleCandidates=0, int $collisionChecks=0, int $supportChecks=0, int $spacePartitions=0, int $searchNodesExpanded=0)
    {
        $this->candidatePointsConsidered = $candidatePointsConsidered;
        $this->orientationsConsidered = $orientationsConsidered;
        $this->feasibleCandidates = $feasibleCandidates;
        $this->collisionChecks = $collisionChecks;
        $this->supportChecks = $supportChecks;
        $this->spacePartitions = $spacePartitions;
        $this->searchNodesExpanded = $searchNodesExpanded;
    }

    /** @return array<string,int> */
    public function toArray():array
    {
        return [
            'candidate_points_considered'=>$this->candidatePointsConsidered,
            'orientations_considered'=>$this->orientationsConsidered,
            'feasible_candidates'=>$this->feasibleCandidates,
            'collision_checks'=>$this->collisionChecks,
            'support_checks'=>$this->supportChecks,
            'space_partitions'=>$this->spacePartitions,
            'search_nodes_expanded'=>$this->searchNodesExpanded,
        ];
    }
}
