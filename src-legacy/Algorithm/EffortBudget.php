<?php
declare(strict_types=1);
namespace Packvium\Algorithm;

use Packvium\Support\ObjectRefusal;

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
    /**
     * @readonly
     * @var int|null
     */
    public $maxCandidatesEvaluated;
    /**
     * @readonly
     * @var int|null
     */
    public $maxPlacementAttempts;
    /**
     * @readonly
     * @var int|null
     */
    public $maxSearchNodes;
    /**
     * @readonly
     * @var int|null
     */
    public $maxRestarts;
    public function __construct(
        ?int $maxCandidatesEvaluated=null,
        ?int $maxPlacementAttempts=null,
        ?int $maxSearchNodes=null,
        ?int $maxRestarts=null
    ) {
        $this->maxCandidatesEvaluated = $maxCandidatesEvaluated;
        $this->maxPlacementAttempts = $maxPlacementAttempts;
        $this->maxSearchNodes = $maxSearchNodes;
        $this->maxRestarts = $maxRestarts;
        // A limit of zero or less would stop every search before its first step, which no
        // caller means; the schema's floor for each is 1.
        foreach (['maxCandidatesEvaluated'=>$maxCandidatesEvaluated,'maxPlacementAttempts'=>$maxPlacementAttempts,'maxSearchNodes'=>$maxSearchNodes,'maxRestarts'=>$maxRestarts] as $name=>$limit) {
            if ($limit!==null && $limit<1) {
                throw new ObjectRefusal('below_minimum',"effortBudget.{$name} must be at least 1");
            }
        }
    }

    public function exceeded(SearchStats $stats):bool
    {
        return ($this->maxCandidatesEvaluated!==null && $stats->candidatesEvaluated>=$this->maxCandidatesEvaluated)
            || ($this->maxPlacementAttempts!==null && $stats->placementsAttempted>=$this->maxPlacementAttempts)
            || ($this->maxSearchNodes!==null && $stats->searchNodesExpanded>=$this->maxSearchNodes);
    }
}

// The public name of this class is `Packvium\Config\EffortBudget`: one class under
// two names, so an instance from either spelling satisfies a type declared with the other.
// Registered here, beside the declaration, because PHP does not autoload for a type check:
// a budget decoded by `ArrayCodec` must already answer to the public name.
\class_alias(EffortBudget::class, \Packvium\Config\EffortBudget::class);
