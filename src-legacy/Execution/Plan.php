<?php
declare(strict_types=1);
namespace Packvium\Execution;

use Packvium\Support\CanonicalJson;
use Packvium\Support\JsonValue;

/**
 * An execution plan derived from an already validated packing result.
 *
 * docs/EXECUTION-PLAN.md is the contract, and `packvium.execution` is the reference
 * implementation this one is held to **byte-identical** on `canonicalPlanJson`. That bar
 * is why several things here look more careful than they need to for PHP alone:
 * integers are cast rather than trusted, keys are emitted in a fixed order, and no value
 * is ever formatted through a locale-sensitive path.
 *
 * The request and result may be associative arrays or JSON decoded with objects as
 * `stdClass`; the second keeps an empty object such as `feasibility: {}` an object in the
 * plan, which an associative array cannot.
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
     * @param array<string,mixed>|\stdClass|mixed $placement
     * @return array<string,mixed>
     */
    public static function placementReference(int $containerIndex, $placement): array
    {
        foreach (['item_type', 'orientation', 'position'] as $required) {
            if (!JsonValue::has($placement, $required)) {
                throw new ExecutionPlanException(
                    "placement is missing a field the reference is built from: '$required'"
                );
            }
        }
        $position = JsonValue::get($placement, 'position');
        $ticks = [];
        foreach (['x', 'y', 'z'] as $axis) {
            $tick = JsonValue::get(JsonValue::get($position, $axis), 'ticks');
            if ($tick === null || !\is_scalar($tick)) {
                throw new ExecutionPlanException(
                    "placement is missing a field the reference is built from: 'position.$axis.ticks'"
                );
            }
            $ticks[$axis] = (int) $tick;
        }
        return [
            'container_index' => $containerIndex,
            'item_type' => JsonValue::get($placement, 'item_type'),
            'orientation' => JsonValue::get($placement, 'orientation'),
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
     * @param mixed $container
     * @param list<int>|null $loadingOrder
     * @return array<string,mixed>
     */
    private static function steps(int $containerIndex, $container, ?array $loadingOrder): array
    {
        $placements = self::sequence(JsonValue::get($container, 'placements'));
        if ($loadingOrder === null) {
            $steps = [];
            foreach ($placements as $placement) {
                $steps[] = ['placement' => self::placementReference($containerIndex, $placement)];
            }
            return ['order' => 'unavailable', 'steps' => $steps];
        }
        $sorted = $loadingOrder;
        \sort($sorted);
        // Not `range(0, count - 1)`: for an empty container PHP's range counts down to [0, -1].
        $indices = $placements === [] ? [] : \range(0, \count($placements) - 1);
        if ($sorted !== $indices) {
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
     * @param mixed $alternative
     * @return array<string,mixed>
     */
    private static function alternative(int $index, array $winnerScore, $alternative): array
    {
        $score = \array_map('intval', self::sequence(JsonValue::get($alternative, 'score')));
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
                'status' => JsonValue::get($alternative, 'status'),
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
     * @param array<string,mixed>|\stdClass $request
     * @param array<string,mixed>|\stdClass $result
     * @param array<int,list<int>> $loadingOrders container index => engine-computed order
     * @return array<string,mixed>
     */
    public static function build($request, $result, array $loadingOrders = []): array
    {
        if (JsonValue::get($result, 'status') === null) {
            throw new ExecutionPlanException('a result without a status is not a validated result');
        }
        $containers = self::sequence(JsonValue::get($result, 'containers'));
        $winnerScore = \array_map('intval', self::sequence(JsonValue::get($result, 'score')));

        $planContainers = [];
        foreach ($containers as $index => $container) {
            $steps = self::steps($index, $container, $loadingOrders[$index] ?? null);
            $planContainers[] = [
                'container_index' => $index,
                'facts' => [
                    'container_type' => JsonValue::get($container, 'container_type'),
                    'placement_count' => \count(self::sequence(JsonValue::get($container, 'placements'))),
                    'volume_utilization' => JsonValue::get($container, 'volume_utilization'),
                ],
            ] + $steps;
        }

        $unplaced = [];
        foreach (self::sequence(JsonValue::get($result, 'unpacked_items')) as $item) {
            $reason = JsonValue::get($item, 'reason');
            $level = JsonValue::get(JsonValue::get($item, 'proof'), 'level');
            $unplaced[] = [
                'facts' => [
                    'item_type' => JsonValue::get($item, 'item_type'),
                    'reason' => $reason,
                    // Carried through unchanged. Softening `observed` into "could not fit"
                    // would turn an honest limit into a false certainty.
                    'proof_level' => $level,
                    'details' => self::sequence(JsonValue::get($item, 'details')),
                ],
                'presentation' => [
                    'summary' => 'Not packed: ' . ($reason ?? '') . ' (' . ($level ?? '') . ').',
                    'cites' => ['unpacked_items[].reason', 'unpacked_items[].proof.level'],
                ],
            ];
        }

        $alternatives = [];
        foreach (self::sequence(JsonValue::get($result, 'alternatives')) as $index => $alternative) {
            $alternatives[] = self::alternative($index, $winnerScore, $alternative);
        }

        return [
            'format' => self::FORMAT,
            'objective' => JsonValue::get($result, 'objective'),
            'facts' => [
                'status' => JsonValue::get($result, 'status'),
                'score' => $winnerScore,
                'feasibility' => JsonValue::get($result, 'feasibility'),
                'optimality' => JsonValue::get($result, 'optimality'),
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
     * The one byte-comparable spelling of a plan: RFC 8785, shared with the operational
     * artifact and held to `packvium.execution.canonical_plan_json`.
     *
     * Cross-language equality is asserted on this string rather than on a parsed array, so
     * key order and whitespace cannot make two identical plans look different. Until 1.3.0
     * this was `json_encode` over recursively sorted keys: the same bytes for every plan the
     * corpus produces, but not for a string holding U+2028, a key outside the Basic
     * Multilingual Plane, or a float, where the four adapters disagreed.
     *
     * @param array<string,mixed> $plan
     * @throws \Packvium\Support\CanonicalJsonException when a value has no canonical spelling
     */
    public static function canonicalJson(array $plan): string
    {
        return CanonicalJson::encode($plan);
    }

    /**
     * A JSON array as a list, and an absent one as empty. Python reads these fields as
     * `list(value or ())`, so the other empty values -- `{}`, `""`, `0`, `false` -- are empty
     * too; any other non-list has no entries to describe.
     *
     * @param mixed $value
     * @return list<mixed>
     */
    private static function sequence($value): array
    {
        if (\is_array($value)) {
            return \array_values($value);
        }
        if ($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0
            || ($value instanceof \stdClass && JsonValue::members($value) === [])) {
            return [];
        }
        throw new ExecutionPlanException('a field the plan lists is not a list');
    }
}
