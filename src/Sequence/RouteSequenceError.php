<?php
declare(strict_types=1);
namespace Packvium\Sequence;

/**
 * No safe order exists that fully empties `$stop` before the route moves on to the
 * next one -- every placement still due there is blocked, whether by
 * another placement due at the same stop, one due at a later stop that has not left
 * yet, or one with no stop assigned at all. Distinct from `SequenceError`: that one
 * means no *unconstrained* order exists at all; this means the constrained,
 * stop-by-stop order the route itself demands does not.
 */
final class RouteSequenceError extends \RuntimeException
{
    /** @param list<int> $stuck */
    public function __construct(public readonly int $stop, public readonly array $stuck)
    {
        $sorted = $stuck;
        sort($sorted);
        parent::__construct("stop {$stop}: placements " . implode(',', $sorted) . ' cannot be unloaded there');
    }
}
