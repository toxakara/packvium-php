<?php
declare(strict_types=1);
namespace Packvium\Sequence;

/**
 * No safe removal order exists: every remaining placement is blocked in every allowed
 * direction. Carries the indices still unremovable when the search stopped, not just
 * the first one, since a real deadlock is usually a mutual one.
 */
final class SequenceError extends \RuntimeException
{
    /** The stable, forward-compatible discriminator carried in `toArray()` --
     * shared with the Python, Rust and JavaScript implementations.
     * Not named `$code`: `\Exception` already declares a non-readonly, integer
     * `$code` property that a stricter-typed readonly string cannot redeclare. */
    public const ERROR_CODE = 'sequence_stuck';

    /** @param list<int> $stuck */
    public function __construct(public readonly array $stuck)
    {
        $sorted = $this->stuck;
        sort($sorted);
        parent::__construct('no safe order exists: placements ' . implode(',', $sorted) . ' are mutually blocking');
    }

    /**
     * Canonical JSON shape shared with the Python, Rust and JavaScript
     * implementations.
     *
     * @return array{code:string,stuck:list<int>}
     */
    public function toArray(): array
    {
        $stuck = $this->stuck;
        sort($stuck);
        return ['code' => self::ERROR_CODE, 'stuck' => $stuck];
    }
}
