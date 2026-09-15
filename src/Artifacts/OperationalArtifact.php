<?php
declare(strict_types=1);
namespace Packvium\Artifacts;

use JsonException;
use Packvium\Execution\ExecutionPlanException;
use Packvium\Execution\Plan;
use Packvium\Support\CanonicalJson;
use Packvium\Support\CanonicalJsonException;
use Packvium\Support\JsonValue;

/**
 * The portable operational artifact.
 *
 * `docs/OPERATIONAL-ARTIFACTS.md` is the contract and `packvium.artifacts` is the reference
 * this builder is held to **byte-identical** on {@see canonicalJson()}. An execution plan says
 * what to do first; the artifact is the one document that can also be drawn, printed and
 * traced offline, and it adds nothing a solver decided.
 *
 * It reads the request, the result and the optional loading orders -- the plan's own inputs
 * -- and calls no solver, validator, renderer or clock. The plan inside it is exactly what
 * {@see Plan::build()} emits for the same inputs, and placements are found by the plan's
 * placement reference, never by `item_id`.
 *
 * JSON text belongs in {@see fromJson()} and {@see parse()}, which decode objects as
 * `stdClass` so `{}` and `{"0":"a"}` survive into the embedded request and the plan.
 * {@see build()} also takes associative arrays, where PHP cannot tell `{}` from `[]`: there a
 * list, `[]` included, is a JSON array.
 */
final class OperationalArtifact
{
    public const FORMAT = 'packvium-operational-artifact/v1';

    /**
     * The suite version of this builder, the same string in all four engines of one release.
     * The engine's own name is deliberately not recorded: four correct builders naming
     * themselves would emit four different documents.
     */
    public const SUITE_VERSION = '1.3.0';

    /** The deterministic part of `result.algorithm`; `duration_ms` is wall-clock time. */
    private const SOLVER_FIELDS = ['profile', 'solver', 'seed', 'time_limit_reached', 'effort_limit_reached'];
    private const DIMENSION_AXES = ['length', 'width', 'height'];
    private const POSITION_AXES = ['x', 'y', 'z'];
    private const JSON_DEPTH = 512;

    /**
     * Build the artifact for one validated result. O(R + P) for a request of size R and P
     * placements, plus O(N + K log K) to prove it has a canonical spelling.
     *
     * @param mixed $request a JSON object: a `stdClass` or an associative array
     * @param mixed $result a JSON object: a `stdClass` or an associative array
     * @param array<int,list<int>> $loadingOrders container index => engine-computed order
     * @return array<string,mixed>
     * @throws OperationalArtifactException
     */
    public static function build($request, $result, array $loadingOrders = []): array
    {
        if (!JsonValue::isObject($request)) {
            throw new OperationalArtifactException('invalid_request', 'a request is a JSON object');
        }
        if (!JsonValue::isObject($result)) {
            throw new OperationalArtifactException('invalid_result', 'a result is a JSON object');
        }
        self::requireObjects($result);
        $plan = self::plan($request, $result, $loadingOrders);

        $containers = self::listOf(JsonValue::get($result, 'containers'), 'result.containers');
        [$lengthUnit, $weightUnit] = self::units($containers);
        $provenance = self::provenance($request, $result);
        $geometry = [];
        foreach ($containers as $index => $container) {
            $geometry[] = self::geometry($index, $container);
        }
        $workOrder = [];
        foreach ($containers as $index => $container) {
            $workOrder[] = self::workOrderContainer(
                $index,
                $container,
                $plan['containers'][$index],
                $lengthUnit,
                $weightUnit
            );
        }
        $artifact = [
            'format' => self::FORMAT,
            'suite_version' => self::SUITE_VERSION,
            'provenance' => $provenance,
            'plan' => $plan,
            'geometry' => ['containers' => $geometry],
            'work_order' => [
                'length_unit' => $lengthUnit,
                'weight_unit' => $weightUnit,
                'containers' => $workOrder,
            ],
        ];
        // Refused here rather than when someone serializes it: an artifact that exists must
        // have one spelling in every engine.
        self::canonicalJson($artifact);
        return $artifact;
    }

    /**
     * Build from the request and result as JSON text.
     *
     * @param array<int,list<int>> $loadingOrders container index => engine-computed order
     * @return array<string,mixed>
     * @throws OperationalArtifactException `invalid_json` when either text does not parse
     */
    public static function fromJson(string $requestJson, string $resultJson, array $loadingOrders = []): array
    {
        return self::build(self::parse($requestJson), self::parse($resultJson), $loadingOrders);
    }

    /**
     * Decode JSON text -- a request, a result or a stored artifact -- with objects as
     * `stdClass`, the reading that keeps every object an object.
     *
     * @return mixed
     * @throws OperationalArtifactException `invalid_json`
     */
    public static function parse(string $json)
    {
        try {
            return \json_decode($json, false, self::JSON_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new OperationalArtifactException('invalid_json', 'not JSON text: ' . $error->getMessage(), $error);
        }
    }

    /**
     * The artifact's RFC 8785 canonical form, the bytes four engines are compared on.
     *
     * @param mixed $artifact
     * @throws OperationalArtifactException
     */
    public static function canonicalJson($artifact): string
    {
        try {
            return CanonicalJson::encode($artifact);
        } catch (CanonicalJsonException $error) {
            throw new OperationalArtifactException($error->errorCode(), $error->getMessage(), $error);
        }
    }

    /**
     * @param array<string,mixed>|\stdClass $request
     * @param array<string,mixed>|\stdClass $result
     * @param array<int,list<int>> $loadingOrders
     * @return array<string,mixed>
     */
    private static function plan($request, $result, array $loadingOrders): array
    {
        try {
            return Plan::build($request, $result, $loadingOrders);
        } catch (ExecutionPlanException $error) {
            throw new OperationalArtifactException('invalid_plan_input', $error->getMessage(), $error);
        }
    }

    // -------------------------------------------------------------------------- provenance

    /**
     * @param array<string,mixed>|\stdClass $request
     * @param array<string,mixed>|\stdClass $result
     * @return array<string,mixed>
     */
    private static function provenance($request, $result): array
    {
        $solver = self::solver(JsonValue::get($result, 'algorithm'));
        $catalogs = self::listOf(JsonValue::get($result, 'catalog_versions_used'), 'result.catalog_versions_used');
        foreach ($catalogs as $catalog) {
            self::catalog($catalog);
        }
        return [
            // Embedded, not digested: only the request itself lets someone replay the
            // artifact without a lookup.
            'request' => $request,
            'catalog_versions_used' => $catalogs,
            'solver' => $solver,
            'replay' => self::replay($solver),
        ];
    }

    /**
     * The result schema's closed catalog reference. The work order prints these fields, so a
     * mistyped one would print differently in every engine.
     *
     * @param mixed $catalog
     */
    private static function catalog($catalog): void
    {
        if (!\is_string(self::field($catalog, 'catalog_id', 'catalog_versions_used[]'))) {
            throw new OperationalArtifactException('invalid_result', 'catalog_versions_used[].catalog_id is not a string');
        }
        foreach (['version', 'effective_at', 'resolved_at'] as $name) {
            if (!\is_int(self::field($catalog, $name, 'catalog_versions_used[]'))) {
                throw new OperationalArtifactException('invalid_result', "catalog_versions_used[].{$name} is not an integer");
            }
        }
    }

    /**
     * @param mixed $algorithm
     * @return array<string,mixed>|null
     */
    private static function solver($algorithm): ?array
    {
        if ($algorithm === null) {
            return null;
        }
        if (!JsonValue::isObject($algorithm)) {
            throw new OperationalArtifactException('invalid_result', 'result.algorithm is not an object');
        }
        $missing = [];
        foreach (self::SOLVER_FIELDS as $field) {
            if (!JsonValue::has($algorithm, $field)) {
                $missing[] = $field;
            }
        }
        if ($missing !== []) {
            throw new OperationalArtifactException('invalid_result', 'result.algorithm has no ' . \implode(', ', $missing));
        }
        foreach (['time_limit_reached', 'effort_limit_reached'] as $flag) {
            if (!\is_bool(JsonValue::get($algorithm, $flag))) {
                throw new OperationalArtifactException('invalid_result', "result.algorithm.{$flag} is not a boolean");
            }
        }
        $solver = [];
        foreach (self::SOLVER_FIELDS as $field) {
            $solver[$field] = JsonValue::get($algorithm, $field);
        }
        return $solver;
    }

    /**
     * `exact` only when a replay must reproduce the result. A search stopped by wall-clock
     * time cannot be reproduced, and a result that does not say how it was solved cannot be
     * promised to; claiming otherwise would be softening a proof level by another name.
     *
     * @param array<string,mixed>|null $solver
     * @return array<string,string|null>
     */
    private static function replay(?array $solver): array
    {
        if ($solver === null) {
            return ['level' => 'not_guaranteed', 'because' => 'provenance.solver'];
        }
        if ($solver['time_limit_reached']) {
            return ['level' => 'not_guaranteed', 'because' => 'provenance.solver.time_limit_reached'];
        }
        return ['level' => 'exact', 'because' => null];
    }

    // ---------------------------------------------------------------------------- geometry

    /**
     * @param mixed $container
     * @return array<string,mixed>
     */
    private static function geometry(int $index, $container): array
    {
        $innerDimensions = self::tickDimensions(self::field($container, 'inner_dimensions', "containers[{$index}]"));
        $placements = [];
        foreach (self::placements($index, $container) as $placement) {
            $placements[] = [
                'placement' => Plan::placementReference($index, $placement),
                'dimensions' => self::tickDimensions(
                    self::field($placement, 'dimensions', "containers[{$index}].placements[]")
                ),
            ];
        }
        return [
            'container_index' => $index,
            'inner_dimensions' => $innerDimensions,
            'placements' => $placements,
        ];
    }

    /**
     * Lengths as decimal strings of ticks: the scene contract's spelling, and one no engine
     * has to hold as a native number.
     *
     * @param mixed $dimensions
     * @return array<string,string>
     */
    private static function tickDimensions($dimensions): array
    {
        $ticks = [];
        foreach (self::DIMENSION_AXES as $axis) {
            $ticks[$axis] = self::ticks(self::field($dimensions, $axis, 'dimensions'));
        }
        return $ticks;
    }

    /** @param mixed $scalar */
    private static function ticks($scalar): string
    {
        $ticks = self::field($scalar, 'ticks', 'exact scalar');
        if (!\is_int($ticks)) {
            throw new OperationalArtifactException('invalid_result', 'ticks is not an integer');
        }
        // Written as a string, so the canonical writer never sees it as a number; the range is
        // checked here instead, because JavaScript has already rounded such a value while parsing.
        if ($ticks > CanonicalJson::MAX_EXACT_MAGNITUDE || $ticks < -CanonicalJson::MAX_EXACT_MAGNITUDE) {
            throw new OperationalArtifactException('number_out_of_range', "ticks {$ticks} is beyond what every engine holds exactly");
        }
        return (string) $ticks;
    }

    // -------------------------------------------------------------------------- work order

    /**
     * The display units, read from the result rather than re-derived from request defaults:
     * the result already rendered every value in them.
     *
     * @param list<mixed> $containers
     * @return array{0:mixed,1:mixed}
     */
    private static function units(array $containers): array
    {
        if ($containers === []) {
            return [null, null];
        }
        $first = $containers[0];
        $inner = self::field($first, 'inner_dimensions', 'containers[0]');
        $length = self::field(self::field($inner, 'length', 'inner_dimensions'), 'unit', 'length');
        $weight = self::field(self::field($first, 'payload_weight', 'containers[0]'), 'unit', 'payload_weight');
        return [$length, $weight];
    }

    /**
     * One line per plan step, in plan step order: the order is the plan's, looked up, never
     * derived a second time.
     *
     * @param mixed $container
     * @param array<string,mixed> $planContainer
     * @param mixed $lengthUnit
     * @param mixed $weightUnit
     * @return array<string,mixed>
     */
    private static function workOrderContainer(int $index, $container, array $planContainer, $lengthUnit, $weightUnit): array
    {
        $byReference = self::placementsByReference($index, $container);
        $lines = [];
        foreach ($planContainer['steps'] as $step) {
            $placement = $byReference[self::referenceKey($step['placement'])];
            $line = [];
            if (\array_key_exists('sequence', $step)) {
                $line['sequence'] = $step['sequence'];
            }
            $position = self::field($placement, 'position', 'placement');
            $dimensions = self::field($placement, 'dimensions', 'placement');
            $line['placement'] = $step['placement'];
            $line['position'] = self::renderedValues($position, self::POSITION_AXES, 'position', $lengthUnit);
            $line['dimensions'] = self::renderedValues($dimensions, self::DIMENSION_AXES, 'dimensions', $lengthUnit);
            $lines[] = $line;
        }
        return [
            'container_index' => $index,
            'container_type' => JsonValue::get($container, 'container_type'),
            'payload_weight' => self::value(self::field($container, 'payload_weight', "containers[{$index}]"), $weightUnit),
            'gross_weight' => self::value(self::field($container, 'gross_weight', "containers[{$index}]"), $weightUnit),
            'lines' => $lines,
        ];
    }

    /**
     * @param mixed $values
     * @param list<string> $axes
     * @param mixed $unit
     * @return array<string,string>
     */
    private static function renderedValues($values, array $axes, string $where, $unit): array
    {
        $rendered = [];
        foreach ($axes as $axis) {
            $rendered[$axis] = self::value(self::field($values, $axis, $where), $unit);
        }
        return $rendered;
    }

    /**
     * @param mixed $container
     * @return array<string,mixed> reference key => placement
     */
    private static function placementsByReference(int $index, $container): array
    {
        $placements = self::placements($index, $container);
        $found = [];
        foreach ($placements as $placement) {
            $found[self::referenceKey(Plan::placementReference($index, $placement))] = $placement;
        }
        if (\count($found) !== \count($placements)) {
            throw new OperationalArtifactException(
                'invalid_result',
                "two placements in container {$index} share an origin, type and orientation"
            );
        }
        return $found;
    }

    /**
     * One string per (item type, orientation, x, y, z), the tuple the reference keys on.
     * Each leading part is self-delimiting, so no two tuples share a key, and the key never
     * looks numeric, so PHP keeps it a string.
     *
     * @param array<string,mixed> $reference
     */
    private static function referenceKey(array $reference): string
    {
        $ticks = $reference['position_ticks'];
        return self::keyPart($reference['item_type']) . self::keyPart($reference['orientation'])
            . $ticks['x'] . ',' . $ticks['y'] . ',' . $ticks['z'];
    }

    /** @param mixed $value */
    private static function keyPart($value): string
    {
        return \is_string($value) ? 's' . \strlen($value) . ':' . $value : 'v' . \serialize($value);
    }

    /**
     * The result's rendered value, copied and never re-rendered.
     *
     * @param mixed $scalar
     * @param mixed $unit
     */
    private static function value($scalar, $unit): string
    {
        if (self::field($scalar, 'unit', 'exact scalar') !== $unit) {
            throw new OperationalArtifactException('mixed_units', 'a value is in another unit than the result uses');
        }
        $value = self::field($scalar, 'value', 'exact scalar');
        if (!\is_string($value)) {
            throw new OperationalArtifactException('invalid_result', 'a rendered value is not a string');
        }
        return $value;
    }

    // ----------------------------------------------------------------------------- helpers

    /**
     * Every list entry the artifact and its plan read is an object, checked before either reads
     * one, so a malformed result is refused by name in every engine instead of failing
     * wherever each language first touches it.
     *
     * @param array<string,mixed>|\stdClass $result
     */
    private static function requireObjects($result): void
    {
        foreach (['containers', 'unpacked_items', 'alternatives', 'catalog_versions_used'] as $name) {
            foreach (self::listOf(JsonValue::get($result, $name), "result.{$name}") as $entry) {
                if (!JsonValue::isObject($entry)) {
                    throw new OperationalArtifactException('invalid_result', "result.{$name} holds a non-object entry");
                }
            }
        }
        foreach (self::listOf(JsonValue::get($result, 'containers'), 'result.containers') as $index => $container) {
            foreach (self::placements($index, $container) as $placement) {
                if (!JsonValue::isObject($placement)) {
                    throw new OperationalArtifactException(
                        'invalid_result',
                        "containers[{$index}].placements holds a non-object entry"
                    );
                }
            }
        }
    }

    /**
     * @param mixed $container
     * @return list<mixed>
     */
    private static function placements(int $index, $container): array
    {
        return self::listOf(JsonValue::get($container, 'placements'), "containers[{$index}].placements");
    }

    /**
     * @param mixed $value
     * @return list<mixed>
     */
    private static function listOf($value, string $where): array
    {
        if ($value === null) {
            return [];
        }
        if (!JsonValue::isList($value)) {
            throw new OperationalArtifactException('invalid_result', "{$where} is not a list");
        }
        return $value;
    }

    /**
     * @param mixed $object
     * @return mixed
     */
    private static function field($object, string $name, string $where)
    {
        if (!JsonValue::has($object, $name)) {
            throw new OperationalArtifactException('invalid_result', "{$where} has no {$name}");
        }
        return JsonValue::get($object, $name);
    }
}
