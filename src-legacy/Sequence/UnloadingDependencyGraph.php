<?php
declare(strict_types=1);
namespace Packvium\Sequence;

use Packvium\Constraint\ContactGraph;
use Packvium\Domain\AxisAlignedBox;
use Packvium\Domain\Dimensions;

/**
 * Unloading dependency graph: which placements must be removed before
 * others, replaying from the full, final scene.
 *
 * `dependsOn[i]` is `i`'s children (`ContactGraph`): whatever rests on `i`
 * sits strictly above it, so following edges strictly increases height and a finite
 * set cannot cycle back on itself -- checked, not assumed, by `isAcyclic()`.
 *
 * `safeRemovalOrder` runs a real simulation, not a topological sort of a static graph:
 * at each step it removes any placement that currently has at least one clear
 * direction (`SequenceGeometry`), which can change as other placements are removed.
 * `SequenceError` is raised only if a step is reached where nothing remaining has a
 * clear direction in any allowed axis.
 *
 * That error path is unreachable for this library's own solver output when every
 * direction is allowed, and this is a proof, not an assumption: an item with nothing
 * placed above it always has a clear `+z` path to the container's own ceiling by
 * definition, so the topmost item(s) by height are always removable; removing them and
 * repeating (structural induction) produces a complete order for any finite placement
 * set. A restricted `directions` set is the only way this library can
 * currently produce a genuine cycle -- see `PackingSequenceTest.php` for a synthetic
 * one built directly, not derived from a real packing, to prove the detector itself is
 * correct independently of that unreachability.
 *
 * See `LoadingDependencyGraph` for the distinct, empty-container-replay counterpart
 * (audit reopen reason: this class alone used to be treated as
 * satisfying both).
 */
final class UnloadingDependencyGraph
{
    /**
     * @var array<int, array<int, true>>
     * @readonly
     */
    private $dependsOn;
    public const ALL_DIRECTIONS = SequenceGeometry::ALL_DIRECTIONS;

    /**
     * Public so a test can build a synthetic (non-geometric) graph directly, to prove
     * `isAcyclic()` is correct independently of whether real geometry can ever produce
     * a cycle -- see `PackingSequenceTest::testASyntheticUnloadingCycleIsDetected`.
     *
     * @param array<int,array<int,true>> $dependsOn keyed by index that must be removed before it
     */
    public function __construct(array $dependsOn)
    {
        $this->dependsOn = $dependsOn;
    }

    /** @param list<AxisAlignedBox> $boxes */
    public static function build(array $boxes): self
    {
        $contacts = new ContactGraph($boxes);
        $dependsOn = [];
        foreach ($boxes as $index => $box) {
            $dependsOn[$index] = array_fill_keys($contacts->children($index), true);
        }
        return new self($dependsOn);
    }

    /** @return array<int,array<int,true>> */
    public function dependsOn(): array
    {
        return $this->dependsOn;
    }

    public function isAcyclic(): bool
    {
        return SequenceGeometry::isAcyclic($this->dependsOn);
    }

    /**
     * A concrete order placements could be removed in, starting from the full scene,
     * without ever needing to move a still-present placement out of the way first.
     * Throws `SequenceError` if no such order exists for the given `$directions`, and
     * `InvalidDirectionError` if `$directions` names anything outside the six-value
     * vocabulary.
     *
     * @param list<AxisAlignedBox> $boxes
     * @param list<string> $directions
     * @return list<int>
     */
    public static function safeRemovalOrder(array $boxes, Dimensions $container, array $directions = self::ALL_DIRECTIONS): array
    {
        SequenceGeometry::validated($directions);
        $graph = self::build($boxes);
        $dependsOn = $graph->dependsOn();
        $present = array_fill_keys(array_keys($boxes), true);
        $order = [];
        while ($present !== []) {
            $removable = [];
            foreach (array_keys($present) as $index) {
                $blockedBySupport = false;
                foreach (array_keys($dependsOn[$index] ?? []) as $dependency) {
                    if (isset($present[$dependency])) { $blockedBySupport = true; break; }
                }
                if ($blockedBySupport) { continue; }
                if (SequenceGeometry::clearDirection($index, $boxes[$index], $boxes, $present, $container, $directions) !== null) {
                    $removable[] = $index;
                }
            }
            if ($removable === []) {
                throw new SequenceError(array_keys($present));
            }
            // Deterministic: the lowest index among this step's candidates, not
            // arbitrary array iteration order.
            sort($removable);
            $chosen = $removable[0];
            $order[] = $chosen;
            unset($present[$chosen]);
        }
        self::replayRemovalOrder($boxes, $container, $order, $directions);
        return $order;
    }

    /**
     * A concrete unloading order that fully empties each stop, in ascending stop
     * order, before the next one starts -- a route check and
     * a restriction to axis-aligned horizontal/vertical exits both
     * fall directly out of `safeRemovalOrder`'s existing machinery, since every
     * `$directions` sweep here is already exactly one of those two kinds; the only
     * thing missing was the route itself.
     *
     * `$stops[$i]` is the stop index placement `$i` is due at; `null` means placement
     * `$i` is not on the route at all -- it is never scheduled for removal and stays
     * present for every step of the replay, exactly like a fixture that rides the
     * whole route, but it still counts as a potential blocker for anything scheduled
     * around it. A request with every `$stops[$i]` set to `null` schedules nothing and
     * returns `[]`, leaving single-stop callers (which never populate this at all)
     * completely unaffected.
     *
     * Throws `RouteSequenceError` at the first stop that cannot be fully unloaded --
     * carrying that stop and the placements still stuck there, not just the first one,
     * since a real deadlock is usually mutual -- and `InvalidDirectionError` if
     * `$directions` names anything outside the six-value vocabulary.
     *
     * @param list<AxisAlignedBox> $boxes
     * @param list<int|null> $stops
     * @param list<string> $directions
     * @return list<int>
     */
    public static function safeRouteRemovalOrder(array $boxes, array $stops, Dimensions $container, array $directions = self::ALL_DIRECTIONS): array
    {
        SequenceGeometry::validated($directions);
        $graph = self::build($boxes);
        $dependsOn = $graph->dependsOn();
        $remaining = array_fill_keys(array_keys($boxes), true);
        $order = [];
        $stopValues = [];
        foreach ($stops as $stop) {
            if ($stop !== null) { $stopValues[$stop] = true; }
        }
        $sortedStops = array_keys($stopValues);
        sort($sortedStops);
        foreach ($sortedStops as $stop) {
            $due = [];
            foreach (array_keys($remaining) as $index) {
                if ($stops[$index] === $stop) { $due[$index] = true; }
            }
            while ($due !== []) {
                $removable = [];
                foreach (array_keys($due) as $index) {
                    $blockedBySupport = false;
                    foreach (array_keys($dependsOn[$index] ?? []) as $dependency) {
                        if (isset($remaining[$dependency])) { $blockedBySupport = true; break; }
                    }
                    if ($blockedBySupport) { continue; }
                    if (SequenceGeometry::clearDirection($index, $boxes[$index], $boxes, $remaining, $container, $directions) !== null) {
                        $removable[] = $index;
                    }
                }
                if ($removable === []) {
                    throw new RouteSequenceError($stop, array_keys($due));
                }
                // Deterministic: the lowest index among this step's candidates, not
                // arbitrary array iteration order.
                sort($removable);
                $chosen = $removable[0];
                $order[] = $chosen;
                unset($remaining[$chosen]);
                unset($due[$chosen]);
            }
        }
        return $order;
    }

    /**
     * `safeRemovalOrder`, with each step's support dependency and the accessibility
     * direction it actually used attached as evidence.
     *
     * @param list<AxisAlignedBox> $boxes
     * @param list<string> $directions
     * @return list<SequenceStep>
     */
    public static function safeRemovalOrderWithEvidence(array $boxes, Dimensions $container, array $directions = self::ALL_DIRECTIONS): array
    {
        $order = self::safeRemovalOrder($boxes, $container, $directions);
        $graph = self::build($boxes);
        $dependsOn = $graph->dependsOn();
        $present = array_fill_keys(array_keys($boxes), true);
        $steps = [];
        foreach ($order as $index) {
            $direction = SequenceGeometry::clearDirection($index, $boxes[$index], $boxes, $present, $container, $directions);
            $steps[] = new SequenceStep($index, $direction, $dependsOn[$index] ?? []);
            unset($present[$index]);
        }
        return $steps;
    }

    /**
     * Per-placement reachability for the full scene as it stands, one
     * entry per box in `$boxes` order.
     *
     * Reuses this class's own dependency graph and the same
     * accessibility sweep `safeRemovalOrder` is built from -- there is no second,
     * possibly-disagreeing notion of "blocked" introduced here. Distinct from that
     * generator: this answers a snapshot question ("what is reachable with nothing
     * yet moved") rather than searching for a complete order, so it never throws
     * `SequenceError` -- an item that is not reachable right now is simply reported
     * as such, evidence attached.
     *
     * `$stops` is optional and behaves exactly like `safeRouteRemovalOrder`'s:
     * `null` (the default, and every entry `null`) leaves `blockedByRoute` empty for
     * every placement, so a single-stop caller that never populates stops is
     * unaffected. When stops are present, a placement due at a later stop than the
     * earliest stop still outstanding is blocked by every present placement due at
     * that earlier stop, exactly the ordering `safeRouteRemovalOrder` enforces for a
     * *complete* unloading order -- reported here per placement instead of only
     * surfacing the first stop a full replay gets stuck at.
     *
     * O(n^2) for `n` placements: one `clearDirection`/`blockingIndices` pass
     * (`O(n)` per allowed direction, at most the fixed six-value vocabulary) per
     * placement, the same bound `safeRemovalOrder`'s own search already pays.
     *
     * @param list<AxisAlignedBox> $boxes
     * @param list<int|null>|null $stops
     * @param list<string> $directions
     * @return list<Reachability>
     */
    public static function placementReachability(array $boxes, Dimensions $container, ?array $stops = null, array $directions = self::ALL_DIRECTIONS): array
    {
        SequenceGeometry::validated($directions);
        $graph = self::build($boxes);
        $dependsOn = $graph->dependsOn();
        $present = array_fill_keys(array_keys($boxes), true);
        $stops = $stops ?? array_fill(0, count($boxes), null);
        if (count($stops) !== count($boxes)) {
            throw new \InvalidArgumentException('stops must contain exactly one entry per placement');
        }
        $dueStops = [];
        foreach (array_keys($present) as $index) {
            if ($stops[$index] !== null) { $dueStops[$stops[$index]] = true; }
        }
        $earliestDueStop = $dueStops === [] ? null : min(array_keys($dueStops));

        $results = [];
        foreach ($boxes as $index => $box) {
            $blockedBySupport = [];
            foreach (array_keys($dependsOn[$index] ?? []) as $dependency) {
                if (isset($present[$dependency])) { $blockedBySupport[$dependency] = true; }
            }
            $blockedByRoute = [];
            if ($stops[$index] !== null && $earliestDueStop !== null && $stops[$index] !== $earliestDueStop) {
                foreach (array_keys($present) as $other) {
                    if ($other !== $index && $stops[$other] !== null && $stops[$other] < $stops[$index]) {
                        $blockedByRoute[$other] = true;
                    }
                }
            }
            $clear = SequenceGeometry::clearDirection($index, $box, $boxes, $present, $container, $directions);
            $blockedByNeighbors = [];
            if ($clear === null && $directions !== []) {
                foreach ($directions as $direction) {
                    $blockedByNeighbors += SequenceGeometry::blockingIndices($index, $box, $boxes, $present, $container, $direction);
                }
                // Evidence, not an order -- a fixed key order regardless of which
                // direction happened to name which blocker first.
                ksort($blockedByNeighbors);
            }
            $reachable = $blockedBySupport === [] && $blockedByRoute === [] && $clear !== null;
            $results[] = new Reachability($index, $reachable, $blockedBySupport, $blockedByNeighbors, $blockedByRoute);
        }
        return $results;
    }

    /**
     * Independently replay an unloading `$order` (full scene to empty) and
     * throw `SequenceReplayError` at the first step that is not actually feasible
     * given only what remains present at that point.
     *
     * This is the "sequence replay validator" the contract calls for: it reuses the same
     * geometric primitives `safeRemovalOrder` is built from (there is no second,
     * possibly-disagreeing notion of "blocked" to maintain), but none of its search or
     * tie-break logic, so it can catch a generator bug -- or a hand-authored or
     * externally-supplied order -- that produces a plausible-looking but wrong
     * sequence, the same role `IndependentSolutionValidator` plays for placements
     * themselves.
     *
     * @param list<AxisAlignedBox> $boxes
     * @param list<int> $order
     * @param list<string> $directions
     */
    public static function replayRemovalOrder(array $boxes, Dimensions $container, array $order, array $directions = self::ALL_DIRECTIONS): void
    {
        SequenceGeometry::validated($directions);
        $expected = array_keys($boxes);
        $sortedOrder = $order;
        sort($sortedOrder);
        if ($sortedOrder !== $expected) {
            throw new SequenceReplayError(-1, -1, 'order is not a permutation of every placement index exactly once');
        }
        $graph = self::build($boxes);
        $dependsOn = $graph->dependsOn();
        $present = array_fill_keys($expected, true);
        foreach ($order as $step => $index) {
            SequenceGeometry::validateBoxAtStep($index, $step, $boxes, $present, $container);
            foreach (array_keys($dependsOn[$index] ?? []) as $dependency) {
                if (isset($present[$dependency])) {
                    throw new SequenceReplayError($index, $step, 'something still resting on it has not been removed yet');
                }
            }
            if (SequenceGeometry::clearDirection($index, $boxes[$index], $boxes, $present, $container, $directions) === null) {
                throw new SequenceReplayError($index, $step, 'no allowed direction is clear of the remaining placements');
            }
            unset($present[$index]);
        }
    }
}
