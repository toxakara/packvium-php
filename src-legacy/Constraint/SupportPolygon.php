<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Domain\AxisAlignedBox;
use Packvium\Support\ExactInteger as Exact;

final class Point2D
{
    /**
     * @readonly
     * @var int|string
     */
    public $x;
    /**
     * @readonly
     * @var int|string
     */
    public $y;
    /** Doubled ticks: native ints while they fit, exact decimal strings beyond.
     * @param int|string $x
     * @param int|string $y */
    public function __construct($x, $y)
    {
        $this->x = $x;
        $this->y = $y;
    }
    public function equals(Point2D $other): bool { return $this->x === $other->x && $this->y === $other->y; }
    public function compare(Point2D $other): int
    {
        return Exact::compare($this->x, $other->x) ?: Exact::compare($this->y, $other->y);
    }
}

/**
 * Convex hull and tipping geometry.
 *
 * Overlap area is not the same as stability: an item can be supported over enough
 * area and still overhang its own centre of gravity. Every function here is exact
 * integer arithmetic, no trig or division, and stays exact past a native int: two doubled
 * coordinates near `PHP_INT_MAX` ticks multiply to what Python and Rust's `i128` still hold.
 */
final class SupportPolygon
{
    /** The sign of the cross product (a - o) x (b - o). */
    private static function turn(Point2D $o, Point2D $a, Point2D $b): int
    {
        return Exact::compare(
            Exact::mul(Exact::sub($a->x, $o->x), Exact::sub($b->y, $o->y)),
            Exact::mul(Exact::sub($a->y, $o->y), Exact::sub($b->x, $o->x)),
        );
    }

    /**
     * Andrew's monotone chain. Returns the hull counter-clockwise, duplicates
     * collapsed. Degenerate input (0, 1, or all-collinear points) returns that same
     * degenerate shape rather than raising -- a single contact point or a razor-thin
     * support strip is a real, if precarious, physical case, not an error.
     *
     * @param list<Point2D> $points @return list<Point2D>
     */
    public static function convexHull(array $points): array
    {
        $unique = [];
        foreach ($points as $p) { $unique["{$p->x},{$p->y}"] = $p; }
        $pts = array_values($unique);
        usort($pts, static function (Point2D $a, Point2D $b): int {
            return $a->compare($b);
        });
        if (count($pts) <= 2) { return $pts; }

        $lower = [];
        foreach ($pts as $p) {
            while (count($lower) >= 2 && self::turn($lower[count($lower) - 2], $lower[count($lower) - 1], $p) <= 0) {
                array_pop($lower);
            }
            $lower[] = $p;
        }
        $upper = [];
        foreach (array_reverse($pts) as $p) {
            while (count($upper) >= 2 && self::turn($upper[count($upper) - 2], $upper[count($upper) - 1], $p) <= 0) {
                array_pop($upper);
            }
            $upper[] = $p;
        }
        array_pop($lower); array_pop($upper);
        $hull = array_merge($lower, $upper);
        return $hull !== [] ? $hull : $pts;
    }

    private static function onSegment(Point2D $p, Point2D $a, Point2D $b): bool
    {
        if (self::turn($a, $b, $p) !== 0) { return false; }
        return self::between($p->x, $a->x, $b->x) && self::between($p->y, $a->y, $b->y);
    }

    /**
     * @param int|string $value
     * @param int|string $end
     * @param int|string $other
     */
    private static function between($value, $end, $other): bool
    {
        [$low, $high] = Exact::compare($end, $other) <= 0 ? [$end, $other] : [$other, $end];
        return Exact::compare($low, $value) <= 0 && Exact::compare($value, $high) <= 0;
    }

    /** Whether `$point` lies inside or on the boundary of a hull from `convexHull`. @param list<Point2D> $hull */
    public static function pointInHull(Point2D $point, array $hull): bool
    {
        $n = count($hull);
        if ($n === 0) { return false; }
        if ($n === 1) { return $point->equals($hull[0]); }
        if ($n === 2) { return self::onSegment($point, $hull[0], $hull[1]); }
        for ($i = 0; $i < $n; $i++) {
            if (self::turn($hull[$i], $hull[($i + 1) % $n], $point) < 0) { return false; }
        }
        return true;
    }

    /**
     * Doubled-tick corners of every candidate/supporter overlap rectangle.
     *
     * Doubled so a centroid computed as `x1 + x2` (see `doubledCentroid`) is always
     * an exact integer even when a box's length or width is odd -- the usual trick
     * for keeping a midpoint computation free of fractions.
     *
     * @param list<AxisAlignedBox> $supporters @return list<Point2D>
     */
    public static function contactHullPoints(AxisAlignedBox $candidate, array $supporters): array
    {
        $cx1 = $candidate->origin->x; $cy1 = $candidate->origin->y;
        $cx2 = $candidate->x2(); $cy2 = $candidate->y2();
        $points = [];
        foreach ($supporters as $box) {
            $ox1 = max($cx1, $box->origin->x); $oy1 = max($cy1, $box->origin->y);
            $ox2 = min($cx2, $box->x2()); $oy2 = min($cy2, $box->y2());
            if ($ox2 > $ox1 && $oy2 > $oy1) {
                foreach ([$ox1, $ox2] as $x) {
                    foreach ([$oy1, $oy2] as $y) { $points[] = new Point2D(Exact::mul(2, $x), Exact::mul(2, $y)); }
                }
            }
        }
        return $points;
    }

    /** Twice the box's own footprint centroid, exact for odd as well as even extents. */
    public static function doubledCentroid(AxisAlignedBox $box): Point2D
    {
        return new Point2D(Exact::add($box->origin->x, $box->x2()), Exact::add($box->origin->y, $box->y2()));
    }
}
