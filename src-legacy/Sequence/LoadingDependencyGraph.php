<?php
declare(strict_types=1);
namespace Packvium\Sequence;

use Packvium\Constraint\ContactGraph;
use Packvium\Constraint\LoadCalculator;
use Packvium\Constraint\Internal\LoadSupportGraph;
use Packvium\Domain\AxisAlignedBox;
use Packvium\Domain\Container;
use Packvium\Domain\Dimensions;
use Packvium\Domain\Placement;

/**
 * Loading dependency graph: which placements must already be present before
 * others can be loaded, replaying from an empty container.
 *
 * `dependsOn[i]` is `i`'s supporters (`ContactGraph`): whatever `i` rests on
 * sits strictly below it, so following edges strictly *decreases* height and a finite
 * set cannot cycle back on itself either -- checked, not assumed, by `isAcyclic()`.
 *
 * See `UnloadingDependencyGraph` for the distinct, full-scene-replay counterpart.
 */
final class LoadingDependencyGraph
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
     * a cycle -- see `PackingSequenceTest::testASyntheticLoadingCycleIsDetected`.
     *
     * @param array<int,array<int,true>> $dependsOn keyed by index that must already be loaded before it
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
            $supporterIndexes = array_map(static function ($edge) {
                return $edge->index;
            }, $contacts->supporters($index));
            $dependsOn[$index] = array_fill_keys($supporterIndexes, true);
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
     * A concrete order placements could be loaded in, starting from an empty
     * container, so that at every step the item being added already has every one of
     * its supporters present and a collision-free insertion sweep in some allowed
     * direction against only what has been loaded so far. Throws `SequenceError` if no
     * such order exists for the given `$directions`, and `InvalidDirectionError` if
     * `$directions` names anything outside the six-value vocabulary.
     *
     * Computed as the exact reverse of a valid unloading order for the same scene and
     * `$directions`, not a second, independent forward search -- and this is a proof,
     * not a shortcut. `UnloadingDependencyGraph::safeRemovalOrder`'s greedy choice is
     * trap-free because unloading only ever *shrinks* the present set, so a step that
     * is clear now stays clear forever; a naive forward loading search does not have
     * that property, since loading only ever *grows* the present set, and an early
     * greedy choice can permanently block a later item's only clear direction
     * (measured directly: two side-by-side items with only one exit allowed can
     * deadlock a naive greedy loader that placed the easy one first). Reversal
     * sidesteps that trap entirely: `UnloadingDependencyGraph` only ever depends on a
     * box's *children*, so `s` supporting `r` puts `r` in `s`'s children and forces
     * `r` out before `s` in any valid unloading order `R` -- meaning `s` precedes `r`
     * in `reversed(R)`, exactly this graph's own supporters-first requirement. The
     * accessibility sweep is symmetric by construction (`SequenceGeometry` measures
     * the same region whether a box is leaving through a wall or arriving through it),
     * so a step clear for removal at position `k` of `R` is clear for loading at
     * `reversed(R)`'s matching position against the identical present set.
     * `replayLoadingOrder` re-derives and checks this independently against this
     * graph and a real forward simulation -- it does not call this method or trust
     * the reversal, so a mistake in this reasoning could not hide behind it.
     *
     * @param list<AxisAlignedBox> $boxes
     * @param list<string> $directions
     * @return list<int>
     */
    public static function safeLoadingOrder(array $boxes, Dimensions $container, array $directions = self::ALL_DIRECTIONS): array
    {
        SequenceGeometry::validated($directions);
        $order = array_reverse(UnloadingDependencyGraph::safeRemovalOrder($boxes, $container, $directions));
        self::replayLoadingOrder($boxes, $container, $order, $directions);
        return $order;
    }

    /**
     * Composed domain-level entry point: geometry, accessibility and every stacking
     * business rule are all replayed before an order is returned.
     *
     * @param list<Placement> $placements
     * @param list<string> $directions
     * @return list<int>
     */
    public static function safeLoadingOrderForPlacements(array $placements, Container $container, array $directions = self::ALL_DIRECTIONS): array
    {
        $boxes = array_map(static function (Placement $placement): AxisAlignedBox {
            return $placement->envelopeBox();
        }, $placements);
        $order = self::safeLoadingOrder($boxes, $container->innerDimensions, $directions);
        self::verifyLoadingPrefixBusinessRules($placements, $order, $container);
        return $order;
    }

    /**
     * `safeLoadingOrder`, with each step's support dependency and the accessibility
     * direction it actually used attached as evidence.
     *
     * @param list<AxisAlignedBox> $boxes
     * @param list<string> $directions
     * @return list<SequenceStep>
     */
    public static function safeLoadingOrderWithEvidence(array $boxes, Dimensions $container, array $directions = self::ALL_DIRECTIONS): array
    {
        $order = self::safeLoadingOrder($boxes, $container, $directions);
        $graph = self::build($boxes);
        $dependsOn = $graph->dependsOn();
        $present = [];
        $steps = [];
        foreach ($order as $index) {
            $direction = SequenceGeometry::clearDirection($index, $boxes[$index], $boxes, $present, $container, $directions);
            $steps[] = new SequenceStep($index, $direction, $dependsOn[$index] ?? []);
            $present[$index] = true;
        }
        return $steps;
    }

    /**
     * Independently replay a loading `$order` (empty container to full
     * scene) and throw `SequenceReplayError` at the first step that is not actually
     * feasible given only what has already been loaded at that point. The loading
     * counterpart to `UnloadingDependencyGraph::replayRemovalOrder`, sharing the same
     * primitives and the same independence from `safeLoadingOrder`'s own search logic.
     *
     * @param list<AxisAlignedBox> $boxes
     * @param list<int> $order
     * @param list<string> $directions
     */
    public static function replayLoadingOrder(array $boxes, Dimensions $container, array $order, array $directions = self::ALL_DIRECTIONS): void
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
        $present = [];
        foreach ($order as $step => $index) {
            SequenceGeometry::validateBoxAtStep($index, $step, $boxes, $present, $container);
            foreach (array_keys($dependsOn[$index] ?? []) as $dependency) {
                if (!isset($present[$dependency])) {
                    throw new SequenceReplayError($index, $step, 'a supporter has not been loaded yet');
                }
            }
            if (SequenceGeometry::clearDirection($index, $boxes[$index], $boxes, $present, $container, $directions) === null) {
                throw new SequenceReplayError($index, $step, 'no allowed direction is clear of what has already been loaded');
            }
            $present[$index] = true;
        }
    }

    /**
     * Independently reuse the exact constraint calculations already proven
     * for a finished scene (`LoadCalculator::units`/`overloaded`/`stackLimitExceeded`/
     * `stackDensityExceeded`/`stackedCounts`) against every loading *prefix*, not only
     * the final state. Additive to `replayLoadingOrder` above rather than a change to
     * it or to any of the bare-geometry functions this file already exposes, so every
     * existing caller keeps working unmodified.
     *
     * Throws `SequenceReplayError` at the first step whose prefix violates a limit --
     * pinned to that step even though these particular rules only ever accumulate as
     * loading proceeds (a violation present at step k is also present in the final
     * scene): identifying *which* addition first broke a limit is strictly more
     * useful to a caller than "the finished scene is invalid" alone, and is the
     * reason this walks the prefix sequence instead of checking only the last step.
     *
     * `groundContactRule`'s FREE/COVERED/SINGLE/MULTIPLE semantics are re-derived here
     * directly from `$present` (boxes whose top face meets the new item's base,
     * exactly `SupportConstraint::evaluate`'s own definition of a supporting surface)
     * rather than through a spatial index: at prefix-replay time `$present` is
     * already the complete set of what a spatial index would be asked for, so
     * building one would only add indirection, not precision.
     *
     * @param list<Placement> $placements
     * @param list<int> $order
     */
    public static function verifyLoadingPrefixBusinessRules(array $placements, array $order, Container $container): void
    {
        $expected = array_keys($placements);
        $sortedOrder = $order;
        sort($sortedOrder);
        if ($sortedOrder !== $expected) {
            throw new SequenceReplayError(-1, -1, 'order is not a permutation of every placement index exactly once');
        }
        $maxDensityTicks = ($nullsafeVariable1 = $container->maxStackDensity) ? $nullsafeVariable1->ticks : null;
        $present = [];
        foreach ($order as $step => $index) {
            $present[] = $placements[$index];
            $units = LoadCalculator::units($present);
            $problem = LoadCalculator::overloaded($units)
                ?? LoadCalculator::stackLimitExceeded($units)
                ?? LoadCalculator::stackDensityExceeded($units, $maxDensityTicks);
            if ($problem === null) {
                $counts = LoadCalculator::stackedCounts($units);
                foreach ($units as $unitIndex => $unit) {
                    if (!$present[$unitIndex]->instance->item->stackable && $counts[$unitIndex] > 0) {
                        $problem = ['non_stackable_item_has_load', $unit->label];
                        break;
                    }
                }
            }
            if ($problem === null) {
                $candidate = $present[count($present) - 1];
                $rule = $candidate->instance->item->groundContactRule;
                $candidateBox = $candidate->envelopeBox();
                if ($rule !== null && $rule !== 'free' && $candidateBox->origin->z !== 0) {
                    $support = LoadSupportGraph::candidateView(
                        array_slice($present, 0, -1), $candidate->instance, $candidateBox,
                    );
                    $count = count($support->supporterBoxes);
                    $violated = ($rule === 'covered' && !\Packvium\Constraint\SupportConstraint::touchesCorners($candidateBox, $support->surfaces))
                        || ($rule === 'single' && $count !== 1)
                        || ($rule === 'multiple' && $count < 2);
                    if ($violated) {
                        $problem = ['ground_contact_violation', $candidate->instance->id()];
                    }
                }
            }
            if ($problem !== null) {
                [$code, $label] = $problem;
                throw new SequenceReplayError($index, $step, "{$code}: {$label}");
            }
        }
    }
}
