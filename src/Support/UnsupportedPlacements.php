<?php
declare(strict_types=1);

namespace Packvium\Support;

use Packvium\Domain\PackedContainer;

/**
 * The warning: the default minimum_support_ratio of 0 is kept so that no packing
 * answer changes, and a caller who never set it is told instead.
 *
 * The count reads the support ratio every placement already reports, O(placements) with
 * no geometry: an obstacle is geometry only and gives no support, so an item on one
 * counts, while a fixed placement is a supporter like any other. A lattice summary is
 * always floor-up and has no placements. Every engine emits this text byte for byte.
 */
final class UnsupportedPlacements
{
    /** @param list<PackedContainer> $containers */
    public static function warning(array $containers, float $minimumSupportRatio): ?string
    {
        if ($minimumSupportRatio !== 0.0) {
            return null;
        }
        $count = 0;
        foreach ($containers as $container) {
            foreach ($container->placements as $placement) {
                if ($placement->supportRatio === 0.0 && $placement->position->z > 0) {
                    $count++;
                }
            }
        }
        if ($count === 0) {
            return null;
        }
        return "unsupported_placements: {$count} placement(s) rest on nothing above the container floor; "
            . 'set configuration.minimum_support_ratio above 0 to require support';
    }
}
