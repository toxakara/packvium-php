<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

use Packvium\Validation\MalformedResult;
use Packvium\Validation\ResultIssue;

/**
 * Compact lattice results, expanded independently before ordinary validation. The summary is
 * untrusted: its parameters must agree with the request before they are used to synthesize
 * placements, which then pass through the same checks as explicit ones.
 */
final class Lattice
{
    private const SIDES = ['length', 'width', 'height'];

    /**
     * @param mixed $result
     * @param Ordered<ItemSpec> $items @param Ordered<ContainerSpec> $containers
     * @param int|string $clearance @param list<ResultIssue> $issues
     * @return mixed the result with every valid summary expanded into placements
     */
    public static function materialize(bool $coordinatesRequired, $result, Ordered $items, Ordered $containers, $clearance, array &$issues)
    {
        $packedContainers = Json::items(Json::at($result, 'containers'), 'result.containers');
        $summarized = false;
        foreach ($packedContainers as $packed) {
            $summarized = $summarized || Json::has($packed, 'lattice_summary');
        }
        if (!$summarized) {
            return $result;
        }
        foreach ($packedContainers as $index => $packed) {
            $placements = self::expand($packed, $coordinatesRequired, $items, $containers, $clearance, $issues);
            if ($placements !== null) {
                $packedContainers[$index]['placements'] = $placements;
            }
        }
        $result['containers'] = $packedContainers;
        return $result;
    }

    /**
     * @param mixed $packed @param Ordered<ItemSpec> $items @param Ordered<ContainerSpec> $containers
     * @param int|string $clearance @param list<ResultIssue> $issues
     * @return list<mixed>|null the placements to write into the container, or null to leave it
     */
    private static function expand($packed, bool $coordinatesRequired, Ordered $items, Ordered $containers, $clearance, array &$issues): ?array
    {
        $summary = Json::get($packed, 'lattice_summary');
        if ($summary === null) {
            return null;
        }
        $containerId = PyFormat::str(Json::get($packed, 'id', '?'));
        if ($coordinatesRequired) {
            $issues[] = new ResultIssue('unexpected_lattice_summary', "{$containerId}: request requires explicit placement coordinates");
        }
        if (Json::truthy(Json::get($packed, 'placements'))) {
            $issues[] = new ResultIssue('lattice_placements_conflict', "{$containerId}: compact summary and explicit placements are mutually exclusive");
        }
        $itemType = Json::get($summary, 'item_type');
        $item = $items->get($itemType);
        $container = $containers->get(Json::get($packed, 'container_type'));
        if ($item === null || $container === null) {
            // The ordinary accounting and container checks report the unknown reference.
            return null;
        }
        $fields = self::fields($summary, $containerId);
        $problems = self::problems($fields, $item, $itemType, $container, $clearance);
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $issues[] = new ResultIssue('lattice_summary_mismatch', "{$containerId}: {$problem}");
            }
            return [];
        }
        return self::synthesize($fields, $summary, $itemType, $containerId, $clearance);
    }

    /** @param mixed $summary @return array<string,mixed> */
    private static function fields($summary, string $containerId): array
    {
        try {
            $sides = static function (string $key) use ($summary): array {
                $block = Json::at($summary, $key);
                return \array_map(static function (string $side) use ($block) {
                    return Json::at(Json::at($block, $side), 'ticks');
                }, self::SIDES);
            };
            return [
                'orientation' => Json::at($summary, 'orientation'),
                'physical' => $sides('physical_dimensions'),
                'envelope' => $sides('envelope_dimensions'),
                'nx' => Json::at($summary, 'nx'),
                'ny' => Json::at($summary, 'ny'),
                'layers_used' => Json::at($summary, 'layers_used'),
                'layer_step' => Json::at(Json::at($summary, 'layer_step'), 'ticks'),
                'count' => Json::at($summary, 'count'),
            ];
        } catch (MalformedResult $missing) {
            // The reference reports a missing field as a contract break, naming the container.
            throw MalformedResult::contract("{$containerId}: lattice_summary is missing a canonical field: {$missing->getMessage()}");
        }
    }

    /** @param array<string,mixed> $fields @param mixed $itemType @param int|string $clearance @return list<string> */
    private static function problems(array $fields, ItemSpec $item, $itemType, ContainerSpec $container, $clearance): array
    {
        $problems = [];
        $orientation = $fields['orientation'];
        if (!Geometry::isRotation($orientation)) {
            $problems[] = 'unknown orientation ' . PyFormat::repr($orientation);
        } else {
            $expected = $item->rotated($orientation);
            if (!self::matches($fields['physical'], $expected)) {
                $problems[] = 'physical dimensions ' . PyFormat::valueTuple($fields['physical']) . ' != rotated item ' . PyFormat::intTuple($expected);
            }
            if (!$item->allows($orientation)) {
                $problems[] = "orientation {$orientation} is forbidden for " . PyFormat::str($itemType);
            }
        }
        $margin = Exact::mul(2, $clearance);
        $expectedEnvelope = [];
        foreach ($fields['physical'] as $side) {
            $expectedEnvelope[] = Exact::add(Json::int($side, 'physical dimension'), $margin);
        }
        if (!self::matches($fields['envelope'], $expectedEnvelope)) {
            $problems[] = 'envelope dimensions ' . PyFormat::valueTuple($fields['envelope']) . ' != physical+clearance ' . PyFormat::intTuple($expectedEnvelope);
        }
        if ($item->nestingHeight !== null) {
            $problems[] = 'compact lattice form is not valid for nesting items';
        }
        if (!Json::equals($fields['layer_step'], $fields['envelope'][2])) {
            $problems[] = 'layer_step ' . PyFormat::str($fields['layer_step']) . ' != envelope height ' . PyFormat::str($fields['envelope'][2]);
        }
        foreach (['nx', 'ny', 'layers_used', 'layer_step', 'count'] as $name) {
            $value = Json::strictInt($fields[$name]);
            if ($value === null || $value < 1) {
                $problems[] = "{$name} must be a positive integer, got " . PyFormat::repr($fields[$name]);
            }
        }
        if ($problems === []) {
            self::independentCounts($fields, $item, $container, $problems);
        }
        return $problems;
    }

    /** Reached only once every field is a positive integer and the envelope is the expected one. @param array<string,mixed> $fields @param list<string> $problems */
    private static function independentCounts(array $fields, ItemSpec $item, ContainerSpec $container, array &$problems): void
    {
        $nx = $fields['nx'];
        $ny = $fields['ny'];
        $layersUsed = $fields['layers_used'];
        $layerStep = $fields['layer_step'];
        $count = $fields['count'];
        $expectedNx = Exact::floorDiv($container->inner[0], $fields['envelope'][0]);
        $expectedNy = Exact::floorDiv($container->inner[1], $fields['envelope'][1]);
        $expectedLayers = Exact::ceilDiv($count, Exact::mul($nx, $ny));
        if (Exact::compare($nx, $expectedNx) !== 0) {
            $problems[] = "nx {$nx} != independently computed {$expectedNx}";
        }
        if (Exact::compare($ny, $expectedNy) !== 0) {
            $problems[] = "ny {$ny} != independently computed {$expectedNy}";
        }
        if (Exact::compare($layersUsed, $expectedLayers) !== 0) {
            $problems[] = "layers_used {$layersUsed} != independently computed {$expectedLayers}";
        }
        if ($count > Json::int($item->quantity, 'quantity')) {
            $problems[] = "count {$count} exceeds requested quantity " . PyFormat::str($item->quantity);
        }
        $capacity = Exact::floorDiv($container->inner[2], $layerStep);
        if (Exact::compare($layersUsed, $capacity) > 0) {
            $problems[] = "layers_used {$layersUsed} exceeds vertical capacity {$capacity}";
        }
    }

    /** @param array<string,mixed> $fields @param mixed $summary @param mixed $itemType @param int|string $clearance @return list<mixed> */
    private static function synthesize(array $fields, $summary, $itemType, string $containerId, $clearance): array
    {
        $nx = $fields['nx'];
        $ny = $fields['ny'];
        $perLayer = $nx * $ny;
        $physical = Json::at($summary, 'physical_dimensions');
        $tick = static function ($value): array { return ['ticks' => $value]; };
        $placements = [];
        for ($index = 0; $index < $fields['count']; ++$index) {
            $placements[] = [
                'item_id' => PyFormat::str($itemType) . "#lattice:{$containerId}:" . ($index + 1),
                'item_type' => $itemType,
                'orientation' => $fields['orientation'],
                'position' => [
                    'x' => $tick(Exact::add(Exact::mul($index % $nx, $fields['envelope'][0]), $clearance)),
                    'y' => $tick(Exact::add(Exact::mul(\intdiv($index, $nx) % $ny, $fields['envelope'][1]), $clearance)),
                    'z' => $tick(Exact::add(Exact::mul(\intdiv($index, $perLayer), $fields['layer_step']), $clearance)),
                ],
                'dimensions' => $physical,
            ];
        }
        return $placements;
    }

    /** Element-wise `reported == expected` for a tuple of JSON values. @param list<mixed> $reported @param list<int|string> $expected */
    private static function matches(array $reported, array $expected): bool
    {
        foreach ($expected as $index => $value) {
            if (!Json::equalsInt($reported[$index], $value)) {
                return false;
            }
        }
        return true;
    }
}
