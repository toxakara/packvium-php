<?php
declare(strict_types=1);
namespace Packvium\Result;

final readonly class SolverMetrics
{
    public function __construct(
        public int $candidatePointsConsidered=0,
        public int $orientationsConsidered=0,
        public int $feasibleCandidates=0,
        public int $collisionChecks=0,
        public int $supportChecks=0,
        public int $spacePartitions=0,
        public int $searchNodesExpanded=0,
    ){}

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
