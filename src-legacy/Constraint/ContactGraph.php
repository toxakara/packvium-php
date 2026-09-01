<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Domain\AxisAlignedBox;

final class ContactEdge
{
    /**
     * @readonly
     * @var int
     */
    public $index;
    /**
     * @readonly
     * @var int
     */
    public $area;
    public function __construct(int $index, int $area)
    {
        $this->index = $index;
        $this->area = $area;
    }
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
    /**
     * @readonly
     * @var int
     */
    private $cell;
    /** @var array<string,list<array{0:int,1:AxisAlignedBox}>> */
    private $buckets = [];

    /** @param list<array{0:int,1:AxisAlignedBox}> $candidates */
    public function __construct(int $cell, array $candidates)
    {
        $this->cell = $cell;
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
    private $supporters;
    /** @var list<list<int>> */
    private $children;
    /** @var list<AxisAlignedBox> */
    private $boxes;
    /**
     * @var int
     */
    private $cell;
    /** @var array<int,list<array{0:int,1:AxisAlignedBox}>> */
    private $byTop;
    /** @var array<int,list<array{0:int,1:AxisAlignedBox}>> */
    private $byBottom;
    /** @var array<int,ContactLevelIndex> */
    private $topIndexes;
    /** @var array<int,ContactLevelIndex> */
    private $bottomIndexes;

    /**
     * `$cellHint` is an upper bound on the footprint of any box that may later be
     * appended with `withBox`.
     *
     * Without it the cell is sized from the boxes present now, and appending anything
     * wider has to fall back to a full rebuild -- which is correct but defeats the
     * point, because in a search the base is what is already placed and the candidate is
     * a *new* item that may well be the widest thing in the request. A caller that knows
     * the item set passes its widest footprint once and the delta path then always
     * applies. Too large a hint only makes each bucket coarser; too small a one is
     * impossible to get wrong, because the fallback covers it.
     *
     * @param list<AxisAlignedBox> $boxes
     */
    public function __construct(array $boxes, int $cellHint = 1)
    {
        $byTop = [];
        $byBottom = [];
        foreach ($boxes as $index => $box) {
            $byTop[$box->z2()][] = [$index, $box];
            $byBottom[$box->origin->z][] = [$index, $box];
        }
        $supporters = array_fill(0, count($boxes), []);
        $children = array_fill(0, count($boxes), []);
        // A single global cell size, not one derived per level from that level's own
        // candidates: a querying box can be any size in this scene, and
        // ContactLevelIndex is only correct when its cell is at least as large as
        // every box it will ever index or be queried with.
        $cell = max(1, $cellHint);
        foreach ($boxes as $box) {
            $cell = max($cell, $box->x2() - $box->origin->x, $box->y2() - $box->origin->y);
        }
        $indexes = [];
        foreach ($boxes as $index => $box) {
            $candidates = $byTop[$box->origin->z] ?? null;
            if ($candidates === null) {
                continue;
            }
            $level = ($indexes[$box->origin->z] = $indexes[$box->origin->z] ?? new ContactLevelIndex($cell, $candidates));
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
            usort($matches, static function (array $a, array $b): int {
                return $a[0] <=> $b[0];
            });
            foreach ($matches as [$otherIndex, $area]) {
                if ($area > 0) {
                    $supporters[$index][] = new ContactEdge($otherIndex, $area);
                    $children[$otherIndex][] = $index;
                }
            }
        }
        $this->supporters = $supporters;
        $this->children = $children;
        $this->boxes = $boxes;
        $this->cell = $cell;
        $this->byTop = $byTop;
        $this->byBottom = $byBottom;
        // Only the top-plane indexes are populated by the build above; the bottom-plane
        // ones are built on demand, because a from-scratch build never needs them and
        // paying for them here would slow the common path to speed up the incremental one.
        $this->topIndexes = $indexes;
        $this->bottomIndexes = [];
    }

    /**
     * This graph plus one more box, appended at the next index.
     *
     * Adding a box cannot create or destroy contact between two boxes that were already
     * here: contact is a pairwise geometric predicate over two boxes and nothing else.
     * That is the whole reason a delta is sound, and it is why this returns a graph
     * sharing the base's edge lists instead of recomputing them -- only the new box's
     * own two planes are queried.
     *
     * The result is required to be identical to `new ContactGraph([...$boxes, $box])`,
     * not merely equivalent: `LoadCalculator::topLoads` splits a conserved integer
     * across the supporter list and hands the rounding remainder to its last edge, so
     * edge *order* is contract, not presentation. The new box takes the highest index,
     * so appending it to an existing list keeps that list ascending.
     */
    public function withBox(AxisAlignedBox $box): self
    {
        $index = count($this->boxes);
        $footprint = max($box->x2() - $box->origin->x, $box->y2() - $box->origin->y);
        if ($footprint > $this->cell) {
            // ContactLevelIndex is only correct while its cell is at least as large as
            // every box indexed in or queried against it. A larger box could step over
            // cells in the middle of its own footprint and miss a real overlap, so this
            // is a correctness fallback, not an optimisation choice.
            $boxes = $this->boxes;
            $boxes[] = $box;
            return new ContactGraph($boxes, $footprint);
        }

        // Both queries run before the clone so that any level index they build lands on
        // this graph first and the clone inherits it -- the two planes the new box joins
        // are invalidated below, and they are not the two it was queried against.
        $below = $this->matches($this->byTop, $this->topIndexes, $box->origin->z, $box);
        $above = $this->matches($this->byBottom, $this->bottomIndexes, $box->z2(), $box);

        $graph = clone $this;
        $graph->boxes[] = $box;

        // What the new box rests on: boxes whose top plane is its bottom plane.
        $ownSupporters = [];
        foreach ($below as [$otherIndex, $area]) {
            $ownSupporters[] = new ContactEdge($otherIndex, $area);
            $graph->children[$otherIndex][] = $index;
        }

        // What now rests on it: boxes whose bottom plane is its top plane. Their
        // supporter lists gain the new index, which is larger than every index already
        // in them, so ascending order is preserved by appending.
        $ownChildren = [];
        foreach ($above as [$otherIndex, $area]) {
            $ownChildren[] = $otherIndex;
            $graph->supporters[$otherIndex][] = new ContactEdge($index, $area);
        }

        $graph->supporters[$index] = $ownSupporters;
        $graph->children[$index] = $ownChildren;

        // The by-plane buckets are carried forward rather than rederived: one box joins
        // exactly two planes, so rewriting those two buckets is all that changed.
        $graph->byTop[$box->z2()][] = [$index, $box];
        $graph->byBottom[$box->origin->z][] = [$index, $box];
        // A ContactLevelIndex is immutable once built, so every cached one may be shared
        // with the base -- except on the two planes whose bucket just gained a member,
        // where the cached index no longer describes its bucket.
        unset($graph->topIndexes[$box->z2()], $graph->bottomIndexes[$box->origin->z]);
        return $graph;
    }

    /**
     * Every box on `$plane` overlapping `$box` in XY, in ascending index order.
     *
     * @param array<int,list<array{0:int,1:AxisAlignedBox}>> $buckets
     * @param array<int,ContactLevelIndex> $cache
     * @return list<array{0:int,1:int}>
     */
    private function matches(array $buckets, array &$cache, int $plane, AxisAlignedBox $box): array
    {
        if (!isset($buckets[$plane])) {
            return [];
        }
        $level = ($cache[$plane] = $cache[$plane] ?? new ContactLevelIndex($this->cell, $buckets[$plane]));
        $matches = [];
        foreach ($level->near($box) as [$otherIndex, $other]) {
            $area = $other->overlapAreaXY($box);
            if ($area > 0) {
                $matches[] = [$otherIndex, $area];
            }
        }
        usort($matches, static function (array $a, array $b): int {
            return $a[0] <=> $b[0];
        });
        return $matches;
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
