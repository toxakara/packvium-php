<?php
declare(strict_types=1);
namespace Packvium\Objective;
use Packvium\Algorithm\RawSolution;

/**
 * Ranks by the total declared value of unpacked items ahead of container count, cost
 * and unused volume.
 *
 * Every objective begins with `unpacked_count`: no selectable trade-off may prefer an
 * incomplete answer over a complete one, this one included. Its purpose is the
 * tie-break among solutions that leave the same *number* of items unpacked but not the
 * same *value* -- the number alone is indifferent between leaving behind a pallet of
 * high-value goods or a pallet of packing foam. `valueForgone` is the sum of
 * `Item::$value` (zero when unset) across every unpacked item.
 *
 * O(u) additional work for `u` unpacked items -- one already-known integer field read
 * per unpacked item, the same bound class as every other named objective here.
 */
final class MaximumValueSolutionScorer implements SolutionScorer
{
    public function score(RawSolution $solution): ObjectiveScore
    {
        [$unpacked, $containers, $cost, $unused, ] = (new DefaultSolutionScorer())->score($solution)->components;
        $valueForgone = 0;
        foreach ($solution->unpacked as $item) {
            $valueForgone += $item->instance->item->value ?? 0;
        }
        return new ObjectiveScore([$unpacked, $valueForgone, $containers, $cost, $unused]);
    }
}
