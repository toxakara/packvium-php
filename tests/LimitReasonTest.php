<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Serialization\ArrayCodec;

/**
 * One reason code per container, axle and stop limit, and: a
 * restart budget that changes the plan. Mirrors
 * `packvium-python/tests/test_limit_reasons.py`; the same scenes are pinned across all four
 * engines in `conformance/unpacked-reasons/`.
 */
final class LimitReasonTest extends TestCase
{
    private const INVENTORY = [['container_inventory_exhausted', 'inferred']];

    public static function testALimitAFreshContainerWouldLiftIsTheInventory(): void
    {
        $cases = [
            'quantity' => [[self::cube('cube', 2)], [self::box('10', '10', '10', ['quantity' => 1])], []],
            'max_containers' => [[self::cube('cube', 2)], [self::box('10', '10', '10')], ['max_containers' => 1]],
            'max_items' => [[self::cube('cube', 2)], [self::box('20', '20', '20', ['quantity' => 1, 'max_items' => 1])], []],
            'max_payload' => [[self::cube('cube', 2, '10', ['weight' => self::grams('600')])], [self::box('20', '20', '20', ['quantity' => 1, 'max_payload' => self::grams('1000')])], []],
            'tag_limits' => [[self::cube('hazmat', 2, '10', ['tags' => ['hazmat']])], [self::box('20', '20', '20', ['quantity' => 1, 'tag_limits' => ['hazmat' => 1]])], []],
            'stop_access' => [[self::cube('early', 1, '100', ['stop_index' => 0]), self::cube('late', 1, '100', ['stop_index' => 1, 'stackable' => false])], [self::box('100', '100', '200', ['quantity' => 1, 'access_directions' => ['+z']])], []],
            'axle_share' => [[self::cube('payload', 2, '10', ['weight' => self::grams('500'), 'allowed_rotations' => ['LWH']])], [self::box('100', '10', '10', ['quantity' => 1, 'axles' => self::axles('600', '350')])], []],
        ];
        foreach ($cases as $name => [$items, $containers, $configuration]) {
            self::assertSame(self::INVENTORY, self::reasons($items, $containers, $configuration), $name);
        }
        self::assertSame([], self::reasons([self::cube('cube', 2)], [self::box('20', '20', '20', ['max_items' => 1])]));
    }

    public static function testAnAxleThatRefusesTheItemAloneIsNamed(): void
    {
        foreach ([['10', '400', '400'], ['100', '400', '700']] as [$length, $front, $rear]) {
            $item = ['id' => 'load', 'quantity' => 1, 'weight' => self::grams('1000'), 'allowed_rotations' => ['LWH'],
                     'dimensions' => ['length' => $length, 'width' => '10', 'height' => '10']];
            $vehicle = self::box('100', '10', '10', ['axles' => self::axles($front, $rear)]);
            self::assertSame([['axle_overloaded', 'inferred']], self::reasons([$item], [$vehicle]), $length);
        }
        $item = self::cube('load', 2, '10', ['weight' => self::grams('1000'), 'allowed_rotations' => ['LWH']]);
        $crate = ['id' => 'crate'] + self::box('10', '10', '10', ['quantity' => 1]);
        $vehicle = self::box('100', '10', '10', ['axles' => self::axles('400', '400')]);
        self::assertSame(self::INVENTORY, self::reasons([$item], [$vehicle, $crate]));
    }

    public static function testASingleRestartPacksWorseThanTheFullPortfolio(): void
    {
        // The shared conformance scene feature-effort-restarts-first-start-loses.
        $items = [
            ['id' => 'panel', 'quantity' => 4, 'dimensions' => ['length' => '20', 'width' => '25', 'height' => '50']],
            ['id' => 'crate', 'quantity' => 4, 'dimensions' => ['length' => '60', 'width' => '40', 'height' => '40']],
        ];
        $budget = ['max_candidates_evaluated' => 1000000, 'max_placement_attempts' => 1000000, 'max_search_nodes' => 1000000];
        foreach ([[1, 4], [null, 3]] as [$restarts, $unpacked]) {
            $effort = $restarts === null ? $budget : $budget + ['max_restarts' => $restarts];
            $found = self::reasons($items, [self::box('50', '100', '60', ['quantity' => 1])], ['solver_profile' => 'balanced', 'effort_budget' => $effort]);
            self::assertSame($unpacked, count($found), (string)$restarts);
        }
    }

    /** @return list<array{0:string,1:string}> */
    private static function reasons(array $items, array $containers, array $configuration = []): array
    {
        $result = ArrayCodec::pack([
            'units' => ['length' => 'mm'],
            'configuration' => ['solver_profile' => 'fast', 'time_limit_ms' => 300000] + $configuration,
            'items' => $items,
            'containers' => $containers,
        ]);
        $reasons = array_map(static fn(array $item): array => [$item['reason'], $item['proof']['level']], $result['unpacked_items']);
        sort($reasons);
        return $reasons;
    }

    private static function cube(string $id, int $quantity, string $side = '10', array $fields = []): array
    {
        return ['id' => $id, 'quantity' => $quantity, 'dimensions' => ['length' => $side, 'width' => $side, 'height' => $side]] + $fields;
    }

    private static function box(string $length, string $width, string $height, array $fields = []): array
    {
        return ['id' => 'box', 'inner_dimensions' => ['length' => $length, 'width' => $width, 'height' => $height]] + $fields;
    }

    private static function grams(string $value): array
    {
        return ['value' => $value, 'unit' => 'g'];
    }

    private static function axles(string $front, string $rear): array
    {
        return [['position' => '0', 'max_load' => self::grams($front)], ['position' => '100', 'max_load' => self::grams($rear)]];
    }
}
