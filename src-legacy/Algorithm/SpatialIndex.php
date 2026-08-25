<?php
declare(strict_types=1);
namespace Packvium\Algorithm;

/**
 * Uniform-grid broad-phase index for axis-aligned box collision queries.
 *
 * `CandidateFinder::find`'s inner loop tests a candidate box against every already-placed
 * box in the container — `docs/ALGORITHMS-AND-COMPLEXITY.md` already flagged this as
 * `O(n*p*r*m)` and named "replace pairwise checks with a spatial index" as the seam.
 * This buckets boxes into a uniform grid so a query only has to exact-check the boxes
 * sharing at least one cell with it, not every box ever placed.
 *
 * Safety property the whole design leans on: a grid cell assignment only needs to be a
 * superset of "boxes this query could possibly intersect" — `query()` returns bound
 * *indices*, and the caller (unchanged from before this class existed) still runs the
 * exact 6-comparison AABB test on each one before trusting it. A bug here can only make
 * the index slower (over-inclusive buckets) or, if under-inclusive, is caught immediately
 * by the differential property tests in SpatialIndexTest.php, which compare every query
 * against a naive O(n) scan across many random configurations. It can never by itself
 * cause a false "no collision" silently, because the exact check is still the final word.
 */
final class SpatialIndex
{
    /**
     * @readonly
     * @var int
     */
    private $cellX;
    /**
     * @readonly
     * @var int
     */
    private $cellY;
    /**
     * @readonly
     * @var int
     */
    private $cellZ;
    /** @var array<string,list<int>> */
    private $cells = [];

    public function __construct(int $lengthTicks, int $widthTicks, int $heightTicks, int $cellsPerAxis = 8)
    {
        $this->cellX = max(1, self::ceilDiv(max(1, $lengthTicks), $cellsPerAxis));
        $this->cellY = max(1, self::ceilDiv(max(1, $widthTicks), $cellsPerAxis));
        $this->cellZ = max(1, self::ceilDiv(max(1, $heightTicks), $cellsPerAxis));
    }

    /**
     * A structural fork: independent of the original from this point on.
     *
     * PHP arrays are value types, so the default `clone` already deep-copies `$cells`
     * correctly; this method exists so `ContainerState::__clone()` has one explicit call
     * to make, matching the copy discipline every other mutable property there follows.
     */
    public function copy(): self
    {
        return clone $this;
    }

    private static function ceilDiv(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }

    /** @return array{0:int,1:int,2:int,3:int,4:int,5:int} [ix1,ix2,iy1,iy2,iz1,iz2) */
    private function cellRange(int $x1, int $y1, int $z1, int $x2, int $y2, int $z2): array
    {
        return [
            intdiv($x1, $this->cellX), self::ceilDiv(max($x2, $x1 + 1), $this->cellX),
            intdiv($y1, $this->cellY), self::ceilDiv(max($y2, $y1 + 1), $this->cellY),
            intdiv($z1, $this->cellZ), self::ceilDiv(max($z2, $z1 + 1), $this->cellZ),
        ];
    }

    /** @param array{0:int,1:int,2:int,3:int,4:int,5:int} $bound */
    public function add(int $index, array $bound): void
    {
        [$x1, $y1, $z1, $x2, $y2, $z2] = $bound;
        [$ix1, $ix2, $iy1, $iy2, $iz1, $iz2] = $this->cellRange($x1, $y1, $z1, $x2, $y2, $z2);
        for ($ix = $ix1; $ix < $ix2; $ix++) {
            for ($iy = $iy1; $iy < $iy2; $iy++) {
                for ($iz = $iz1; $iz < $iz2; $iz++) {
                    $this->cells["{$ix}:{$iy}:{$iz}"][] = $index;
                }
            }
        }
    }

    /** Bound indices sharing at least one cell with the given box, each at most once. @return list<int> */
    public function query(int $x1, int $y1, int $z1, int $x2, int $y2, int $z2): array
    {
        [$ix1, $ix2, $iy1, $iy2, $iz1, $iz2] = $this->cellRange($x1, $y1, $z1, $x2, $y2, $z2);
        $seen = [];
        $out = [];
        for ($ix = $ix1; $ix < $ix2; $ix++) {
            for ($iy = $iy1; $iy < $iy2; $iy++) {
                for ($iz = $iz1; $iz < $iz2; $iz++) {
                    foreach ($this->cells["{$ix}:{$iy}:{$iz}"] ?? [] as $index) {
                        if (!isset($seen[$index])) {
                            $seen[$index] = true;
                            $out[] = $index;
                        }
                    }
                }
            }
        }
        return $out;
    }

    /** @param list<array{0:int,1:int,2:int,3:int,4:int,5:int}> $bounds */
    public static function build(array $bounds, int $lengthTicks, int $widthTicks, int $heightTicks): self
    {
        $index = new self($lengthTicks, $widthTicks, $heightTicks);
        foreach ($bounds as $position => $bound) {
            $index->add($position, $bound);
        }
        return $index;
    }
}
