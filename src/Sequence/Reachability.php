<?php
declare(strict_types=1);
namespace Packvium\Sequence;

/**
 * Whether placement `$index` could be reached and removed *right now*, given the
 * full scene as it stands, its contact-graph dependents (`ContactGraph`)
 * and the declared unloading route -- independent of whether a complete
 * order exists for the *whole* load (`UnloadingDependencyGraph::safeRemovalOrder`/
 * `safeRouteRemovalOrder` answer that separately, and can require moving other items
 * first). A snapshot query: "if the door opened right now, what could actually come
 * out first."
 *
 * A geometrically valid packing (passes placement/collision/support checks) can
 * still box an item in on every exposed side, or place it behind an earlier-route
 * stop's cargo -- `$reachable` is `false` for exactly those placements, and each
 * `$blockedBy*` set names which other placements are responsible, not just whether
 * one is.
 *
 * `$reachable` is true only when none of the three block it: nothing from
 * `$blockedBySupport` (still-present children resting on top) is present,
 * every allowed exit sweep is not simultaneously blocked (`$blockedByNeighbors` is
 * only populated when *every* allowed direction is blocked -- a single clear
 * direction is enough to leave), and nothing from `$blockedByRoute` (present
 * placements due at an earlier stop) remains.
 */
final class Reachability
{
    /**
     * @param array<int,true> $blockedBySupport
     * @param array<int,true> $blockedByNeighbors
     * @param array<int,true> $blockedByRoute
     */
    public function __construct(
        public readonly int $index,
        public readonly bool $reachable,
        public readonly array $blockedBySupport,
        public readonly array $blockedByNeighbors,
        public readonly array $blockedByRoute,
    ) {}

    /** @return array{index:int,reachable:bool,blocked_by_support:list<int>,blocked_by_neighbors:list<int>,blocked_by_route:list<int>} */
    public function toArray(): array
    {
        $sorted = static function (array $values): array {
            $indexes = array_map('intval', array_keys($values));
            sort($indexes);
            return $indexes;
        };
        return [
            'index' => $this->index,
            'reachable' => $this->reachable,
            'blocked_by_support' => $sorted($this->blockedBySupport),
            'blocked_by_neighbors' => $sorted($this->blockedByNeighbors),
            'blocked_by_route' => $sorted($this->blockedByRoute),
        ];
    }
}
