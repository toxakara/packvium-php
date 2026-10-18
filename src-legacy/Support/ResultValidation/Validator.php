<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

use Packvium\Validation\MalformedResult;
use Packvium\Validation\ResultIssue;

/**
 * A port of the reference validator, `packvium.validate` (packvium-python's
 * `result_validation.py`), in the reference's order of checks. It shares no code with the PHP
 * engine: units, geometry, support and load are recomputed here from the request and the reported
 * placements, and only the package's integer primitives (`Support\BigInt`, `SignedBigInt`,
 * `ShortestFloat`) are reused, as the reference reuses Python's `int`.
 */
final class Validator
{
    private const REQUIRED_KEYS = [
        'status', 'feasibility', 'termination', 'optimality', 'complete', 'algorithm', 'summary', 'containers',
        'unpacked_items', 'score',
    ];

    /** @param mixed $request @param mixed $result @return list<ResultIssue> */
    public static function validate($request, $result): array
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (!Json::has($result, $key)) {
                throw MalformedResult::contract('result is missing ' . PyFormat::stringRepr($key));
            }
        }
        $unit = Json::text(Json::get(Json::get($request, 'units', []), 'length', 'mm'), 'units.length');
        $configuration = Json::get($request, 'configuration', []);
        $clearance = Units::toTicks(Json::get($configuration, 'clearance', 0), $unit, Units::LENGTH);
        $globalSupport = Units::ratioOf(Json::get($configuration, 'minimum_support_ratio', 0));
        $items = Spec::items($request, $unit);
        $containers = Spec::containers($request, $unit);

        $issues = [];
        $validated = Lattice::materialize($request, $result, $items, $containers, $clearance, $issues);
        Accounting::checkStructure($validated, $issues);
        Facts::checkAlgorithmMetrics($validated, $issues);
        Facts::checkEffortBudget($request, $validated, $issues);
        Facts::checkResultFacts($validated, $issues);
        $context = new Context($items, $containers, $clearance, Accounting::containerTagRules($request));
        Accounting::checkAccounting($validated, $context, $issues);
        self::checkInfeasibleProof($validated, $issues);

        $fabricated = self::checkContainers($validated, $result, $context, $globalSupport, $issues);
        self::checkContainerCounts($validated, $configuration, $containers, $issues);

        ObjectiveCheck::checkFixedPlacements($request, $validated, $unit, $issues);
        ObjectiveCheck::checkGroups($request, $validated, $issues);
        ObjectiveCheck::checkSolverSelection($request, $validated, $issues);
        ObjectiveCheck::check($request, $validated, $context, $fabricated, $issues);
        return $issues;
    }

    /** @param mixed $result @param list<ResultIssue> $issues */
    private static function checkInfeasibleProof($result, array &$issues): void
    {
        if (!Json::isText(Json::at($result, 'status'), 'infeasible')) {
            return;
        }
        foreach (Json::items(Json::at($result, 'unpacked_items'), 'result.unpacked_items') as $unpacked) {
            if (!Json::isText(Json::get(Json::get($unpacked, 'proof', []), 'level'), 'proven')) {
                $issues[] = new ResultIssue('unproven_infeasible', 'infeasible status requires a proven rejection for every unpacked item');
                return;
            }
        }
    }

    /**
     * Each packed container against its type, recomputing a made-to-measure box first; returns
     * those recomputed boxes by container id, for the objective.
     *
     * @param mixed $validated @param mixed $reported @param list<ResultIssue> $issues @return Ordered<ContainerSpec>
     */
    private static function checkContainers($validated, $reported, Context $context, Ratio $globalSupport, array &$issues): Ordered
    {
        $fabricated = new Ordered();
        $originals = Json::items(Json::at($reported, 'containers'), 'result.containers');
        foreach (Json::items(Json::at($validated, 'containers'), 'result.containers') as $index => $packed) {
            if (!\array_key_exists($index, $originals)) {
                break;
            }
            $containerType = Json::at($packed, 'container_type');
            $spec = $context->containers->get($containerType);
            if ($spec === null) {
                $issues[] = new ResultIssue('unknown_container_type', PyFormat::str($containerType));
                continue;
            }
            $spec = ContainerCheck::checkMadeToMeasure($packed, Json::has($originals[$index], 'lattice_summary'), $spec, $context->items, $context->clearance, $issues);
            if ($spec->madeToMeasure !== null) {
                $fabricated->set(Json::at($packed, 'id'), $spec);
            }
            ContainerCheck::check($packed, $spec, $context->items, $context->clearance, $globalSupport, $issues);
            ContainerCheck::checkAchievedExtent($packed, $spec, $context->clearance, $issues);
        }
        return $fabricated;
    }

    /** @param mixed $validated @param mixed $configuration @param Ordered<ContainerSpec> $containers @param list<ResultIssue> $issues */
    private static function checkContainerCounts($validated, $configuration, Ordered $containers, array &$issues): void
    {
        $opened = Json::items(Json::at($validated, 'containers'), 'result.containers');
        $inventory = new Ordered();
        foreach ($opened as $packed) {
            $containerType = Json::at($packed, 'container_type');
            if ($containers->has($containerType)) {
                $inventory->set($containerType, $inventory->get($containerType, 0) + 1);
            }
        }
        foreach ($inventory->entries() as [$containerType, $count]) {
            $quantity = $containers->get($containerType)->quantity;
            if ($quantity !== null && $count > $quantity) {
                $issues[] = new ResultIssue('container_inventory_exceeded', PyFormat::str($containerType) . ": used {$count}, available {$quantity}");
            }
        }
        $limit = Json::optionalInt(Json::get($configuration, 'max_containers'), 'max_containers');
        if ($limit !== null && \count($opened) > $limit) {
            $issues[] = new ResultIssue('max_containers_exceeded', \count($opened) . " containers opened, limit {$limit}");
        }
    }
}
