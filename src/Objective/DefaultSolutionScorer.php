<?php
declare(strict_types=1);
namespace Packvium\Objective;

use Packvium\Algorithm\RawSolution;
use Packvium\Support\BigInt;

/**
 * Canonical objective vector — see docs/OBJECTIVE.md.
 *
 * Five lexicographic keys, ascending, lower is better. Every key is an exact integer:
 * the ratios are floored per container using decimal-string arithmetic rather than
 * binary floating point, so Python, PHP, Rust and the JavaScript fallback all agree
 * bit-for-bit.
 */
final class DefaultSolutionScorer implements SolutionScorer
{
    /** Ratios are expressed as parts per million so the vector stays exactly comparable. */
    private const SCALE = 1_000_000;

    public function score(RawSolution $solution): ObjectiveScore
    {
        $cost = 0;
        $unused = 0;
        $height = 0;

        foreach ($solution->containers as $container) {
            $inner = $container->container->innerDimensions;
            $cost += $container->container->costMinor;

            $volume = $inner->volumeString();
            $free = BigInt::subtract($volume, $container->usedVolumeString());
            $unused += (int)BigInt::divide(BigInt::multiply($free, (string)self::SCALE), $volume);

            $top = $container->maxZTicks();
            $height += (int)BigInt::divide(BigInt::multiply((string)$top, (string)self::SCALE), (string)$inner->height->ticks);
        }

        return new ObjectiveScore([
            count($solution->unpacked),
            count($solution->containers),
            $cost,
            $unused,
            $height,
        ]);
    }
}
