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
 */
final class Deadline
{
    /** A multi-start never gets less than this, otherwise a long start list would hand out slices too small to place a single item. */
    public const MINIMUM_SLICE_NS=1_000_000;

    private int $started;
    private readonly \Closure $clock;
    private readonly bool $usesRealClock;

    public function __construct(
        private readonly int $limitNs,
        ?\Closure $clock=null,
        private readonly ?EffortBudget $effortBudget=null,
        private readonly ?SearchStats $stats=null,
    )
    {
        $this->usesRealClock=$clock===null;
        $this->clock=$clock ?? static fn():int=>hrtime(true);
        $this->started=($this->clock)();
    }

    public static function ofMilliseconds(int $milliseconds, ?\Closure $clock=null):self
    {
        return new self($milliseconds*1_000_000,$clock);
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

    public function elapsedMs():int{return intdiv(($this->clock)()-$this->started,1_000_000);}
    public function remainingNs():int{return $this->limitNs-(($this->clock)()-$this->started);}
    public function effortExceeded():bool{return $this->effortBudget!==null && $this->stats!==null && $this->effortBudget->exceeded($this->stats);}
    public function expired():bool{return $this->effortExceeded() || $this->remainingNs()<=0;}

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
