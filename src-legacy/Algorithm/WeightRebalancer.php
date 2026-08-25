<?php
declare(strict_types=1);

namespace Packvium\Algorithm;

use Packvium\Config\PackingConfig;
use Packvium\Constraint\ConstraintSet;
use Packvium\Domain\{PackedContainer, PackingRequest, Placement, UnpackedItem, UnratedWeightException};
use Packvium\Extension\DefaultCandidateScorer;
use Packvium\Objective\{LandedCostSolutionScorer, UnknownObjectiveException};
use Packvium\Validation\IndependentSolutionValidator;

/**
 * Redistributes payload weight across an already-solved multi-container packing.
 *
 * An explicit, opt-in post-processing pass: call it after `Packer::pack()`, the same
 * way a post-pass weight redistributor is invoked separately from
 * packing itself, not run automatically inside `Packer::pack()`. `$unpacked` should be
 * the same list `Packer::pack()` returned (or `[]` for an already complete packing) --
 * it is passed straight through to the validator so full accounting is checked against
 * every requested instance, not only the ones currently sitting in a container.
 *
 * such a post-pass carries a well-known failure mode: it can silently
 * drop an item, because its own bookkeeping only checks whether the box count is still
 * 1, not whether every item it started with is still accounted for. This class never
 * substitutes a partial signal like that for the real thing. Every candidate move is
 * simulated in full and checked against the same `IndependentSolutionValidator` every
 * solve in this library is checked against -- a fresh, independent re-derivation of
 * the whole packing from its placements alone -- and only committed once that check
 * passes. See `attemptMove` for exactly what that guarantees.
 *
 * Greedy and best-effort, not globally optimal: each round moves at most one item,
 * from whichever container is currently heaviest, trying its own placements
 * heaviest-first against the other containers lightest-first, and commits the first
 * combination that both fits and strictly reduces the gap between the heaviest and
 * lightest container's payload weight. Requiring a strict reduction on every commit
 * makes the payload spread a strictly decreasing sequence of non-negative integers, so
 * the pass always terminates on its own (bounded again by `$maxMoves`) whether or not
 * it reaches the smallest spread achievable by some other arrangement. Mirrors
 * `packvium.rebalance.rebalance_weight` in the Python package -- see that module's
 * own docstring for the same argument in more detail.
 */
final class WeightRebalancer
{
    /**
     * @param list<PackedContainer> $containers
     * @param list<UnpackedItem> $unpacked
     */
    public static function rebalance(
        PackingRequest $request,
        array $containers,
        array $unpacked = [],
        ?PackingConfig $config = null,
        int $maxMoves = 64,
        int $timeLimitMs = 1000
    ): RebalanceResult {
        $config = $config ?? new PackingConfig();
        if (($config->objective === 'shipping_cost' || $config->objective === 'lowest_landed_cost')
            && $config->dimensionalWeightDivisor === null
        ) {
            throw new UnknownObjectiveException(
                'the ' . $config->objective . ' objective requires configuration.dimensional_weight_divisor',
            );
        }
        if ($config->objective === 'lowest_landed_cost') {
            foreach ($request->containers as $container) {
                if ($container->rateTable === null) {
                    throw new UnknownObjectiveException(
                        'the lowest_landed_cost objective requires a rate_table on every container; '
                        . "'{$container->id}' has none",
                    );
                }
            }
        }
        // Under lowest_landed_cost an input that already bills past a rate table's last
        // bracket has no published price to preserve: refuse it outright, in the same
        // words as `Packer::pack()`, rather than shuffle weight inside an answer the
        // caller cannot ship ( review). A no-op for every other objective --
        // `unpriceableContainer` is itself gated on the objective and divisor.
        $unpriceable = LandedCostSolutionScorer::unpriceableContainer($containers, $config);
        if ($unpriceable !== null) {
            [$containerId, $grams, $bound] = $unpriceable;
            throw new UnratedWeightException(
                "container '{$containerId}' bills at {$grams} g, above its rate table's "
                . "last bracket ({$bound} g); the shipment has no published price"
            );
        }
        $validator = new IndependentSolutionValidator();
        $deadline = Deadline::ofMilliseconds($timeLimitMs);
        $working = array_values($containers);
        $moves = [];

        for ($round = 0; $round < $maxMoves; $round++) {
            if (count($working) < 2 || $deadline->expired()) {
                break;
            }
            $weights = array_map(static function (PackedContainer $c): int {
                return $c->payloadWeight()->ticks;
            }, $working);
            $spread = max($weights) - min($weights);
            if ($spread <= 0) {
                break;
            }
            $sourceIndex = self::argMax($weights);
            $source = $working[$sourceIndex];

            $rankedItems = range(0, count($source->placements) - 1);
            usort($rankedItems, static function (int $a, int $b) use ($source): int {
                return ($source->placements[$b]->instance->weight()->ticks <=> $source->placements[$a]->instance->weight()->ticks)
                ?: ($a <=> $b);
            });

            $destinations = array_values(array_filter(
                range(0, count($working) - 1),
                static function (int $i) use ($sourceIndex): bool {
                    return $i !== $sourceIndex;
                },
            ));
            usort($destinations, static function (int $a, int $b) use ($weights): int {
                return ($weights[$a] <=> $weights[$b]) ?: ($a <=> $b);
            });

            $committed = null;
            foreach ($rankedItems as $placementIndex) {
                $weightTicks = $source->placements[$placementIndex]->instance->weight()->ticks;
                if ($weightTicks <= 0) {
                    continue;
                }
                foreach ($destinations as $destIndex) {
                    $projected = $weights;
                    $projected[$sourceIndex] -= $weightTicks;
                    $projected[$destIndex] += $weightTicks;
                    if (max($projected) - min($projected) >= $spread) {
                        continue;
                    }
                    try {
                        $trial = self::attemptMove(
                            $request, $validator, $working, $unpacked,
                            $sourceIndex, $destIndex, $placementIndex, $config, $deadline,
                        );
                    } catch (TimeLimitReached $exception) {
                        $trial = null;
                    }
                    if ($trial === null) {
                        continue;
                    }
                    $committed = [
                        $trial,
                        $source->placements[$placementIndex]->instance->id(),
                        $source->id(),
                        $working[$destIndex]->id(),
                    ];
                    break 2;
                }
            }
            if ($committed === null) {
                break;
            }
            [$trial, $itemId, $fromId, $toId] = $committed;
            $moves[] = new WeightMove($itemId, $fromId, $toId);
            $working = $trial;
        }

        return new RebalanceResult($working, $moves);
    }

    /**
     * Try relocating one placement; return the new container list if it is sound, else null.
     *
     * Every candidate is proven, not assumed: the item must find a real,
     * constraint-respecting spot in the destination (`CandidateFinder::find`, the same
     * search a solve itself uses), and the *entire* resulting set of containers must
     * then pass the independent validator before this reports success. Nothing is
     * removed from its source ahead of that check -- both container entries are only
     * ever replaced together, in the same returned array, so there is no observable
     * state in which the item belongs to neither its old container nor its new one,
     * and no path that "finishes" a move without the item landing exactly once. A move
     * that fails geometrically (no room in the destination), physically (the
     * validator rejects the result -- for example because something was resting on the
     * item that just moved) or commercially (under lowest_landed_cost the destination
     * would bill past its rate table's last bracket,  review) simply is not made;
     * the caller's container list is untouched.
     *
     * @param list<PackedContainer> $containers
     * @param list<UnpackedItem> $unpacked
     * @return list<PackedContainer>|null
     */
    private static function attemptMove(
        PackingRequest $request,
        IndependentSolutionValidator $validator,
        array $containers,
        array $unpacked,
        int $sourceIndex,
        int $destIndex,
        int $placementIndex,
        PackingConfig $config,
        Deadline $deadline
    ): ?array {
        $source = $containers[$sourceIndex];
        $dest = $containers[$destIndex];
        $moving = $source->placements[$placementIndex];

        $destState = new ContainerState($dest->container, $dest->sequence);
        foreach ($dest->placements as $placement) {
            $destState->add($placement);
        }
        $constraints = ConstraintSet::defaults($config->minimumSupportRatio);
        $stats = new SearchStats();
        $candidates = CandidateFinder::find(
            $destState, $moving->instance, $config, $constraints, $stats, $deadline,
            new DefaultCandidateScorer(), 1,
        );
        if ($candidates === []) {
            return null;
        }
        $candidate = $candidates[0];

        $newDestPlacement = new Placement(
            $moving->instance, $candidate->position, $candidate->rotation, $candidate->dimensions,
            $candidate->point, $candidate->envelopeDimensions,
            SupportCalculator::ratio($destState, $moving->instance, $candidate),
        );
        $newSourcePlacements = array_merge(
            array_slice($source->placements, 0, $placementIndex),
            array_slice($source->placements, $placementIndex + 1),
        );
        $newDestPlacements = array_merge($dest->placements, [$newDestPlacement]);

        $trial = $containers;
        $trial[$sourceIndex] = new PackedContainer($source->container, $source->sequence, TopLoadAssigner::assign($newSourcePlacements));
        $trial[$destIndex] = new PackedContainer($dest->container, $dest->sequence, TopLoadAssigner::assign($newDestPlacements));

        $report = $validator->validate($request, $trial, $config->minimumSupportRatio, $config->clearance, $unpacked);
        if (!$report->valid) {
            return null;
        }
        // A move can be geometrically and physically sound yet lift the destination's
        // billed weight past its rate table's last bracket. Under lowest_landed_cost
        // that would trade a shippable packing for one with no published price, so the
        // candidate is skipped and the search moves on ( review).
        if (LandedCostSolutionScorer::unpriceableContainer($trial, $config) !== null) {
            return null;
        }
        return $trial;
    }

    /** @param list<int> $weights */
    private static function argMax(array $weights): int
    {
        $best = 0;
        foreach ($weights as $index => $weight) {
            if ($weight > $weights[$best]) {
                $best = $index;
            }
        }
        return $best;
    }
}
