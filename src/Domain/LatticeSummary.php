<?php
declare(strict_types=1);
namespace Packvium\Domain;

use InvalidArgumentException;
use Packvium\Support\BigInt;

/**
 * Compact, O(1)-to-build description of one GridSolver regular-lattice run.
 *
 * GridSolver already computes every field here in O(r) (at most six rotations)
 * before it ever places a single item -- rotation, physical/envelope dimensions,
 * per-axis capacity, layer step, clearance, and how many instances actually fit.
 * Building the summary costs nothing beyond what the solver already pays; what it
 * *replaces* is the O(n) loop that used to construct one Placement domain object per
 * item purely to fill in per-item coordinates.
 *
 * expand() reconstructs the exact Placement list that loop would have built -- same
 * order, same coordinates, same rotation -- so opting into the compact form never
 * loses information, only defers materializing it until a caller actually asks for
 * per-item coordinates.
 *
 * Restricted to the case Item::$nestingHeight is unset: a nested column's used
 * volume and overlap bookkeeping (Nesting.php) depends on which adjacent pair of
 * layers actually touch, which is exact but not worth the closed-form derivation
 * risk for a still-uncommon feature. GridSolver only builds a LatticeSummary when
 * the prototype item has no nestingHeight; nesting keeps using the O(n)
 * materializing loop unchanged, regardless of the config flag.
 */
final readonly class LatticeSummary
{
    public function __construct(
        public string $itemType,
        public Rotation $rotation,
        public Dimensions $physical,
        public Dimensions $envelope,
        public int $nx,
        public int $ny,
        public int $layerStep,
        public int $clearanceTicks,
        public int $count,
        public int $weightTicks,
    ) {
        if ($count <= 0) { throw new InvalidArgumentException('a lattice summary must describe at least one placed instance'); }
        if ($nx <= 0 || $ny <= 0) { throw new InvalidArgumentException('a lattice summary requires positive per-axis capacity'); }
    }

    private function perLayer(): int { return $this->nx * $this->ny; }
    public function fullLayers(): int { return intdiv($this->count, $this->perLayer()); }
    public function remainder(): int { return $this->count % $this->perLayer(); }
    public function layersUsed(): int { return $this->fullLayers() + ($this->remainder() > 0 ? 1 : 0); }

    /** Plain `int`, matching `Weight::$ticks` and the existing per-placement summation this replaces. */
    public function totalWeightTicks(): int { return $this->count * $this->weightTicks; }

    /**
     * Physical volume occupied, as a decimal string (numeric contract). No
     * nesting overlap term: GridSolver only ever builds a summary when the
     * prototype has no nestingHeight (see class docstring), so this reduces to a
     * plain per-item volume sum.
     */
    public function usedVolumeString(): string
    {
        return BigInt::multiply((string)$this->count, $this->physical->volumeString());
    }

    /** Highest envelope z2, matching the topmost placed item's envelope box in the original per-item loop. */
    public function maxZTicks(): int
    {
        $topLayerIndex = $this->layersUsed() - 1;
        return $topLayerIndex * $this->layerStep + $this->envelope->height->ticks;
    }

    private function triangular(int $n): int { return intdiv($n * ($n - 1), 2); }

    /**
     * Closed-form equivalent of CentreOfMass::offsetPpm for a uniform lattice of
     * identical items, without expanding a single Placement.
     *
     * Every instance shares the same weight and physical dimensions in a lattice,
     * so the per-item formula reduces to
     * `weight * (2*envelopeStep*sum(index) + count*(2*clearance + dimension))`,
     * where sum(index) is the sum of the x (or y) grid index across every placed
     * item -- a closed form over full layers plus one partial layer, since the
     * grid fills x fastest, then y, then z (see GridSolver).
     */
    public function centreOfMassOffsetPpm(int $innerLengthTicks, int $innerWidthTicks): int
    {
        if ($this->weightTicks === 0 || $this->count === 0) { return 0; }
        $nx = $this->nx; $ny = $this->ny;
        $fullLayers = $this->fullLayers();
        $remainder = $this->remainder();
        $rowsInPartial = intdiv($remainder, $nx);
        $extraCols = $remainder % $nx;

        $sumX = $fullLayers * $ny * $this->triangular($nx) + $rowsInPartial * $this->triangular($nx) + $this->triangular($extraCols);
        $sumY = $fullLayers * $nx * $this->triangular($ny) + $nx * $this->triangular($rowsInPartial) + $extraCols * $rowsInPartial;

        $clearance = $this->clearanceTicks;
        $weight = (string)$this->weightTicks;
        $doubledWeightedX = BigInt::multiply($weight, (string)(2 * $this->envelope->length->ticks * $sumX + $this->count * (2 * $clearance + $this->physical->length->ticks)));
        $doubledWeightedY = BigInt::multiply($weight, (string)(2 * $this->envelope->width->ticks * $sumY + $this->count * (2 * $clearance + $this->physical->width->ticks)));
        $totalWeight = (string)$this->totalWeightTicks();

        $offsetXPpm = $this->axisOffsetPpm($doubledWeightedX, $totalWeight, (string)$innerLengthTicks);
        $offsetYPpm = $this->axisOffsetPpm($doubledWeightedY, $totalWeight, (string)$innerWidthTicks);
        return max($offsetXPpm, $offsetYPpm);
    }

    private function axisOffsetPpm(string $doubledWeighted, string $totalWeight, string $extent): int
    {
        $reference = BigInt::multiply($totalWeight, $extent);
        $absNumerator = BigInt::compare($doubledWeighted, $reference) >= 0
            ? BigInt::subtract($doubledWeighted, $reference)
            : BigInt::subtract($reference, $doubledWeighted);
        return (int)BigInt::divide(BigInt::multiply($absNumerator, '1000000'), $reference);
    }

    /**
     * Rebuild the exact Placement list the original per-item loop would have
     * produced, in the same order, from $items[0:count].
     *
     * @param list<ItemInstance> $items
     * @return list<Placement>
     */
    public function expand(array $items): array
    {
        $nx = $this->nx; $ny = $this->ny; $layerStep = $this->layerStep; $clearance = $this->clearanceTicks;
        $out = [];
        for ($index = 0; $index < $this->count; $index++) {
            $x = $index % $nx; $y = intdiv($index, $nx) % $ny; $z = intdiv($index, $nx * $ny);
            $point = new Point($x * $this->envelope->length->ticks, $y * $this->envelope->width->ticks, $z * $layerStep);
            $position = new Point($point->x + $clearance, $point->y + $clearance, $point->z + $clearance);
            $out[] = new Placement($items[$index], $position, $this->rotation, $this->physical, $point, $this->envelope, 1.0);
        }
        return $out;
    }
}
