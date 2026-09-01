<?php
declare(strict_types=1);
namespace Packvium\Sequence;

use Packvium\Domain\AxisAlignedBox;
use Packvium\Domain\Dimensions;
use Packvium\Domain\SweptRegion;

/**
 * Accessibility primitives shared by `UnloadingDependencyGraph` and
 * `LoadingDependencyGraph`.
 *
 * `direction` names which container wall's region a box's sweep occupies (its own
 * face to that wall), not a direction of travel: the region between a box and its
 * `+x` wall is the same region whether the box is *leaving* through it (unloading,
 * sliding toward `+x`) or *arriving* through it (loading, coming from outside and
 * sliding toward the box's own resting `-x` side). `blocked` answers "is this region
 * clear of every other present item" identically for both; the two graphs differ only
 * in which items count as present at each step and which structural dependency
 * (children vs. supporters) gates the step at all.
 */
final class SequenceGeometry
{
    /** Re-exported: the vocabulary now lives in `Domain` so the constraint layer can
     * share it without `Sequence` having to be imported downwards. */
    public const ALL_DIRECTIONS = SweptRegion::ALL_DIRECTIONS;

    /** @param list<string> $directions */
    public static function validated(array $directions): array
    {
        foreach ($directions as $direction) {
            if (!in_array($direction, self::ALL_DIRECTIONS, true)) {
                throw new InvalidDirectionError($direction);
            }
        }
        return $directions;
    }

    /** The region between `$box`'s own face and the matching container wall along `$direction`. @return array{0:int,1:int,2:int,3:int,4:int,5:int} */
    public static function sweptVolume(AxisAlignedBox $box, Dimensions $container, string $direction): array
    {
        self::validated([$direction]);
        return SweptRegion::volume($box, $container, $direction);
    }

    /** Every other currently-present box whose envelope intersects `$box`'s `$direction` sweep -- the evidence `blocked` reduces to a bare boolean. @param list<AxisAlignedBox> $boxes @param array<int,true> $present @return array<int,true> */
    public static function blockingIndices(int $index, AxisAlignedBox $box, array $boxes, array $present, Dimensions $container, string $direction): array
    {
        $sweep = self::sweptVolume($box, $container, $direction);
        $blocking = [];
        foreach (array_keys($present) as $otherIndex) {
            if ($otherIndex !== $index && SweptRegion::intersects($sweep, $boxes[$otherIndex])) {
                $blocking[$otherIndex] = true;
            }
        }
        return $blocking;
    }

    /**
     * Would `$box`'s `$direction` sweep collide with any other currently-present box?
     *
     * Same predicate as `blockingIndices`, but stopping at the first blocker: the
     * boolean callers (the safe-order search and reachability sweeps) ask it O(n*d)
     * times per step and never read the set, which only the evidence paths need.
     *
     * @param list<AxisAlignedBox> $boxes @param array<int,true> $present
     */
    public static function blocked(int $index, AxisAlignedBox $box, array $boxes, array $present, Dimensions $container, string $direction): bool
    {
        $sweep = self::sweptVolume($box, $container, $direction);
        foreach (array_keys($present) as $otherIndex) {
            if ($otherIndex !== $index && SweptRegion::intersects($sweep, $boxes[$otherIndex])) {
                return true;
            }
        }
        return false;
    }

    /** The first allowed direction (in the order given) whose sweep is clear, or null if every one is blocked. @param list<AxisAlignedBox> $boxes @param array<int,true> $present @param list<string> $directions */
    public static function clearDirection(int $index, AxisAlignedBox $box, array $boxes, array $present, Dimensions $container, array $directions): ?string
    {
        foreach ($directions as $direction) {
            if (!self::blocked($index, $box, $boxes, $present, $container, $direction)) { return $direction; }
        }
        return null;
    }

    /** Independently validate containment and collisions against the replay state. @param list<AxisAlignedBox> $boxes @param array<int,true> $present */
    public static function validateBoxAtStep(int $index, int $step, array $boxes, array $present, Dimensions $container): void
    {
        $box = $boxes[$index];
        if ($box->origin->x < 0 || $box->origin->y < 0 || $box->origin->z < 0
            || $box->x2() > $container->length->ticks
            || $box->y2() > $container->width->ticks
            || $box->z2() > $container->height->ticks) {
            throw new SequenceReplayError($index, $step, 'placement is outside the container');
        }
        foreach (array_keys($present) as $otherIndex) {
            if ($otherIndex !== $index && $box->intersects($boxes[$otherIndex])) {
                throw new SequenceReplayError($index, $step, 'placement collides with an already present placement');
            }
        }
    }

    /** @param array<int,array<int,true>> $dependsOn */
    public static function isAcyclic(array $dependsOn): bool
    {
        $visiting = [];
        $visited = [];
        $visit = function (int $node) use (&$visit, &$visiting, &$visited, $dependsOn): bool {
            if (isset($visited[$node])) { return true; }
            if (isset($visiting[$node])) { return false; }
            $visiting[$node] = true;
            foreach (array_keys($dependsOn[$node] ?? []) as $dependency) {
                if (!$visit($dependency)) { return false; }
            }
            unset($visiting[$node]);
            $visited[$node] = true;
            return true;
        };
        foreach (array_keys($dependsOn) as $node) {
            if (!$visit($node)) { return false; }
        }
        return true;
    }
}
