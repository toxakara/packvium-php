<?php
declare(strict_types=1);
namespace Packvium\Execution;

/**
 * An execution plan derived from an already validated packing result.
 *
 * docs/EXECUTION-PLAN.md is the contract, and `packvium.execution` is the reference
 * implementation this one is held to **byte-identical** on `canonicalPlanJson`. That bar
 * is why several things here look more careful than they need to for PHP alone:
 * integers are cast rather than trusted, keys are emitted in a fixed order, and no value
 * is ever formatted through a locale-sensitive path.
 *
 * The adapter imports no solver and no validator, holds no registry and reads no clock.
 * Everything it emits is a function of the request and result it was handed.
 *
 * Two rules do most of the work, and both are about not quietly becoming a decision-maker:
 * authoritative solver facts and human-readable text are separated in the *output* rather
 * than only in the prose, and every presentation string names the fields it came from.
 */
final class Plan
{
    /**
     * The plan's own format tag. Not the packing schema's version, and it does not move
     * with it: a result can gain fields without changing what a plan says.
     */
    public const FORMAT = 'packvium-execution-plan/v1';

    /**
     * What a `score` index means is a property of the request's objective, which this
     * adapter does not know. Naming an index it cannot explain would be inventing meaning.
     */
    public const UNNAMED_AXIS = 'unnamed objective axis';

    /**
     * A reference two languages agree on, for one placement in one container.
     *
     * `$containerIndex` is the container's position, never its `id`: conformance/canonical.py
     * drops ids from the projection two implementations are diffed against, because an id
     * is "an instance count rather than a semantic property". Position is read from
     * `ticks`, the exact integer, and never from `value`, the rendering the same
     * `exactScalar` also carries.
     *
     * @param array<string,mixed> $placement
     * @return array<string,mixed>
     */
    public static function placementReference(int $containerIndex, array $placement): array
    {
        foreach (['item_type', 'orientation', 'position'] as $required) {
            if (!\array_key_exists($required, $placement)) {
                throw new ExecutionPlanException(
                    "placement is missing a field the reference is built from: '$required'"
                );
            }
        }
        $ticks = [];
        foreach (['x', 'y', 'z'] as $axis) {
            if (!isset($placement['position'][$axis]['ticks'])) {
                throw new ExecutionPlanException(
                    "placement is missing a field the reference is built from: 'position.$axis.ticks'"
                );
            }
            $ticks[$axis] = (int) $placement['position'][$axis]['ticks'];
        }
        return [
            'container_index' => $containerIndex,
            'item_type' => $placement['item_type'],
            'orientation' => $placement['orientation'],
            'position_ticks' => $ticks,
        ];
    }

    /**
     * The operator sequence for one container, or an honest absence of one.
     *
     * The engines compute a loading order from domain objects this adapter never sees, so
     * it is injected: a caller who has the order passes it, one who does not gets none.
     * Falling back to the order placements happen to appear in would present an artifact
     * of how the solver walked its candidates as a safe order to lift boxes in.
     *
     * @param array<string,mixed> $container
     * @param list<int>|null $loadingOrder
     * @return array<string,mixed>
     */
    private static function steps(int $containerIndex, array $container, ?array $loadingOrder): array
    {
        $placements = \array_values($container['placements'] ?? []);
        if ($loadingOrder === null) {
            $steps = [];
            foreach ($placements as $placement) {
                $steps[] = ['placement' => self::placementReference($containerIndex, $placement)];
            }
            return ['order' => 'unavailable', 'steps' => $steps];
        }
        $sorted = $loadingOrder;
        \sort($sorted);
        if ($sorted !== \range(0, \count($placements) - 1)) {
            throw new ExecutionPlanException(
                "loading order for container $containerIndex is not a permutation of its "
                . \count($placements) . ' placements'
            );
        }
        $steps = [];
        foreach ($loadingOrder as $step => $index) {
            $steps[] = [
                'sequence' => $step + 1,
                'placement' => self::placementReference($containerIndex, $placements[$index]),
            ];
        }
        return ['order' => 'loading', 'steps' => $steps];
    }

    /**
     * The first index at which two score vectors differ, and by how much.
     *
     * Never a blended number. The portfolio compared these lexicographically, so the first
     * differing index *is* the decision; weighting the vector would replace a decision that
     * was made with one that was not.
     *
     * @param list<int> $winner
     * @param list<int> $loser
     * @return array<string,int>|null
     */
    private static function firstDifference(array $winner, array $loser): ?array
    {
        $shared = \min(\count($winner), \count($loser));
        for ($index = 0; $index < $shared; $index++) {
            if ($winner[$index] !== $loser[$index]) {
                return [
                    'index' => $index,
                    'winner' => (int) $winner[$index],
                    'alternative' => (int) $loser[$index],
                    'difference' => (int) $loser[$index] - (int) $winner[$index],
                ];
            }
        }
        if (\count($winner) !== \count($loser)) {
            throw new ExecutionPlanException(
                'score vectors of different length cannot be compared lexicographically'
            );
        }
        return null;
    }

    /**
     * @param list<int> $winnerScore
     * @param array<string,mixed> $alternative
     * @return array<string,mixed>
     */
    private static function alternative(int $index, array $winnerScore, array $alternative): array
    {
        $score = \array_map('intval', \array_values($alternative['score'] ?? []));
        $difference = self::firstDifference($winnerScore, $score);
        if ($difference === null) {
            $text = 'This option scored identically to the chosen one on every objective axis; '
                . 'the score does not record why one was taken.';
        } else {
            $text = 'This option differs first at objective axis ' . $difference['index']
                . ' (' . self::UNNAMED_AXIS . '): chosen ' . $difference['winner']
                . ', this ' . $difference['alternative'] . '.';
        }
        return [
            'facts' => [
                'alternative_index' => $index,
                'score' => $score,
                'status' => $alternative['status'] ?? null,
                'first_difference' => $difference,
            ],
            // Deliberately not "it lost because it is taller". The solver recorded a score,
            // not a cause; naming a cause would be a claim nothing in the result supports.
            'presentation' => [
                'summary' => $text,
                'cites' => ['score', 'alternatives[].score'],
            ],
        ];
    }

    /**
     * Derive the execution plan for one validated result.
     *
     * @param array<string,mixed> $request
     * @param array<string,mixed> $result
     * @param array<int,list<int>> $loadingOrders container index => engine-computed order
     * @return array<string,mixed>
     */
    public static function build(array $request, array $result, array $loadingOrders = []): array
    {
        if (!isset($result['status'])) {
            throw new ExecutionPlanException('a result without a status is not a validated result');
        }
        $containers = \array_values($result['containers'] ?? []);
        $winnerScore = \array_map('intval', \array_values($result['score'] ?? []));

        $planContainers = [];
        foreach ($containers as $index => $container) {
            $planContainers[] = [
                'container_index' => $index,
                'facts' => [
                    'container_type' => $container['container_type'] ?? null,
                    'placement_count' => \count($container['placements'] ?? []),
                    'volume_utilization' => $container['volume_utilization'] ?? null,
                ],
            ] + self::steps($index, $container, $loadingOrders[$index] ?? null);
        }

        $unplaced = [];
        foreach ($result['unpacked_items'] ?? [] as $item) {
            $level = $item['proof']['level'] ?? null;
            $unplaced[] = [
                'facts' => [
                    'item_type' => $item['item_type'] ?? null,
                    'reason' => $item['reason'] ?? null,
                    // Carried through unchanged. Softening `observed` into "could not fit"
                    // would turn an honest limit into a false certainty.
                    'proof_level' => $level,
                    'details' => \array_values($item['details'] ?? []),
                ],
                'presentation' => [
                    'summary' => 'Not packed: ' . ($item['reason'] ?? '') . ' (' . ($level ?? '') . ').',
                    'cites' => ['unpacked_items[].reason', 'unpacked_items[].proof.level'],
                ],
            ];
        }

        $alternatives = [];
        foreach (\array_values($result['alternatives'] ?? []) as $index => $alternative) {
            $alternatives[] = self::alternative($index, $winnerScore, $alternative);
        }

        return [
            'format' => self::FORMAT,
            'objective' => $result['objective'] ?? null,
            'facts' => [
                'status' => $result['status'],
                'score' => $winnerScore,
                'feasibility' => $result['feasibility'] ?? null,
                'optimality' => $result['optimality'] ?? null,
                'container_count' => \count($containers),
            ],
            'containers' => $planContainers,
            // Often empty, and not for one reason. Four produce an empty list: the
            // `fast` profile runs a single solver, stops the start loop once the
            // grid lattice packs everything, only one start completed, or
            // `alternatives: 1` -- the cap counts the winner. Measured over the corpus,
            // 165 of 399 requests do carry one, so this is not the rare case an earlier
            // draft of this comment claimed. An empty list is well-formed and
            // is never an error.
            'alternatives' => $alternatives,
            'unplaced' => $unplaced,
        ];
    }

    /**
     * The one byte-comparable spelling of a plan.
     *
     * Cross-language equality is asserted on this string rather than on a parsed array, so
     * key order and whitespace cannot make two identical plans look different. Keys are
     * sorted recursively because PHP preserves insertion order where Python's
     * `sort_keys=True` does not care, and `JSON_UNESCAPED_*` matches `ensure_ascii=False`.
     *
     * @param array<string,mixed> $plan
     */
    public static function canonicalJson(array $plan): string
    {
        return (string) \json_encode(
            self::sortKeys($plan),
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sortKeys($value)
    {
        if (!\is_array($value)) {
            return $value;
        }
        // A list stays a list: sorting its keys would reorder steps, which are ordered on
        // purpose. Only associative arrays are sorted, matching `sort_keys=True`.
        if ($value === [] || \array_keys($value) === \range(0, \count($value) - 1)) {
            return \array_map([self::class, 'sortKeys'], $value);
        }
        \ksort($value, \SORT_STRING);
        return \array_map([self::class, 'sortKeys'], $value);
    }
}
