<?php
declare(strict_types=1);
namespace Packvium\Objective;
use Packvium\Algorithm\RawSolution;

/**
 * Ranks by the raw achieved stack height ahead of container count, cost and unused
 * volume.
 *
 * `default`/`lowest_cost` express height as a *ratio* against the container's own
 * declared inner height (`stack_height_ppm`) -- meaningful when that height is a real
 * limit the caller cares about staying under. It stops being meaningful once a caller
 * declares a container whose height is a generous, otherwise arbitrary ceiling rather
 * than a real limit -- the "open dimension" case, where the real question
 * is "how tall does this end up", not "what fraction of some height did it use". This
 * objective answers that directly: it ranks by the summed raw achieved height
 * (`PackedContainer::maxZTicks()`, already computed by the solver -- no new geometry
 * work) across every opened container, in ticks, ahead of
 * `containerCount`/`totalCostMinor`/`unusedVolumePpm`. Every solver still treats a
 * container's declared inner height as a hard upper bound during placement (there is
 * no actually-unbounded container in this release -- see the scope note in
 * the project's issue tracker), so a caller wanting an open-dimension answer supplies a height
 * generous enough not to bind, and lets this objective find the shortest arrangement
 * that still fits everything else.
 *
 * O(c) additional work for `c` opened containers: one already-computed integer field
 * read per container, no new placement or geometry computation -- the same bound as
 * `DefaultSolutionScorer`/`LowestCostSolutionScorer`.
 */
final class OpenDimensionSolutionScorer implements SolutionScorer
{
    public function score(RawSolution $solution): ObjectiveScore
    {
        [$unpacked, $containers, $cost, $unused, ] = (new DefaultSolutionScorer())->score($solution)->components;
        $achievedHeight = 0;
        foreach ($solution->containers as $container) {
            $achievedHeight += $container->maxZTicks();
        }
        return new ObjectiveScore([$unpacked, $achievedHeight, $containers, $cost, $unused]);
    }
}
