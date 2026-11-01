<?php
declare(strict_types=1);
namespace Packvium\Support;

use Packvium\Domain\AxisAlignedBox;
use Packvium\Domain\Dimensions;

/**
 * The products of two lengths the engine forms, exact past a native int.
 *
 * Every length below `PHP_INT_MAX` ticks is a valid request, and two of them multiply to a
 * value Python and Rust (`i128`) still hold exactly. The public `Dimensions::baseAreaTicks`
 * and `AxisAlignedBox::overlapAreaXY` keep their `int` contract; the search reads these.
 * Each keeps the native product whenever it is one, so an ordinary request pays one
 * `is_int` check.
 */
final class ExactGeometry
{
    public static function baseArea(Dimensions $dimensions): int|string
    {
        $area = $dimensions->length->ticks * $dimensions->width->ticks;
        return is_int($area) ? $area : ExactInteger::mul($dimensions->length->ticks, $dimensions->width->ticks);
    }

    public static function overlapAreaXY(AxisAlignedBox $a, AxisAlignedBox $b): int|string
    {
        $x = max(0, min($a->x2(), $b->x2()) - max($a->origin->x, $b->origin->x));
        $y = max(0, min($a->y2(), $b->y2()) - max($a->origin->y, $b->origin->y));
        $area = $x * $y;
        return is_int($area) ? $area : ExactInteger::mul($x, $y);
    }

    /** `2 * origin + extent`, the doubled centre of one side, as a decimal string. */
    public static function doubledCentre(int $origin, int $extent): string
    {
        return (string)ExactInteger::add(ExactInteger::mul(2, $origin), $extent);
    }
}
