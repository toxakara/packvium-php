<?php
declare(strict_types=1);
namespace Packvium\Domain;

use InvalidArgumentException;
use Packvium\Support\BigInt;
use Packvium\Support\SignedBigInt;

/**
 * Exact separating-axis collision and volume for a `convex_hull` item.
 *
 * The rule is fixed by docs/IRREGULAR-ITEMS.md and reproduced here rather than translated
 * from the Python engine, so the golden fixtures compare two implementations.
 *
 * ## Why this file is not a straight port
 *
 * Python's integers are arbitrary-precision, which hides a real limit. A separating axis is
 * a cross product of two edge vectors, so its components grow as the square of a coordinate,
 * and a projection `v . d` grows as the cube. Measured on a 100 mm hull whose vertex
 * coordinates are coprime -- so the gcd reduction cannot shrink the axes -- the worst
 * projection is 2.5e19 against a 64-bit ceiling of 9.2e18. Ordinary shapes are nowhere near
 * it: a wedge's normals reduce to `(1,1,0)` and friends, leaving eleven orders of headroom.
 *
 * So each shape carries an a-priori bound on its own projections, and every pair is checked
 * against it before any arithmetic happens. Inside the bound the work is native integers;
 * outside it the same work is done in `SignedBigInt`. The answer is identical either way --
 * the fallback exists so that "the axes happened to reduce well" is never load-bearing.
 */
final class HullShape
{
    /** Smallest number of vertices that can enclose a volume. */
    public const MINIMUM_VERTICES = 4;

    /** Both the face normals and the edge directions of any axis-aligned box. */
    private const UNIT_AXES = [[0, 0, 1], [0, 1, 0], [1, 0, 0]];

    /**
     * Largest vertex coordinate a hull may carry, in ticks -- 6.25 m.
     *
     * A cross product of two vertex differences is bounded by `8 * C^2`, so below this bound
     * every axis this class computes is a native integer and only the *projections* onto
     * those axes can need exact arithmetic. Refusing beyond it keeps that one guarantee true
     * instead of spreading big-integer arithmetic through the whole file for a shape no
     * container could hold.
     */
    private const MAX_COORDINATE = 100000000;

    /**
     * @param list<array{int,int,int}> $vertices
     * @param list<array{int,int,int}> $faceAxes
     * @param list<array{int,int,int}> $edgeDirections
     */
    private function __construct(
        public readonly array $vertices,
        public readonly array $faceAxes,
        public readonly array $edgeDirections,
        public readonly string $volume,
        private readonly int $maxCoordinate,
        private readonly int $maxAxisComponent,
    ) {
    }

    /**
     * How many rotated hulls stay resident, before the memo is dropped and refilled.
     *
     * A request is bounded by its distinct hull items times the six orientations, so this
     * holds far more than any request the solver is sized for. Bounded rather than growing
     * for the life of the process: the point of the memo is to spend less, not to spend it
     * somewhere else.
     */
    private const SHAPE_CACHE_ENTRIES = 1024;

    /** @var array<string,self> */
    private static array $shapeCache = [];

    /**
     * The rotated hull of one item in one orientation, built at most once.
     *
     * A hull depends on the item and the orientation and on nothing about where a candidate
     * sits, but the collision predicate was rebuilding it on every call -- `O(v^4)` work
     * inside an `O(n^2)` loop. Measured on the two-wedge fixture: 78 builds for two items,
     * where twelve are the floor.
     *
     * Memoisation is safe here in the way it is not in general. A `HullShape` is immutable
     * once built, the key is the whole of what determines the value, and callers only ever
     * project through it. Determinism is untouched: this changes how often the answer is
     * computed, never what it is.
     *
     * @param list<array{int,int,int}> $vertices
     */
    public static function shapeFor(array $vertices, Rotation $rotation): self
    {
        $key = $rotation->value . '|' . implode(';', array_map(
            static fn (array $vertex): string => $vertex[0] . ',' . $vertex[1] . ',' . $vertex[2],
            $vertices,
        ));
        if (isset(self::$shapeCache[$key])) {
            return self::$shapeCache[$key];
        }
        $shape = self::of(self::rotate($vertices, $rotation));
        if (count(self::$shapeCache) >= self::SHAPE_CACHE_ENTRIES) {
            self::$shapeCache = [];
        }
        self::$shapeCache[$key] = $shape;
        return $shape;
    }

    /** @param list<array{int,int,int}> $vertices */
    public static function of(array $vertices): self
    {
        $points = self::validate($vertices);
        $faces = [];
        $count = count($points);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                for ($k = $j + 1; $k < $count; $k++) {
                    $axis = self::primitive(self::cross(
                        self::difference($points[$j], $points[$i]),
                        self::difference($points[$k], $points[$i]),
                    ));
                    // A triple whose plane cuts through the solid is not a face, and its
                    // normal separates nothing the real face normals do not.
                    if ($axis !== null && self::isSupporting($points, $points[$i], $axis)) {
                        $faces[self::key($axis)] = $axis;
                    }
                }
            }
        }
        $faceAxes = self::orderedAxes($faces);
        $wound = self::woundFaces($points, $faceAxes);
        $edgeDirections = self::edgeDirections($wound);
        return new self(
            $points,
            $faceAxes,
            $edgeDirections,
            self::volumeOf($wound),
            self::largestComponent($points),
            max(self::largestComponent($faceAxes), self::largestComponent($edgeDirections)),
        );
    }

    /**
     * A cuboid, built without searching for its own faces.
     *
     * A box's face normals and edge directions are both exactly the three unit axes, so the
     * supporting-plane search would only rediscover what is already known.
     */
    public static function box(int $length, int $width, int $height): self
    {
        $vertices = [];
        foreach ([0, 1] as $x) {
            foreach ([0, 1] as $y) {
                foreach ([0, 1] as $z) {
                    $vertices[] = [$x * $length, $y * $width, $z * $height];
                }
            }
        }
        return new self(
            $vertices,
            self::UNIT_AXES,
            self::UNIT_AXES,
            BigInt::multiply(BigInt::multiply((string)$length, (string)$width), (string)$height),
            max($length, $width, $height),
            1,
        );
    }

    /**
     * Reorient a hull the way `Dimensions::rotated` reorients its box, never mirroring it.
     *
     * Three of the six rotations are odd permutations of the coordinate axes. On a cuboid
     * that is invisible; on a hull a bare permutation returns the item's mirror image, a
     * shape the caller does not own. One axis therefore changes sign when the permutation is
     * odd, which makes all six proper rotations. Vertices come back translated so the
     * rotated hull's bounding box starts at the origin.
     *
     * @param list<array{int,int,int}> $vertices
     * @return list<array{int,int,int}>
     */
    public static function rotate(array $vertices, Rotation $rotation): array
    {
        $axes = match ($rotation) {
            Rotation::LWH => [0, 1, 2], Rotation::LHW => [0, 2, 1],
            Rotation::WLH => [1, 0, 2], Rotation::WHL => [1, 2, 0],
            Rotation::HLW => [2, 0, 1], Rotation::HWL => [2, 1, 0],
        };
        $inversions = 0;
        for ($i = 0; $i < 3; $i++) {
            for ($j = $i + 1; $j < 3; $j++) {
                if ($axes[$i] > $axes[$j]) {
                    $inversions++;
                }
            }
        }
        $sign = $inversions % 2 === 1 ? -1 : 1;
        $turned = [];
        foreach ($vertices as $vertex) {
            $turned[] = [$sign * $vertex[$axes[0]], $vertex[$axes[1]], $vertex[$axes[2]]];
        }
        $low = [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX];
        foreach ($turned as $vertex) {
            for ($axis = 0; $axis < 3; $axis++) {
                $low[$axis] = min($low[$axis], $vertex[$axis]);
            }
        }
        $out = [];
        foreach ($turned as $vertex) {
            $out[] = [$vertex[0] - $low[0], $vertex[1] - $low[1], $vertex[2] - $low[2]];
        }
        return $out;
    }

    /**
     * Canonicalise an authored vertex list or refuse a hull with no interior.
     *
     * A zero-volume hull is separated from everything on its own normal, so it would pass
     * through every other item and still be reported as a valid placement.
     *
     * @param list<array{int,int,int}> $vertices
     * @return list<array{int,int,int}>
     */
    public static function validate(array $vertices): array
    {
        $points = array_values($vertices);
        $count = count($points);
        if ($count < self::MINIMUM_VERTICES) {
            throw new InvalidArgumentException(
                'a convex hull needs at least ' . self::MINIMUM_VERTICES . " vertices, got {$count}"
            );
        }
        $seen = [];
        foreach ($points as $point) {
            $seen[self::key($point)] = true;
        }
        if (count($seen) !== $count) {
            throw new InvalidArgumentException('convex hull vertices must be unique');
        }
        if (self::largestComponent($points) > self::MAX_COORDINATE) {
            throw new InvalidArgumentException(
                'convex hull coordinates must stay within ' . self::MAX_COORDINATE . ' ticks'
            );
        }
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                for ($k = $j + 1; $k < $count; $k++) {
                    for ($l = $k + 1; $l < $count; $l++) {
                        // Exact: this product reaches `12 * C^3` and the only thing asked of
                        // it is whether it is zero, so a silently wrapped value would admit a
                        // flat hull -- one that passes through every other item.
                        $volume = self::exactDot(
                            self::difference($points[$l], $points[$i]),
                            self::cross(
                                self::difference($points[$j], $points[$i]),
                                self::difference($points[$k], $points[$i]),
                            ),
                        );
                        if ($volume !== '0') {
                            return $points;
                        }
                    }
                }
            }
        }
        throw new InvalidArgumentException('convex hull vertices are coplanar and enclose no volume');
    }

    /**
     * Do two placed hulls overlap with positive volume?
     *
     * Touching is contact, not collision: the comparison is `<=`, keeping hulls consistent
     * with the half-open convention cuboids already use, so a hull resting exactly on a box
     * is supported rather than colliding with it.
     *
     * @param array{int,int,int} $leftOrigin
     * @param array{int,int,int} $rightOrigin
     */
    public static function collide(
        self $left,
        array $leftOrigin,
        self $right,
        array $rightOrigin,
    ): bool {
        $reach = max($left->maxCoordinate, $right->maxCoordinate)
            + max(self::largestComponent([$leftOrigin]), self::largestComponent([$rightOrigin]));
        foreach (self::separatingAxes($left, $right) as $axis) {
            if (!self::axisFitsNatively($reach, $axis)) {
                if (self::separatedExactly($left, $leftOrigin, $right, $rightOrigin, $axis)) {
                    return false;
                }
                continue;
            }
            [$leftLow, $leftHigh] = $left->projection($axis);
            [$rightLow, $rightHigh] = $right->projection($axis);
            $leftShift = self::dot($leftOrigin, $axis);
            $rightShift = self::dot($rightOrigin, $axis);
            if ($leftHigh + $leftShift <= $rightLow + $rightShift
                || $rightHigh + $rightShift <= $leftLow + $leftShift) {
                return false;
            }
        }
        return true;
    }

    /**
     * Both hulls' face normals plus every edge-against-edge direction, deduplicated.
     *
     * @return list<array{int,int,int}>
     */
    public static function separatingAxes(self $left, self $right): array
    {
        $axes = [];
        foreach ([$left->faceAxes, $right->faceAxes] as $group) {
            foreach ($group as $axis) {
                $axes[self::key($axis)] = $axis;
            }
        }
        foreach ($left->edgeDirections as $leftEdge) {
            foreach ($right->edgeDirections as $rightEdge) {
                $axis = self::primitive(self::cross($leftEdge, $rightEdge));
                if ($axis !== null) {
                    $axes[self::key($axis)] = $axis;
                }
            }
        }
        ksort($axes);
        return array_values($axes);
    }

    /**
     * Closed projection interval on `$axis`, in this shape's own coordinates.
     *
     * @param array{int,int,int} $axis
     * @return array{int,int}
     */
    public function projection(array $axis): array
    {
        $low = null;
        $high = null;
        foreach ($this->vertices as $vertex) {
            $value = self::dot($vertex, $axis);
            $low = $low === null ? $value : min($low, $value);
            $high = $high === null ? $value : max($high, $value);
        }
        return [(int)$low, (int)$high];
    }

    /**
     * Inclusive lower and upper corners of a hull's axis-aligned envelope.
     *
     * @param list<array{int,int,int}> $vertices
     * @return array{array{int,int,int},array{int,int,int}}
     */
    public static function boundingExtent(array $vertices): array
    {
        $low = [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX];
        $high = [PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN];
        foreach ($vertices as $vertex) {
            for ($axis = 0; $axis < 3; $axis++) {
                $low[$axis] = min($low[$axis], $vertex[$axis]);
                $high[$axis] = max($high[$axis], $vertex[$axis]);
            }
        }
        return [$low, $high];
    }

    /**
     * Is every projection of this pair provably inside a 64-bit integer?
     *
     * `|v . d| <= 3 * maxCoordinate * maxAxisComponent`, with the placement origins folded
     * into the coordinate term. The check itself is done by division so that testing for
     * overflow cannot overflow.
     *
     * @param array{int,int,int} $leftOrigin
     * @param array{int,int,int} $rightOrigin
     */
    /**
     * Is every projection onto `$axis` provably inside a 64-bit integer?
     *
     * `|v . d| <= 3 * maxCoordinate * maxAxisComponent`, tested by division so that checking
     * for overflow cannot itself overflow. Per axis rather than per shape, because the gcd
     * reduction is what decides: a wedge's normals come out as `(1, 1, 0)` and stay native at
     * any size, while an axis that stays large needs the exact path even on a small hull.
     *
     * @param array{int,int,int} $axis
     */
    private static function axisFitsNatively(int $maxCoordinate, array $axis): bool
    {
        $largest = self::largestComponent([$axis]);
        if ($largest === 0) {
            return true;
        }
        if ($largest > intdiv(PHP_INT_MAX, 4)) {
            return false;
        }
        return $maxCoordinate <= intdiv(PHP_INT_MAX, 3 * $largest);
    }

    /**
     * The same separation test with every product carried as an exact decimal string.
     *
     * @param array{int,int,int} $leftOrigin
     * @param array{int,int,int} $rightOrigin
     * @param array{int,int,int} $axis
     */
    private static function separatedExactly(
        self $left,
        array $leftOrigin,
        self $right,
        array $rightOrigin,
        array $axis,
    ): bool {
        [$leftLow, $leftHigh] = $left->exactProjection($axis, $leftOrigin);
        [$rightLow, $rightHigh] = $right->exactProjection($axis, $rightOrigin);
        return SignedBigInt::compare($leftHigh, $rightLow) <= 0
            || SignedBigInt::compare($rightHigh, $leftLow) <= 0;
    }

    /**
     * @param array{int,int,int} $axis
     * @param array{int,int,int} $origin
     * @return array{string,string}
     */
    private function exactProjection(array $axis, array $origin): array
    {
        $low = null;
        $high = null;
        foreach ($this->vertices as $vertex) {
            $value = '0';
            for ($index = 0; $index < 3; $index++) {
                $value = SignedBigInt::add(
                    $value,
                    SignedBigInt::multiply((string)($vertex[$index] + $origin[$index]), (string)$axis[$index]),
                );
            }
            if ($low === null || SignedBigInt::compare($value, $low) < 0) {
                $low = $value;
            }
            if ($high === null || SignedBigInt::compare($value, $high) > 0) {
                $high = $value;
            }
        }
        return [(string)$low, (string)$high];
    }

    /**
     * Exact volume in cubic ticks, by the divergence theorem over the hull's own faces.
     *
     * `6V = sum over outward-oriented surface triangles of a . (b x c)`. Accumulated in
     * decimal strings because a one-metre cube is already 4.096e21 cubic ticks, the same
     * reason every other volume in this engine is a `BigInt`.
     *
     * @param list<array{int,int,int}> $vertices
     * @param list<array{int,int,int}> $faceAxes
     */
    /**
     * Every face of the hull, each as its own corners in outward cyclic order.
     *
     * One walk, because the faces answer two questions at once: the volume needs them wound
     * consistently, and the hull's edges are the consecutive corner pairs of the same walk.
     * Each canonical face axis stands for up to two opposite faces, so both the maximal and
     * the minimal supporting plane along it are collected; a plane carrying fewer than three
     * vertices is an edge or a corner of the hull, not a face, and carries no edge its two
     * adjoining faces do not already carry.
     *
     * @param list<array{int,int,int}> $vertices
     * @param list<array{int,int,int}> $faceAxes
     * @return list<list<array{int,int,int}>>
     */
    private static function woundFaces(array $vertices, array $faceAxes): array
    {
        $faces = [];
        foreach ($faceAxes as $axis) {
            foreach ([$axis, [-$axis[0], -$axis[1], -$axis[2]]] as $outward) {
                $native = self::axisFitsNatively(self::largestComponent($vertices), $outward);
                $extreme = null;
                $projections = [];
                foreach ($vertices as $index => $vertex) {
                    $value = $native ? (string)self::dot($vertex, $outward) : self::exactDot($vertex, $outward);
                    $projections[$index] = $value;
                    if ($extreme === null || SignedBigInt::compare($value, $extreme) > 0) {
                        $extreme = $value;
                    }
                }
                $face = [];
                foreach ($vertices as $index => $vertex) {
                    if ($projections[$index] === $extreme) {
                        $face[] = $vertex;
                    }
                }
                if (count($face) < 3) {
                    continue;
                }
                $faces[] = self::windFace($face, $outward);
            }
        }
        return $faces;
    }

    /**
     * Exact volume in cubic ticks, by the divergence theorem over the hull's own faces.
     *
     * `6V = sum over outward-oriented surface triangles of a . (b x c)`, an integer for
     * integer vertices and therefore exact -- no tolerance decides whether a wedge is half a
     * cube.
     *
     * @param list<list<array{int,int,int}>> $faces
     */
    private static function volumeOf(array $faces): string
    {
        $total = '0';
        foreach ($faces as $ordered) {
            $apex = $ordered[0];
            $size = count($ordered);
            for ($index = 1; $index + 1 < $size; $index++) {
                $total = SignedBigInt::add(
                    $total,
                    self::exactDot($apex, self::cross($ordered[$index], $ordered[$index + 1])),
                );
            }
        }
        $magnitude = ltrim($total, '-');
        return BigInt::divide($magnitude, 6);
    }

    /**
     * Directions of the hull's real edges, deduplicated and canonical.
     *
     * Every edge of a convex polyhedron is shared by exactly two faces, so walking each wound
     * face and taking its consecutive corner pairs -- closing the cycle -- reaches all of
     * them. The separating-axis theorem asks for exactly these, not for every vertex pair.
     *
     * The distinction is the whole cost of the predicate. A hull has at most `3v - 6` edges
     * but `v(v - 1) / 2` vertex pairs, and the axis set is the *product* of two hulls' sets,
     * so the gap squares: on a 20-vertex hull, 1351 candidate axes rather than 15616. Vertex
     * pairs were never wrong, only a superset -- a pair that is not an edge names a direction
     * no face can separate along, so it can add an axis but never remove one.
     *
     * @param list<list<array{int,int,int}>> $faces
     * @return list<array{int,int,int}>
     */
    private static function edgeDirections(array $faces): array
    {
        $edges = [];
        foreach ($faces as $ordered) {
            $size = count($ordered);
            for ($index = 0; $index < $size; $index++) {
                $axis = self::primitive(
                    self::difference($ordered[($index + 1) % $size], $ordered[$index]),
                );
                if ($axis !== null) {
                    $edges[self::key($axis)] = $axis;
                }
            }
        }
        return self::orderedAxes($edges);
    }

    /**
     * One planar convex face, wound counter-clockwise seen from outside.
     *
     * Decided with integer cross products rather than an angle: no `atan2`, nothing whose
     * result could depend on the platform's maths library.
     *
     * @param list<array{int,int,int}> $face
     * @param array{int,int,int} $outward
     * @return list<array{int,int,int}>
     */
    private static function windFace(array $face, array $outward): array
    {
        // The vertices sharing a supporting plane are not all corners of the polygon they lie
        // on: one can sit inside the face or part-way along an edge, and fanning over the raw
        // set triangulates the wrong region -- the surface then fails to close and the volume
        // is wrong. Gift-wrapping keeps only the corners. It also suits this engine's other
        // constraint: only the *sign* of a turn is needed, and `signAlong` reads it without
        // forming `offset . outward`, a product that reaches 10^24 on a hull whose axes did
        // not reduce.
        // Ordered by the vertex *vector*, never by its decimal encoding. The whole
        // correctness argument for the walk below is that it starts from a corner, and it
        // earns that by starting from the smallest vertex under a genuine linear order --
        // extreme in any such order, therefore a corner. String order is not one: "10,0,0"
        // sorts before "9,0,0", so it can name a vertex sitting inside the face or part-way
        // along one of its edges, and the walk then emits a segment that is not a hull edge.
        // Measured before this was fixed: nine of 54512 random faces, and the engine reported
        // 18 edge directions where Python reported 17 for the same hull.
        usort($face, static fn(array $l, array $r): int => [$l[0], $l[1], $l[2]] <=> [$r[0], $r[1], $r[2]]);
        $start = $face[0];
        $ordered = [$start];
        $current = $start;
        $count = count($face);
        for ($step = 0; $step < $count; $step++) {
            $following = null;
            foreach ($face as $candidate) {
                if ($candidate === $current) {
                    continue;
                }
                if ($following === null) {
                    $following = $candidate;
                    continue;
                }
                $turn = self::signAlong(
                    self::cross(
                        self::difference($following, $current),
                        self::difference($candidate, $current),
                    ),
                    $outward,
                );
                // Collinear with the current corner: take the farthest, so a vertex lying on
                // an edge is walked past rather than doubled back through.
                if ($turn < 0 || ($turn === 0
                    && self::squareLength(self::difference($candidate, $current))
                        > self::squareLength(self::difference($following, $current)))) {
                    $following = $candidate;
                }
            }
            if ($following === null || $following === $start) {
                break;
            }
            $ordered[] = $following;
            $current = $following;
        }
        return $ordered;
    }

    /** @param array{int,int,int} $vector */
    private static function squareLength(array $vector): int
    {
        return $vector[0] * $vector[0] + $vector[1] * $vector[1] + $vector[2] * $vector[2];
    }

    /**
     * The sign of `$parallel . $axis`, where `$parallel` is known to be parallel to `$axis`.
     *
     * Every vector this is asked about is a cross product of two in-plane vectors, so it lies
     * along the face normal by construction. That makes the dot product's sign equal to the
     * product of the signs of any one matching pair of non-zero components -- which is the
     * whole answer, obtained without multiplying two large numbers together.
     *
     * @param array{int,int,int} $parallel
     * @param array{int,int,int} $axis
     */
    private static function signAlong(array $parallel, array $axis): int
    {
        for ($index = 0; $index < 3; $index++) {
            if ($axis[$index] === 0 || $parallel[$index] === 0) {
                continue;
            }
            return ($parallel[$index] > 0) === ($axis[$index] > 0) ? 1 : -1;
        }
        return 0;
    }

    private static function exactDot(array $vertices, array $axis): string
    {
        $value = '0';
        for ($index = 0; $index < 3; $index++) {
            $value = SignedBigInt::add(
                $value,
                SignedBigInt::multiply((string)$vertices[$index], (string)$axis[$index]),
            );
        }
        return $value;
    }

    /** @param list<array{int,int,int}> $vectors */
    private static function largestComponent(array $vectors): int
    {
        $largest = 0;
        foreach ($vectors as $vector) {
            foreach ($vector as $component) {
                $largest = max($largest, abs($component));
            }
        }
        return $largest;
    }

    /**
     * @param list<array{int,int,int}> $vertices
     * @param array{int,int,int} $origin
     * @param array{int,int,int} $axis
     */
    private static function isSupporting(array $vertices, array $origin, array $axis): bool
    {
        $native = self::axisFitsNatively(self::largestComponent($vertices), $axis);
        $above = false;
        $below = false;
        $offset = $native ? (string)self::dot($origin, $axis) : self::exactDot($origin, $axis);
        foreach ($vertices as $vertex) {
            $value = $native ? (string)self::dot($vertex, $axis) : self::exactDot($vertex, $axis);
            $side = SignedBigInt::compare($value, $offset);
            if ($side > 0) {
                $above = true;
            } elseif ($side < 0) {
                $below = true;
            }
            if ($above && $below) {
                return false;
            }
        }
        return true;
    }

    /**
     * Divide out the gcd and fix the sign, so parallel axes collapse to one entry.
     *
     * Null for the zero vector: a cross product of two parallel directions names no axis,
     * which is an ordinary outcome here rather than an error.
     *
     * @param array{int,int,int} $axis
     * @return array{int,int,int}|null
     */
    private static function primitive(array $axis): ?array
    {
        $divisor = self::gcd(self::gcd(abs($axis[0]), abs($axis[1])), abs($axis[2]));
        if ($divisor === 0) {
            return null;
        }
        $reduced = [intdiv($axis[0], $divisor), intdiv($axis[1], $divisor), intdiv($axis[2], $divisor)];
        foreach ($reduced as $component) {
            if ($component > 0) {
                return $reduced;
            }
            if ($component < 0) {
                return [-$reduced[0], -$reduced[1], -$reduced[2]];
            }
        }
        return null;
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return $a;
    }

    /**
     * @param array{int,int,int} $left
     * @param array{int,int,int} $right
     * @return array{int,int,int}
     */
    private static function difference(array $left, array $right): array
    {
        return [$left[0] - $right[0], $left[1] - $right[1], $left[2] - $right[2]];
    }

    /**
     * @param array{int,int,int} $left
     * @param array{int,int,int} $right
     * @return array{int,int,int}
     */
    private static function cross(array $left, array $right): array
    {
        return [
            $left[1] * $right[2] - $left[2] * $right[1],
            $left[2] * $right[0] - $left[0] * $right[2],
            $left[0] * $right[1] - $left[1] * $right[0],
        ];
    }

    /**
     * @param array{int,int,int} $point
     * @param array{int,int,int} $axis
     */
    private static function dot(array $point, array $axis): int
    {
        return $point[0] * $axis[0] + $point[1] * $axis[1] + $point[2] * $axis[2];
    }

    /**
     * Deduplicated axes in the lexicographic order docs/IRREGULAR-ITEMS.md specifies.
     *
     * By the vector, not by `key()`. The keys exist to deduplicate -- an associative array is
     * how PHP spells a set -- and a decimal encoding is fine for identity and wrong for order:
     * `ksort` puts "14,11,-2" before "3,0,-2". Nothing observable depended on it, because the
     * predicate is an `and` over the whole set, but the four engines are supposed to agree
     * internally and not merely coincide in their answers.
     *
     * @param array<string,array{int,int,int}> $axes
     * @return list<array{int,int,int}>
     */
    private static function orderedAxes(array $axes): array
    {
        $ordered = array_values($axes);
        usort($ordered, static fn(array $l, array $r): int => [$l[0], $l[1], $l[2]] <=> [$r[0], $r[1], $r[2]]);
        return $ordered;
    }

    /** @param array{int,int,int} $vector */
    private static function key(array $vector): string
    {
        return $vector[0] . ',' . $vector[1] . ',' . $vector[2];
    }
}
