<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Algorithm\DeterministicRandom;
use Packvium\Algorithm\SpatialIndex;

/**
 * The spatial index is a broad-phase filter, not the source of truth.
 *
 * Every test here compares `SpatialIndex::query()` against a naive O(n) scan using the
 * *exact same* intersection predicate `CandidateFinder::find` uses. The one property
 * that must never break is that the index's result is a superset of the naive scan's —
 * an index that reports too many candidates only costs a few wasted exact checks
 * downstream, but one that misses a real collision would let two items overlap. The
 * Python suite runs the same shape of test in test_spatial_index.py.
 */
final class SpatialIndexTest extends TestCase
{
    /** @param array{0:int,1:int,2:int,3:int,4:int,5:int} $a @param array{0:int,1:int,2:int,3:int,4:int,5:int} $b */
    private static function exactIntersects(array $a, array $b): bool
    {
        [$ax1, $ay1, $az1, $ax2, $ay2, $az2] = $a;
        [$bx1, $by1, $bz1, $bx2, $by2, $bz2] = $b;
        return $ax1 < $bx2 && $bx1 < $ax2 && $ay1 < $by2 && $by1 < $ay2 && $az1 < $bz2 && $bz1 < $az2;
    }

    /** @param list<array{0:int,1:int,2:int,3:int,4:int,5:int}> $bounds @param array{0:int,1:int,2:int,3:int,4:int,5:int} $query @return list<int> */
    private static function naiveOverlaps(array $bounds, array $query): array
    {
        $out = [];
        foreach ($bounds as $index => $bound) {
            if (self::exactIntersects($bound, $query)) $out[] = $index;
        }
        return $out;
    }

    /** @return array{0:int,1:int,2:int,3:int,4:int,5:int} */
    private static function randomBound(DeterministicRandom $rng, int $span): array
    {
        $x1 = $rng->nextInt($span);
        $y1 = $rng->nextInt($span);
        $z1 = $rng->nextInt($span);
        $x2 = $x1 + 1 + $rng->nextInt(intdiv($span, 4) + 1);
        $y2 = $y1 + 1 + $rng->nextInt(intdiv($span, 4) + 1);
        $z2 = $z1 + 1 + $rng->nextInt(intdiv($span, 4) + 1);
        return [$x1, $y1, $z1, $x2, $y2, $z2];
    }

    public static function testAnEmptyIndexFindsNothing(): void
    {
        $index = new SpatialIndex(1000, 1000, 1000);
        self::assertSame([], $index->query(0, 0, 0, 100, 100, 100));
    }

    public static function testASingleBoxIsFoundByAnOverlappingQuery(): void
    {
        $index = new SpatialIndex(1000, 1000, 1000);
        $index->add(0, [0, 0, 0, 100, 100, 100]);
        self::assertTrue(in_array(0, $index->query(50, 50, 50, 150, 150, 150), true));
    }

    public static function testADisjointBoxFarAwayIsNotReturned(): void
    {
        $index = new SpatialIndex(1000, 1000, 1000);
        $index->add(0, [0, 0, 0, 10, 10, 10]);
        self::assertSame([], $index->query(900, 900, 900, 950, 950, 950));
    }

    public static function testEdgeTouchingBoxesAreStillCandidatesForTheExactCheck(): void
    {
        $index = new SpatialIndex(1000, 1000, 1000);
        $index->add(0, [0, 0, 0, 100, 100, 100]);
        $candidates = $index->query(100, 0, 0, 200, 100, 100);
        self::assertFalse(self::exactIntersects([0, 0, 0, 100, 100, 100], [100, 0, 0, 200, 100, 100]));
        $naive = self::naiveOverlaps([[0, 0, 0, 100, 100, 100]], [100, 0, 0, 200, 100, 100]);
        foreach ($naive as $expected) self::assertTrue(in_array($expected, $candidates, true));
    }

    public static function testTheIndexNeverMissesATrueCollision(): void
    {
        for ($seed = 0; $seed < 30; $seed++) {
            $rng = new DeterministicRandom($seed);
            $span = 2000;
            $count = 1 + $rng->nextInt(150);
            $bounds = [];
            for ($i = 0; $i < $count; $i++) $bounds[] = self::randomBound($rng, $span);
            $index = SpatialIndex::build($bounds, $span, $span, $span);

            for ($q = 0; $q < 20; $q++) {
                $query = self::randomBound($rng, $span);
                $expected = self::naiveOverlaps($bounds, $query);
                $found = $index->query(...$query);
                foreach ($expected as $index_) {
                    self::assertTrue(in_array($index_, $found, true), "seed {$seed}: missed {$index_}");
                }
            }
        }
    }

    public static function testIncrementalAddMatchesABulkBuild(): void
    {
        for ($seed = 0; $seed < 10; $seed++) {
            $rng = new DeterministicRandom($seed);
            $span = 1000;
            $count = 1 + $rng->nextInt(80);
            $bounds = [];
            for ($i = 0; $i < $count; $i++) $bounds[] = self::randomBound($rng, $span);

            $incremental = new SpatialIndex($span, $span, $span);
            foreach ($bounds as $position => $bound) $incremental->add($position, $bound);
            $bulk = SpatialIndex::build($bounds, $span, $span, $span);

            for ($q = 0; $q < 10; $q++) {
                $query = self::randomBound($rng, $span);
                $a = $incremental->query(...$query);
                $b = $bulk->query(...$query);
                sort($a);
                sort($b);
                self::assertSame($a, $b);
            }
        }
    }

    public static function testCopyIsIndependentOfTheOriginal(): void
    {
        $index = new SpatialIndex(1000, 1000, 1000);
        $index->add(0, [0, 0, 0, 100, 100, 100]);
        $clone = $index->copy();

        $clone->add(1, [500, 500, 500, 600, 600, 600]);
        $index->add(2, [10, 10, 10, 20, 20, 20]);

        self::assertTrue(in_array(1, $clone->query(500, 500, 500, 600, 600, 600), true));
        self::assertFalse(in_array(1, $index->query(500, 500, 500, 600, 600, 600), true));
        self::assertTrue(in_array(2, $index->query(10, 10, 10, 20, 20, 20), true));
        self::assertFalse(in_array(2, $clone->query(10, 10, 10, 20, 20, 20), true));
    }

    public static function testABoxSpanningManyCellsIsFoundFromAnyOfThem(): void
    {
        $index = new SpatialIndex(800, 800, 800, 8);
        $big = [0, 0, 0, 800, 100, 100];
        $index->add(0, $big);
        foreach ([0, 200, 400, 600, 790] as $x) {
            self::assertTrue(in_array(0, $index->query($x, 0, 0, $x + 5, 5, 5), true), (string)$x);
        }
    }
}
