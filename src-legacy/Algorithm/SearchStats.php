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
    /**
     * The request-level lower bound on the objective vector, computed once per start at the
     * root of an `exact_small` or global-beam solve.
     *
     * Deliberately absent from `metrics()`: `algorithm.metrics` is serialised into every
     * result, so a key there changes the bytes of every committed golden and has to land in
     * all four engines at once. Reporting a gap to a caller is also a new public result
     * field, which this project reserves and rejects before a contract freeze rather than
     * adding mid-line. `null` when no bound was computed -- a non-default objective keys its
     * score vector differently, so a bound compared against it would compare different
     * quantities.
     *
     * @var array{int,int,int,int,int}|null
     */
    public $objectiveLowerBound;

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
