<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Algorithm\WeightRebalancer;
use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container, Dimensions, FixedPlacement, Item, PackingRequest, Point, Rotation};
use Packvium\Packer;
use Packvium\Serialization\ArrayCodec;
use Packvium\Unit\Length;
use Packvium\Validation\{FixedPlacementException, IndependentSolutionValidator};

/**
 * Fixed placements: items already in a known place before the solve (docs/PLAN-REVISIONS.md).
 * Mirrors `packvium-python/tests/test_fixed_placements.py`.
 */
final class FixedPlacementTest extends TestCase
{
    public static function testAFixedItemIsReportedWhereTheRequestPutItInEveryProfile(): void
    {
        foreach (['fast', 'balanced', 'quality', 'exact_small'] as $profile) {
            $result = ArrayCodec::pack(self::request(null, null, null, ['solver_profile' => $profile]));
            self::assertContains($result['status'], ['feasible', 'optimal'], $profile);
            self::assertSame([['box#1', 'cube#1', '100', '0', '0']], self::fixedRows($result), $profile);
            self::assertSame(5, self::placed($result), $profile);
        }
    }

    public static function testOnlyFixedPlacementsCarryTheFlag(): void
    {
        $result = ArrayCodec::pack(self::request());
        $flags = [];
        foreach ($result['containers'] as $container) {
            foreach ($container['placements'] as $placement) {
                $flags[] = array_key_exists('fixed', $placement) ? $placement['fixed'] : null;
            }
        }
        self::assertSame(1, count(array_filter($flags, static fn($flag): bool => $flag === true)));
        self::assertSame(4, count(array_filter($flags, static fn($flag): bool => $flag === null)));
    }

    public static function testFixedItemsTakeTheFirstInstancesInListedOrder(): void
    {
        $result = ArrayCodec::pack(self::request(null, null, [self::fixed('100'), self::fixed('0')]));
        self::assertSame([['box#1', 'cube#1', '100', '0', '0'], ['box#1', 'cube#2', '0', '0', '0']], self::fixedRows($result));
    }

    public static function testFixedContainersOpenFirstAndFreeOnesAreNumberedAfterThem(): void
    {
        $result = ArrayCodec::pack(self::request([self::cube(9)], null, [self::fixed(), self::fixed('0', '0', '0', 2)]));
        self::assertSame(['box#1', 'box#2', 'box#3'], array_map(static fn(array $c): string => $c['id'], $result['containers']));
    }

    public static function testAFixedContainerIsKeptWhenNoFreeItemFitsInIt(): void
    {
        $tray = ['id' => 'tray', 'quantity' => 1, 'inner_dimensions' => ['length' => '100', 'width' => '100', 'height' => '100']];
        $result = ArrayCodec::pack(self::request([self::cube(3)], [$tray, self::box()], [array_replace(self::fixed(), ['container_type' => 'tray'])]));
        self::assertSame('tray#1', $result['containers'][0]['id']);
        self::assertSame(['cube#1'], array_map(static fn(array $p): string => $p['item_id'], $result['containers'][0]['placements']));
    }

    public static function testAFixedContainerSurvivesASearchThatRunsOutOfEffort(): void
    {
        $result = ArrayCodec::pack(self::request([self::cube(40)], [self::box(20)], [self::fixed(), self::fixed('0', '0', '0', 2)],
            ['effort_budget' => ['max_search_nodes' => 1]]));
        self::assertSame(['box#1', 'box#2'], array_values(array_unique(array_map(static fn(array $row): string => $row[0], self::fixedRows($result)))));
        self::assertSame([], $result['warnings']);
    }

    public static function testAFreeItemRestingOnAFixedOneLoadsIt(): void
    {
        $box = self::box(1);
        $box['inner_dimensions']['length'] = '100';
        $result = ArrayCodec::pack(self::request([self::cube(2)], [$box], [self::fixed()], ['solvers' => ['extreme_points']]));
        $byId = [];
        foreach ($result['containers'][0]['placements'] as $placement) {
            $byId[$placement['item_id']] = $placement;
        }
        self::assertSame('100', $byId['cube#2']['position']['z']['value']);
        self::assertSame('1000', $byId['cube#1']['top_load']['value']);
    }

    public static function testEverySolverPacksAroundFixedItems(): void
    {
        foreach (['grid', 'homogeneous_blocks', 'maximal_spaces', 'layer'] as $solver) {
            $result = ArrayCodec::pack(self::request(null, null, null, ['solvers' => [$solver]]));
            self::assertSame([['box#1', 'cube#1', '100', '0', '0']], self::fixedRows($result), $solver);
            self::assertSame([], $result['warnings'], $solver);
            self::assertSame(5, self::placed($result), $solver);
        }
    }

    public static function testTheLatticeStillServesContainersWithoutFixedItems(): void
    {
        $result = ArrayCodec::pack(self::request([self::cube(12)], [self::box(4)], null,
            ['solver_profile' => 'fast', 'require_placement_coordinates' => false]));
        self::assertTrue($result['containers'][0]['placements'][0]['fixed']);
        self::assertTrue(array_key_exists('lattice_summary', $result['containers'][1]));
    }

    public static function testAFixedSetThatCannotHoldIsRefusedBeforeSearch(): void
    {
        $cases = [
            'collision' => static function (array &$d): void { $d['fixed_placements'][] = self::fixed('100'); },
            'unknown item type' => static function (array &$d): void { $d['fixed_placements'][0]['item_type'] = 'crate'; },
            'unknown container type' => static function (array &$d): void { $d['fixed_placements'][0]['container_type'] = 'crate'; },
            'not numbered 1..1' => static function (array &$d): void { $d['fixed_placements'][0]['container_instance'] = 2; },
            'outside_container' => static function (array &$d): void { $d['fixed_placements'][0]['position']['x'] = '150'; },
            '2 cube fixed, 1 requested' => static function (array &$d): void { $d['items'][0]['quantity'] = 1; $d['fixed_placements'][] = self::fixed(); },
            'orientation HWL' => static function (array &$d): void { $d['items'][0]['keep_upright'] = true; $d['fixed_placements'][0]['orientation'] = 'HWL'; },
            'obstacle_collision' => static function (array &$d): void { $d['containers'][0]['obstacles'] = [['id' => 'p', 'origin' => ['x' => '150'], 'dimensions' => ['length' => '10', 'width' => '10', 'height' => '10']]]; },
            'payload_exceeded' => static function (array &$d): void { $d['containers'][0]['max_payload'] = '500'; },
            'outside_container: cube#1' => static function (array &$d): void { $d['configuration'] = ['clearance' => '1']; },
            'insufficient_support' => static function (array &$d): void { $d['configuration'] = ['minimum_support_ratio' => 1]; $d['fixed_placements'][0]['position']['z'] = '50'; },
            '2 box named, 1 available' => static function (array &$d): void { $d['containers'][0]['quantity'] = 1; $d['fixed_placements'][] = self::fixed('0', '0', '0', 2); },
            'max_containers is 1' => static function (array &$d): void { $d['configuration'] = ['max_containers' => 1]; $d['fixed_placements'][] = self::fixed('0', '0', '0', 2); },
        ];
        foreach ($cases as $fragment => $mutate) {
            $data = self::request();
            $mutate($data);
            $message = null;
            try {
                ArrayCodec::pack($data);
            } catch (FixedPlacementException $error) {
                $message = $error->getMessage();
            }
            self::assertNotNull($message, $fragment);
            self::assertTrue(str_starts_with($message, 'invalid_fixed_placement: '), $message);
            self::assertTrue(str_contains($message, $fragment), "{$fragment}: {$message}");
        }
    }

    public static function testAFixedPlacementOutOfShapeIsRefusedBeforeAnythingIsCoerced(): void
    {
        $entry = self::fixed('100');
        unset($entry['container_instance']);
        $cases = [
            ['fixed_placements is a list', 'x'],
            ['fixed_placements is a list', ['a' => $entry]],
            ['fixed_placements[0] is an object', [5]],
            ['fixed_placements[1] is an object', [$entry, [1, 2]]],
            ['fixed_placements[0] needs ["item_type","container_type","orientation"]', [[]]],
            ['fixed_placements[0] does not carry ["5","note"]', [$entry + ['note' => 'strapped', 5 => 1]]],
            ['fixed_placements[0] needs ["orientation"]', [array_diff_key($entry, ['orientation' => 0])]],
            ['fixed_placements[0].item_type is a non-empty string', [array_replace($entry, ['item_type' => ''])]],
            ['fixed_placements[0].container_type is a non-empty string', [array_replace($entry, ['container_type' => 5])]],
            ['fixed_placements[0].orientation is one of the six codes', [array_replace($entry, ['orientation' => 'lwh'])]],
            ['fixed_placements[0].container_instance counts from 1', [array_replace($entry, ['container_instance' => '1'])]],
            ['fixed_placements[0].container_instance counts from 1', [array_replace($entry, ['container_instance' => true])]],
            ['fixed_placements[0].container_instance counts from 1', [array_replace($entry, ['container_instance' => 1.9])]],
            ['fixed_placements[0].container_instance counts from 1', [array_replace($entry, ['container_instance' => 2 ** 53])]],
            ['fixed_placements[0].container_instance counts from 1', [array_replace($entry, ['container_instance' => 0])]],
            ['fixed_placements[0].position is a point object', [array_replace($entry, ['position' => ['100', '0', '0']])]],
            ['fixed_placements[0].position is a point object', [array_replace($entry, ['position' => null])]],
            ['fixed_placements[0].position is a point object', [array_replace($entry, ['position' => '100'])]],
            ['fixed_placements[0].position does not carry ["w"]', [array_replace($entry, ['position' => ['x' => '100', 'w' => '5']])]],
            ['fixed_placements[0].position.x is a measure', [array_replace($entry, ['position' => ['x' => true]])]],
            ['fixed_placements[0].position.y is a measure', [array_replace($entry, ['position' => ['y' => null]])]],
            ['fixed_placements[0].position.z is a measure', [array_replace($entry, ['position' => ['z' => ['1']]])]],
            ['unknown item type "crate"', [array_replace($entry, ['item_type' => 'crate'])]],
            ['unknown container type "crate"', [array_replace($entry, ['container_type' => 'crate'])]],
            ['box instances [2] are not numbered 1..1', [array_replace($entry, ['container_instance' => 2])]],
            ['outside_container: cube#1', [array_replace($entry, ['position' => ['x' => '576460752303423']])]],
            ['outside_container: cube#1', [array_replace($entry, ['position' => ['z' => '576460752303423']])]],
        ];
        foreach ($cases as [$detail, $placements]) {
            $data = array_replace(self::request(), ['fixed_placements' => $placements]);
            self::assertSame("invalid_fixed_placement: {$detail}", self::refusal($data), $detail);
        }
    }

    public static function testAFixedPlacementIsReadByValueAndAnEmptyPositionIsTheOrigin(): void
    {
        $entry = array_replace(self::fixed('100'), ['container_instance' => 1.0]);
        self::assertSame([['box#1', 'cube#1', '100', '0', '0']], self::fixedRows(ArrayCodec::pack(self::request(null, null, [$entry]))));
        // `json_decode(..., true)` reads `"position": {}` as the empty array.
        $origin = array_replace(self::fixed(), ['position' => []]);
        self::assertSame([['box#1', 'cube#1', '0', '0', '0']], self::fixedRows(ArrayCodec::pack(self::request(null, null, [$origin]))));
        $request = self::request();
        $request['fixed_placements'] = null;
        self::assertSame([], self::fixedRows(ArrayCodec::pack($request)));
        unset($request['fixed_placements']);
        self::assertSame([], self::fixedRows(ArrayCodec::pack($request)));
    }

    public static function testTheValidatorCatchesAMovedFixedItem(): void
    {
        [$items, $containers] = self::domain();
        $packed = (new Packer(new PackingConfig()))->pack($items, $containers, [self::at(100, 0, 0)]);
        $moved = new PackingRequest($items, $containers, [self::at(0, 0, 0)]);
        $report = (new IndependentSolutionValidator())->validate($moved, $packed->containers, 0.0, null, $packed->unpacked);
        $codes = array_map(static fn($issue): string => $issue->code, $report->issues);
        sort($codes);
        self::assertSame(['fixed_placement_moved', 'unexpected_fixed_placement'], $codes);
    }

    public static function testSeveralMovedFixedItemsAreReportedInOneOrder(): void
    {
        [$items, $containers] = self::domain();
        $packed = (new Packer(new PackingConfig()))->pack($items, $containers, [self::at(100, 0, 0), self::at(0, 0, 0)]);
        $moved = new PackingRequest($items, $containers, [self::at(100, 0, 100), self::at(0, 0, 100)]);
        $report = (new IndependentSolutionValidator())->validate($moved, $packed->containers, 0.0, null, $packed->unpacked);
        $details = array_map(static fn($issue): string => $issue->code . ' ' . $issue->detail, $report->issues);
        self::assertSame([
            'fixed_placement_moved cube in box#1 at (0, 0, 1600000) LWH',
            'fixed_placement_moved cube in box#1 at (1600000, 0, 1600000) LWH',
            'unexpected_fixed_placement cube in box#1 at (0, 0, 0) LWH',
            'unexpected_fixed_placement cube in box#1 at (1600000, 0, 0) LWH',
        ], array_values(array_filter($details, static fn(string $d): bool => str_contains($d, 'fixed'))));
    }

    public static function testTheValidatorCatchesAMissingFixedContainer(): void
    {
        [$items, $containers] = self::domain();
        $packed = (new Packer(new PackingConfig()))->pack($items, $containers);
        $wanted = new PackingRequest($items, $containers, [self::at(0, 0, 0, 3)]);
        $report = (new IndependentSolutionValidator())->validate($wanted, $packed->containers);
        self::assertContains('fixed_container_missing', array_map(static fn($issue): string => $issue->code, $report->issues));
    }

    public static function testRebalancingNeverMovesAFixedItem(): void
    {
        [$items, $containers] = self::domain(5, '5000g', 2);
        $fixed = [self::at(0, 0, 0), self::at(100, 0, 0), self::at(0, 0, 100), self::at(0, 0, 0, 2)];
        $config = new PackingConfig();
        $packed = (new Packer($config))->pack($items, $containers, $fixed);
        self::assertCount(2, $packed->containers);
        $request = new PackingRequest($items, $containers, $fixed);
        $rebalanced = WeightRebalancer::rebalance($request, $packed->containers, $packed->unpacked, $config);
        foreach ($rebalanced->moves as $move) {
            self::assertNotContains($move->itemId, ['cube#1', 'cube#2', 'cube#3', 'cube#4']);
        }
        $report = (new IndependentSolutionValidator())->validate($request, $rebalanced->containers, 0.0, null, $packed->unpacked);
        self::assertTrue($report->valid);
    }

    /** @return array<string,mixed> */
    private static function cube(int $quantity): array
    {
        return ['id' => 'cube', 'quantity' => $quantity, 'weight' => '1000',
            'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100']];
    }

    /** @return array<string,mixed> */
    private static function box(int $quantity = 3): array
    {
        return ['id' => 'box', 'quantity' => $quantity,
            'inner_dimensions' => ['length' => '200', 'width' => '100', 'height' => '200']];
    }

    /** @return array<string,mixed> */
    private static function fixed(string $x = '0', string $y = '0', string $z = '0', int $instance = 1): array
    {
        return ['item_type' => 'cube', 'container_type' => 'box', 'container_instance' => $instance,
            'position' => ['x' => $x, 'y' => $y, 'z' => $z], 'orientation' => 'LWH'];
    }

    private static function refusal(array $data): ?string
    {
        try {
            ArrayCodec::pack($data);
        } catch (FixedPlacementException $error) {
            return $error->getMessage();
        }
        return null;
    }

    /** @return array<string,mixed> */
    private static function request(?array $items = null, ?array $containers = null, ?array $placements = null, ?array $configuration = null): array
    {
        $data = ['items' => $items ?? [self::cube(5)], 'containers' => $containers ?? [self::box()],
            'fixed_placements' => $placements ?? [self::fixed('100')]];
        if ($configuration !== null) {
            $data['configuration'] = $configuration;
        }
        return $data;
    }

    /** @return list<list<string>> */
    private static function fixedRows(array $result): array
    {
        $rows = [];
        foreach ($result['containers'] as $container) {
            foreach ($container['placements'] as $placement) {
                if (($placement['fixed'] ?? false) === true) {
                    $rows[] = [$container['id'], $placement['item_id'], $placement['position']['x']['value'],
                        $placement['position']['y']['value'], $placement['position']['z']['value']];
                }
            }
        }
        return $rows;
    }

    private static function placed(array $result): int
    {
        return array_sum(array_map(static fn(array $c): int => count($c['placements']), $result['containers']));
    }

    /** @return array{0:list<Item>,1:list<Container>} */
    private static function domain(int $quantity = 5, string $weight = '1000g', int $boxes = 3): array
    {
        return [
            [Item::create('cube', Dimensions::mm(100, 100, 100), $weight, $quantity)],
            [Container::create('box', Dimensions::mm(200, 100, 200), 0, null, null, 0, $boxes)],
        ];
    }

    private static function at(int $x, int $y, int $z, int $instance = 1): FixedPlacement
    {
        return new FixedPlacement('cube', 'box', new Point(Length::mm($x)->ticks, Length::mm($y)->ticks, Length::mm($z)->ticks), Rotation::LWH, $instance);
    }
}
