<?php
declare(strict_types=1);
namespace Packvium\Sequence;

/**
 * A given order includes a step that is not actually feasible against only
 * the placements not yet removed at that point. Distinct from `SequenceError` (which
 * means *no* order exists at all): this means the specific order supplied is wrong,
 * whether or not some other order would have worked.
 */
final class SequenceReplayError extends \RuntimeException
{
    /** See `SequenceError::ERROR_CODE` for why this isn't named `$code`. */
    public const ERROR_CODE = 'sequence_replay';

    public function __construct(
        public readonly int $index,
        public readonly int $step,
        public readonly string $reason,
    )
    {
        parent::__construct("step {$step}: placement {$index} is not safe there ({$reason})");
    }

    /**
     * Canonical JSON shape shared with the Python, Rust and JavaScript
     * implementations.
     *
     * @return array{code:string,index:int,step:int,reason:string}
     */
    public function toArray(): array
    {
        return [
            'code' => self::ERROR_CODE,
            'index' => $this->index,
            'step' => $this->step,
            'reason' => $this->reason,
        ];
    }
}
