<?php
declare(strict_types=1);
namespace Packvium\Domain;

use InvalidArgumentException;

/**
 * The region between a box's own face and a container wall, and the test for whether
 * anything stands in it.
 *
 * It lives in `Domain` rather than beside either caller because both the constraint layer
 * and the sequence layer need it, and `Packvium\Sequence` already depends on
 * `Packvium\Constraint` -- putting it there would invert the one-way dependency direction
 * the architecture fixes. `SequenceGeometry` delegates here rather than keeping a second
 * copy: two implementations of the same corridor arithmetic is how two engines start
 * disagreeing about which loads are legal.
 *
 * `direction` names *which wall's region* a box occupies, not a direction of travel. The
 * region between a box and its `+x` wall is the same region whether the box is leaving
 * through it or arriving through it, which is why loading and unloading share one
 * primitive.
 */
final class SweptRegion
{
    /**
     * The six axis-aligned faces a box can leave a container through, in a fixed order.
     *
     * Fixed because callers iterate it to pick the *first* clear direction, and an
     * unordered set would make which one they pick depend on hash order.
     */
    public const ALL_DIRECTIONS = ['+x', '-x', '+y', '-y', '+z', '-z'];

    /**
     * The swept region as `[x1, y1, z1, x2, y2, z2]` in ticks.
     *
     * Throws `InvalidArgumentException` rather than a sequence-specific error because
     * `Domain` sits below `Sequence` and must not reach up to it; callers that owe their
     * own error type validate before calling.
     */
    public static function volume(AxisAlignedBox $box, Dimensions $container, string $direction): array
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
            default: throw new InvalidArgumentException("unknown movement direction {$direction}");
        }
        return [$x1, $y1, $z1, $x2, $y2, $z2];
    }

    /**
     * Does `$box` stand anywhere inside a swept region?
     *
     * Half-open on every axis, matching `AxisAlignedBox::intersects`, so a box flush
     * against another's exit face is not treated as standing in its way.
     *
     * @param array{0:int,1:int,2:int,3:int,4:int,5:int} $sweep
     */
    public static function intersects(array $sweep, AxisAlignedBox $box): bool
    {
        [$sx1, $sy1, $sz1, $sx2, $sy2, $sz2] = $sweep;
        return $sx1 < $box->x2() && $box->origin->x < $sx2
            && $sy1 < $box->y2() && $box->origin->y < $sy2
            && $sz1 < $box->z2() && $box->origin->z < $sz2;
    }
}
