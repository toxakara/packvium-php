<?php
declare(strict_types=1);
namespace Packvium\Algorithm;

/**
 * Counted-work limits, checked alongside (never instead of) the wall clock.
 *
 * A wall clock is not a reproducible measure of work: scheduler, CPU frequency,
 * background load, cache state and GC all move where it trips. Counting instead
 * lets a caller who needs bit-identical results across machines and loads buy
 * that -- as long as the wall-clock cutoff stays generous enough not to trip
 * first, since it remains a live safety bound even when an effort budget is set.
 * `maxRestarts` bounds the portfolio's multi-start loop directly; the other three
 * mirror `SearchStats` fields and are checked against one solver call's own count.
 */
final class EffortBudget
{
    public function __construct(
        public readonly ?int $maxCandidatesEvaluated=null,
        public readonly ?int $maxPlacementAttempts=null,
        public readonly ?int $maxSearchNodes=null,
        public readonly ?int $maxRestarts=null,
    ) {}

    public function exceeded(SearchStats $stats):bool
    {
        return ($this->maxCandidatesEvaluated!==null && $stats->candidatesEvaluated>=$this->maxCandidatesEvaluated)
            || ($this->maxPlacementAttempts!==null && $stats->placementsAttempted>=$this->maxPlacementAttempts)
            || ($this->maxSearchNodes!==null && $stats->searchNodesExpanded>=$this->maxSearchNodes);
    }
}
