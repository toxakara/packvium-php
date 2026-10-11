<?php
declare(strict_types=1);
namespace Packvium\Objective;

use Packvium\Algorithm\RawSolution;
use Packvium\Support\BigInt;

/**
 * Ranks by the share of the requested item volume left unpacked, ahead of how many
 * items that is.
 *
 * The knapsack reading of a load that cannot all fit: fill the space, rather than place
 * the most pieces. `default` prefers many small items to one large one, because it
 * counts pieces; this objective prefers whichever leaves less volume behind.
 *
 * `unpackedVolumePpm` is `ceil(unpacked volume x 10^6 / requested volume)`, in nominal
 * item volume. Rounded up, so an incomplete answer never scores zero: a complete answer
 * still beats every incomplete one, which is the guarantee `unpacked_count` gives the
 * other objectives by leading them. A ratio rather than the raw volume because cubic
 * ticks overflow a 64-bit integer for an ordinary freight container.
 *
 * O(n) additional work for `n` item instances -- one exact product each.
 */
final class MaximumVolumeSolutionScorer implements SolutionScorer
{
    private const SCALE = 1000000;

    public function score(RawSolution $solution): ObjectiveScore
    {
        [$unpacked, $containers, $cost, $unused, ] = (new DefaultSolutionScorer())->score($solution)->components;
        $unpackedVolume = '0';
        foreach ($solution->unpacked as $item) {
            $unpackedVolume = BigInt::add($unpackedVolume, $item->instance->dimensions()->volumeString());
        }
        $requested = $unpackedVolume;
        foreach ($solution->containers as $container) {
            $instances = $container->latticeItems !== []
                ? $container->latticeItems
                : array_map(static function ($placement) {
                    return $placement->instance;
                }, $container->placements);
            foreach ($instances as $instance) {
                $requested = BigInt::add($requested, $instance->dimensions()->volumeString());
            }
        }
        return new ObjectiveScore([self::ceilingPpm($unpackedVolume, $requested), $unpacked, $containers, $cost, $unused]);
    }

    private static function ceilingPpm(string $part, string $whole): int
    {
        if (BigInt::compare($whole, '0') === 0) {
            return 0;
        }
        $rounded = BigInt::add(BigInt::multiply($part, (string)self::SCALE), BigInt::subtract($whole, '1'));
        return (int)BigInt::divide($rounded, $whole);
    }
}
