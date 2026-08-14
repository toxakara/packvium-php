<?php
declare(strict_types=1);
namespace Packvium\Objective;
use Packvium\Algorithm\RawSolution;

/**
 * Ranks by total container cost ahead of container count and unused volume.
 *
 * Completeness still dominates every other key -- a solution that leaves items
 * unpacked never wins for being cheaper, only among otherwise-complete solutions is
 * cost the deciding factor. See docs/OBJECTIVE.md.
 */
final class LowestCostSolutionScorer implements SolutionScorer
{
    public function score(RawSolution $solution): ObjectiveScore
    {
        [$unpacked, $containers, $cost, $unused, $height] = (new DefaultSolutionScorer())->score($solution)->components;
        return new ObjectiveScore([$unpacked, $cost, $containers, $unused, $height]);
    }
}
