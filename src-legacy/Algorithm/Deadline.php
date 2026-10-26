<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
/**
 * Monotonic budget that can hand bounded sub-budgets to individual multi-starts.
 *
 * An attached `EffortBudget` is read against a live `SearchStats`, so `expired()`/
 * `check()` trip on whichever bound -- time or counted work -- is hit first. Every
 * existing call site already polls `expired()`/`check()`, so attaching an effort
 * budget (via `withEffort`) needs no changes anywhere else; leaving it unattached
 * (the default) reproduces the exact prior time-only behaviour.
 *
 * A spent budget answers `true` to a poll, and that poll is a search refusing further work:
 * the deadline records it (`effortStopped()`), so the effort flag says the budget stopped the
 * search, not that a counter equals its limit afterwards. A search that used exactly
 * its budget and finished was not stopped. Code asking afterwards whether a search was cut
 * reads `stopped()`, which records nothing.
 */
final class Deadline
{
    /**
     * @readonly
     * @var int
     */
    private $limitNs;
    /**
     * @readonly
     * @var \Packvium\Algorithm\EffortBudget|null
     */
    private $effortBudget;
    /**
     * @readonly
     * @var \Packvium\Algorithm\SearchStats|null
     */
    private $stats;
    /** A multi-start never gets less than this, otherwise a long start list would hand out slices too small to place a single item. */
    public const MINIMUM_SLICE_NS=1000000;

    /**
     * @var int
     */
    private $started;
    /**
     * @readonly
     * @var \Closure
     */
    private $clock;
    /**
     * @readonly
     * @var bool
     */
    private $usesRealClock;
    /** Per-solve state: set once a poll found the effort budget spent and the search stopped there.
     * @var bool */
    private $effortStopped=false;

    public function __construct(
        int $limitNs,
        ?\Closure $clock=null,
        ?EffortBudget $effortBudget=null,
        ?SearchStats $stats=null
    )
    {
        $this->limitNs = $limitNs;
        $this->effortBudget = $effortBudget;
        $this->stats = $stats;
        $this->usesRealClock=$clock===null;
        $this->clock=$clock ?? static function (): int {
            return hrtime(true);
        };
        $this->started=($this->clock)();
    }

    public static function ofMilliseconds(int $milliseconds, ?\Closure $clock=null):self
    {
        return new self($milliseconds*1000000,$clock);
    }

    /**
     * True only for the unmodified system monotonic clock (`hrtime(true)`).
     *
     * An injected test/simulation clock closure has no meaning outside the process
     * that owns it -- after `pcntl_fork()`, the child's copy of that closure's
     * captured state diverges from the parent's immediately and independently, so
     * two "concurrent" starts driven by an injected clock would not actually be
     * comparing against the same instant the way real wall-clock time is shared
     * machine-wide. `SolverOrchestrator`'s concurrent portfolio path only
     * ever forks when this is true; otherwise it falls back to the fully sequential
     * path, the only one an injected clock can drive correctly.
     */
    public function usesRealClock():bool{return $this->usesRealClock;}

    /**
     * Rebuild a deadline bound to a shared absolute `hrtime(true)` instant.
     *
     * `hrtime(true)` reads a machine-wide monotonic clock, not a per-process one,
     * so an absolute instant computed in the parent before forking is a valid,
     * directly comparable deadline in every forked child: each independently
     * compares its own fresh `hrtime(true)` reading against the same instant,
     * honouring one shared budget without dividing it the way `slice()` does.
     */
    public static function until(int $absoluteNs):self
    {
        return new self(max($absoluteNs-hrtime(true),0));
    }

    public function elapsedMs():int{return intdiv(($this->clock)()-$this->started,1000000);}
    public function remainingNs():int{return $this->limitNs-(($this->clock)()-$this->started);}
    /** Whether the budget is spent; a query that records nothing. */
    public function effortExceeded():bool{return $this->effortBudget!==null && $this->stats!==null && $this->effortBudget->exceeded($this->stats);}
    /**
     * Whether the effort budget stops the search at this poll, recording that it did. Only a
     * caller about to do more work asks: asking is refusing it.
     */
    public function effortStops():bool
    {
        if(!$this->effortExceeded())return false;
        $this->effortStopped=true;
        return true;
    }
    /** Whether a poll found the effort budget spent and the search stopped there. */
    public function effortStopped():bool{return $this->effortStopped;}
    /** Whether the search was cut, by the effort budget at a poll or by the clock; records nothing. */
    public function stopped():bool{return $this->effortStopped || $this->remainingNs()<=0;}
    /**
     * How many more search nodes counted into `$counted` may expand before `effortExceeded()`
     * trips, while nothing else that budget counts moves: zero once it has tripped, and
     * `PHP_INT_MAX` when no node budget reads `$counted`. A solver that counts a run of nodes
     * in one step stops on the same node as one that checks before each.
     */
    public function searchNodesLeft(SearchStats $counted):int
    {
        if($this->effortExceeded())return 0;
        if($this->effortBudget===null||$this->effortBudget->maxSearchNodes===null||$this->stats!==$counted)return PHP_INT_MAX;
        return $this->effortBudget->maxSearchNodes-$counted->searchNodesExpanded;
    }
    /**
     * The `placementsAttempted` and `candidatesEvaluated` values of `$counted` at which the budget
     * stops a search, `PHP_INT_MAX` for a counter no limit reads. A scan compares a counter with
     * its ceiling before each step, so the counter never passes its limit. O(1).
     *
     * @return array{int,int}
     */
    public function placementCeilings(SearchStats $counted):array
    {
        $budget=$this->effortBudget;
        if($budget===null||$this->stats!==$counted)return [PHP_INT_MAX,PHP_INT_MAX];
        return [$budget->maxPlacementAttempts??PHP_INT_MAX,$budget->maxCandidatesEvaluated??PHP_INT_MAX];
    }
    /**
     * Whether the budget leaves room for `$candidates`, `$placements` and `$nodes` more counted
     * steps on `$counted`, so a solver that counts a run of steps at once never passes a limit;
     * a query that records nothing. O(1).
     */
    public function effortRoom(SearchStats $counted,int $candidates,int $placements,int $nodes):bool
    {
        $budget=$this->effortBudget;
        if($budget===null||$this->stats!==$counted)return true;
        return ($budget->maxCandidatesEvaluated===null||$counted->candidatesEvaluated+$candidates<=$budget->maxCandidatesEvaluated)
            && ($budget->maxPlacementAttempts===null||$counted->placementsAttempted+$placements<=$budget->maxPlacementAttempts)
            && ($budget->maxSearchNodes===null||$counted->searchNodesExpanded+$nodes<=$budget->maxSearchNodes);
    }
    public function expired():bool{return $this->effortStops() || $this->remainingNs()<=0;}

    public function slice(int $parts):self
    {
        $remaining=$this->remainingNs();
        if($parts<=1)return new self($remaining,$this->clock,$this->effortBudget,$this->stats);
        return new self(max(intdiv($remaining,$parts),min($remaining,self::MINIMUM_SLICE_NS)),$this->clock,$this->effortBudget,$this->stats);
    }

    /** Bind an effort budget to this start's own stats, keeping the same time bound. */
    public function withEffort(?EffortBudget $effortBudget, SearchStats $stats):self
    {
        return new self($this->remainingNs(),$this->clock,$effortBudget,$stats);
    }

    public function check():void{if($this->expired())throw new TimeLimitReached('Packing time limit reached');}
}
