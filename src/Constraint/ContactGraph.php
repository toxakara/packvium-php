<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Domain\AxisAlignedBox;

final readonly class ContactEdge
{
    public function __construct(public int $index, public int $area) {}
}

/**
 * A uniform spatial hash over one z-level's boxes (those sharing one `byTop` or
 * `origin->z` bucket), bucketed by XY cell so a query only scans the boxes actually
 * near it instead of every other box at that level.
 *
 * A regular lattice (`GridSolver`) can put thousands of items on one shared level, and
 * the plain "for each box, scan every other box at this level" this replaces was
 * exactly quadratic in that case -- fine for the few-dozen-item scenes this module was
 * written against, but O(level_size^2) is what actually made a 10,000-item request take
 * longer than its own configured time limit.
 *
 * `$cell` must be at least as large as the largest footprint dimension of *every* box
 * that will ever be inserted into or queried against this index -- not just the
 * candidates being indexed. Only then is a box guaranteed to span no more than a 2x2
 * block of cells, which is what guarantees two overlapping boxes always share at least
 * one cell. Sizing `$cell` from the indexed candidates alone would be exactly wrong: a
 * querying box larger than that could skip cells in the middle of its own footprint and
 * silently miss a real overlap.
 */
final class ContactLevelIndex
{
    /** @var array<string,list<array{0:int,1:AxisAlignedBox}>> */
    private array $buckets = [];

    /** @param list<array{0:int,1:AxisAlignedBox}> $candidates */
    public function __construct(private readonly int $cell, array $candidates)
    {
        foreach ($candidates as $entry) {
            foreach ($this->cells($entry[1]) as $key) {
                $this->buckets[$key][] = $entry;
            }
        }
    }

    /** @return list<string> */
    private function cells(AxisAlignedBox $box): array
    {
        $cell = $this->cell;
        $x1 = intdiv($box->origin->x, $cell);
        $y1 = intdiv($box->origin->y, $cell);
        $x2 = intdiv($box->x2() - 1, $cell);
        $y2 = intdiv($box->y2() - 1, $cell);
        $keys = ["{$x1}:{$y1}", "{$x2}:{$y1}", "{$x1}:{$y2}", "{$x2}:{$y2}"];
        return array_values(array_unique($keys));
    }

    /**
     * Every candidate that could possibly overlap `$box` in XY -- a superset of the
     * true overlaps, not an exact answer; the caller still checks `overlapAreaXY`
     * itself. May yield the same candidate more than once when its footprint spans
     * more than one of `$box`'s cells, so this dedupes by index before returning.
     *
     * @return list<array{0:int,1:AxisAlignedBox}>
     */
    public function near(AxisAlignedBox $box): array
    {
        $seen = [];
        $out = [];
        foreach ($this->cells($box) as $key) {
            foreach ($this->buckets[$key] ?? [] as $entry) {
                if (!isset($seen[$entry[0]])) {
                    $seen[$entry[0]] = true;
                    $out[] = $entry;
                }
            }
        }
        return $out;
    }
}

/**
 * Direct top/bottom contact between boxes, derived from geometry alone.
 *
 * Built once per whole-container check and shared by every load-propagation
 * computation that used to each re-derive their own by-plane index or nested
 * pairwise scan (`topLoads`, `stackedCounts`) -- the two disagreeing on
 * what "touches" means was a standing risk neither test caught, because nothing
 * forced them to share the definition.
 */
final class ContactGraph
{
    /** @var list<list<ContactEdge>> */
    private array $supporters;
    /** @var list<list<int>> */
    private array $children;

    /** @param list<AxisAlignedBox> $boxes */
    public function __construct(array $boxes)
    {
        $byTop = [];
        foreach ($boxes as $index => $box) {
            $byTop[$box->z2()][] = [$index, $box];
        }
        $supporters = array_fill(0, count($boxes), []);
        $children = array_fill(0, count($boxes), []);
        // A single global cell size, not one derived per level from that level's own
        // candidates: a querying box can be any size in this scene, and
        // ContactLevelIndex is only correct when its cell is at least as large as
        // every box it will ever index or be queried with.
        $cell = 1;
        foreach ($boxes as $box) {
            $cell = max($cell, $box->x2() - $box->origin->x, $box->y2() - $box->origin->y);
        }
        $indexes = [];
        foreach ($boxes as $index => $box) {
            $candidates = $byTop[$box->origin->z] ?? null;
            if ($candidates === null) {
                continue;
            }
            $level = $indexes[$box->origin->z] ??= new ContactLevelIndex($cell, $candidates);
            // `topLoads` (LoadCalculator) splits a conserved integer total across
            // `supporters($index)` and hands the rounding remainder to whichever edge
            // is *last* in that list -- so this must land in the same ascending
            // otherIndex order the original all-pairs scan produced (it visited
            // `byTop`'s bucket, itself built by a single increasing-index pass), not
            // whatever order the spatial hash's cells happen to iterate in.
            $matches = [];
            foreach ($level->near($box) as [$otherIndex, $other]) {
                if ($otherIndex === $index) {
                    continue;
                }
                $matches[] = [$otherIndex, $other->overlapAreaXY($box)];
            }
            usort($matches, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            foreach ($matches as [$otherIndex, $area]) {
                if ($area > 0) {
                    $supporters[$index][] = new ContactEdge($otherIndex, $area);
                    $children[$otherIndex][] = $index;
                }
            }
        }
        $this->supporters = $supporters;
        $this->children = $children;
    }

    /** What `$index` directly rests on, each with the contact area. @return list<ContactEdge> */
    public function supporters(int $index): array
    {
        return $this->supporters[$index];
    }

    /** What rests directly on `$index` (not transitively). @return list<int> */
    public function children(int $index): array
    {
        return $this->children[$index];
    }
}
