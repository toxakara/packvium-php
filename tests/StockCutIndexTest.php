<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Revisions\PlanRevision;
use Packvium\Revisions\PlanRevisionException;
use Packvium\Artifacts\OperationalArtifact;
use Packvium\Serialization\ArrayCodec;

final class StockCutIndexTest extends TestCase
{
    public static function testReplacementAndLockRefuseUnreadableStockFields(): void
    {
        $sides = ['length' => '10', 'width' => '10', 'height' => '10'];
        $request = ['items' => [['id' => 'cube', 'dimensions' => $sides]], 'containers' => [
            ['id' => 'machine', 'inner_dimensions' => $sides, 'made_to_measure' => ['minimum' => $sides, 'increment' => $sides]],
        ]];
        $artifact = OperationalArtifact::build($request, ArrayCodec::pack($request));
        foreach ([['made_to_measure', true, 'made_to_measure is not an object'],
            ['cost_minor', 'price', 'cost_minor is not an integer'], ['tare_weight', 'heavy', 'tare_weight is not a measure']] as [$field, $value, $detail]) {
            $replacement = $request['containers'][0];
            $replacement[$field] = $value;
            $events = [['sequence' => 1, 'type' => 'container_substituted', 'container_type' => 'machine', 'replacement' => $replacement],
                ['sequence' => 2, 'type' => 'placement_locked', 'placement' => ['item_type' => 'cube', 'container_type' => 'machine', 'orientation' => 'LWH']]];
            try {
                PlanRevision::derive(PlanRevision::root($request), $artifact, $events);
                self::assertTrue(false, 'an unreadable stock field was accepted');
            } catch (PlanRevisionException $error) {
                self::assertSame('invalid_revision', $error->errorCode());
                self::assertSame("a made-to-measure container's {$detail}", $error->getMessage());
            }
        }
        $revision = PlanRevision::derive(PlanRevision::root($request), $artifact, [['sequence' => 1, 'type' => 'placement_locked',
            'placement' => ['item_type' => 'cube', 'container_type' => 'machine', 'orientation' => 'LWH', 'position' => ['x' => '999999']]]]);
        self::assertSame('999999', $revision->request->fixed_placements[0]->position->x);
        foreach ([[[], 'units is not an object'], [['length' => []], 'units.length is not a string']] as [$units, $detail]) {
            $fixed = $request;
            $fixed['units'] = $units;
            $fixed['fixed_placements'] = [['container_type' => 'machine']];
            try {
                PlanRevision::stockCutBoxes($fixed, $artifact, 1);
                self::assertTrue(false, 'an unreadable measure context was accepted');
            } catch (PlanRevisionException $error) {
                self::assertSame('invalid_revision', $error->errorCode());
                self::assertSame("a made-to-measure container's {$detail}", $error->getMessage());
            }
        }
        foreach ([null, 0, 1, 1.0, -1] as $cost) {
            $fixed = $request;
            $fixed['containers'][0]['cost_minor'] = $cost;
            $fixed['fixed_placements'] = [['container_type' => 'machine']];
            self::assertSame((int) ($cost ?? 0), PlanRevision::stockCutBoxes($fixed, $artifact, 1)->containers[1]->cost_minor);
        }
    }

    public static function testStockingKeepsFirstLockOrder(): void
    {
        $fixed = $orders = $shapes = [];
        for ($i = 100; $i >= 1; --$i) {
            $fixed[] = ['container_type' => 'machine', 'container_instance' => $i];
        }
        for ($i = 1; $i <= 100; ++$i) {
            $orders[] = ['container_type' => 'machine'];
            $shapes[] = ['inner_dimensions' => ['length' => (string) $i, 'width' => '2', 'height' => '3']];
        }
        $request = ['containers' => [['id' => 'machine', 'quantity' => 100, 'made_to_measure' => (object) []]], 'fixed_placements' => $fixed];
        $artifact = ['work_order' => ['containers' => $orders], 'geometry' => ['containers' => $shapes]];
        $stocked = PlanRevision::stockCutBoxes($request, $artifact, 7);
        self::assertSame(100, count($stocked->containers));
        self::assertSame('machine@7#100', $stocked->containers[0]->id);
        self::assertSame('machine@7#1', $stocked->containers[99]->id);
        self::assertSame('100', $stocked->containers[0]->inner_dimensions->length->value);
        self::assertSame('1', $stocked->containers[99]->inner_dimensions->length->value);
    }

    public static function testStockingJoinsTypesAndLeavesUnselectedMalformedGeometryUnread(): void
    {
        $request = ['containers' => [
            ['id' => 'machine', 'made_to_measure' => (object) [], 'cost_minor' => 1],
            ['id' => 'machine', 'made_to_measure' => (object) [], 'cost_minor' => 9],
        ], 'fixed_placements' => [['container_type' => 'machine', 'container_instance' => 2]]];
        $artifact = ['work_order' => ['containers' => [
            ['container_type' => 'machine'], ['container_type' => 'other'],
            ['container_type' => 'machine'], ['container_type' => 'machine'],
        ]], 'geometry' => ['containers' => [null, null, ['inner_dimensions' => ['length' => '1', 'width' => '2', 'height' => '3']]]]];
        $stocked = PlanRevision::stockCutBoxes($request, $artifact, 1);
        self::assertSame(9, $stocked->containers[1]->cost_minor);
        self::assertSame('1', $stocked->containers[1]->inner_dimensions->length->value);
    }
}
