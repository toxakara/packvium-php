<?php
declare(strict_types=1);
namespace Packvium\Sequence;

use Packvium\Domain\AxisAlignedBox;
use Packvium\Domain\Dimensions;

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
    public const ALL_DIRECTIONS = ['+x', '-x', '+y', '-y', '+z', '-z'];

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
        $x1 = $box->origin->x; $y1 = $box->origin->y; $z1 = $box->origin->z;
        $x2 = $box->x2(); $y2 = $box->y2(); $z2 = $box->z2();
        switch ($direction) {
            case '+x': $x1 = $x2; $x2 = $container->length->ticks; break;
            case '-x': $x2 = $x1; $x1 = 0; break;
            case '+y': $y1 = $y2; $y2 = $container->width->ticks; break;
            case '-y': $y2 = $y1; $y1 = 0; break;
            case '+z': $z1 = $z2; $z2 = $container->height->ticks; break;
            case '-z': $z2 = $z1; $z1 = 0; break;
            default: throw new InvalidDirectionError($direction);
        }
        return [$x1, $y1, $z1, $x2, $y2, $z2];
    }

    /** Every other currently-present box whose envelope intersects `$box`'s `$direction` sweep -- the evidence `blocked` reduces to a bare boolean. @param list<AxisAlignedBox> $boxes @param array<int,true> $present @return array<int,true> */
    public static function blockingIndices(int $index, AxisAlignedBox $box, array $boxes, array $present, Dimensions $container, string $direction): array
    {
        [$sx1, $sy1, $sz1, $sx2, $sy2, $sz2] = self::sweptVolume($box, $container, $direction);
        $blocking = [];
        foreach (array_keys($present) as $otherIndex) {
            if ($otherIndex === $index) { continue; }
            $other = $boxes[$otherIndex];
            if ($sx1 < $other->x2() && $other->origin->x < $sx2
                && $sy1 < $other->y2() && $other->origin->y < $sy2
                && $sz1 < $other->z2() && $other->origin->z < $sz2) {
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
        [$sx1, $sy1, $sz1, $sx2, $sy2, $sz2] = self::sweptVolume($box, $container, $direction);
        foreach (array_keys($present) as $otherIndex) {
            if ($otherIndex === $index) { continue; }
            $other = $boxes[$otherIndex];
            if ($sx1 < $other->x2() && $other->origin->x < $sx2
                && $sy1 < $other->y2() && $other->origin->y < $sy2
                && $sz1 < $other->z2() && $other->origin->z < $sz2) {
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
