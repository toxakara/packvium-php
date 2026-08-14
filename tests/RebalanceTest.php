<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Algorithm\WeightRebalancer;
use Packvium\Config\PackingConfig;
use Packvium\Domain\Item;
use Packvium\Domain\ItemInstance;
use Packvium\Domain\PackedContainer;
use Packvium\Domain\PackingRequest;
use Packvium\Domain\Placement;
use Packvium\Domain\Point;
use Packvium\Domain\Rotation;
use Packvium\Domain\UnpackedItem;
use Packvium\Validation\IndependentSolutionValidator;

/**
 * Weight balancing across already-packed containers.
 *
 * A naive post-pass weight redistributor carries a well-known failure mode:
 * it can silently drop an item during redistribution because its own bookkeeping only
 * checks whether the box count is still 1, not whether every item it started with is
 * still accounted for. Every test here is built around that failure mode -- proving an
 * item is never lost or duplicated across a rebalance, that a move which would strand
 * another item's support is declined rather than made, and that the redistribution
 * actually narrows the payload spread it exists to narrow. Mirrors
 * packvium-python/tests/test_rebalance.py.
 */
final class RebalanceTest extends TestCase
{
    private const TRIALS = 12;

    private static function floorPlacement(Item $item, int $sequence, int $x, int $y): Placement
    {
        $instance = new ItemInstance($item, $sequence);
        $origin = new Point($x, $y, 0);
        return new Placement($instance, $origin, Rotation::LWH, $item->dimensions, $origin, $item->dimensions, 1.0);
    }

    private static function stackedPlacement(Item $item, int $sequence, Placement $below): Placement
    {
        $instance = new ItemInstance($item, $sequence);
        $origin = new Point($below->envelopeOrigin->x, $below->envelopeOrigin->y, $below->envelopeBox()->z2());
        return new Placement($instance, $origin, Rotation::LWH, $item->dimensions, $origin, $item->dimensions, 1.0);
    }

    /** @param list<PackedContainer> $containers @return list<int> */
    private static function weights(array $containers): array
    {
        return array_map(static fn(PackedContainer $c): int => $c->payloadWeight()->ticks, $containers);
    }

    /**
     * The strongest bookkeeping property there is: every requested instance comes back
     * exactly once, either placed or explained -- exactly what a naive
     * post-pass redistributor fails to guarantee.
     *
     * @param list<PackedContainer> $containers @param list<UnpackedItem> $unpacked
     */
    private static function assertAccountingHolds(PackingRequest $request, array $containers, array $unpacked): void
    {
        $placedIds = [];
        foreach ($containers as $c) {
            foreach ($c->placements as $p) {
                $placedIds[] = $p->instance->id();
            }
        }
        $unpackedIds = array_map(static fn(UnpackedItem $u): string => $u->instance->id(), $unpacked);
        self::assertSame(count($placedIds), count(array_unique($placedIds)), 'an item instance was packed twice');

        $expected = [];
        foreach ($request->items as $item) {
            foreach ($item->instances() as $instance) {
                $expected[] = $instance->id();
            }
        }
        sort($expected);
        $accounted = [...$placedIds, ...$unpackedIds];
        sort($accounted);
        self::assertSame($expected, $accounted, 'items were lost or fabricated');
    }

    /** @param list<PackedContainer> $containers @param list<UnpackedItem> $unpacked */
    private static function assertValid(PackingRequest $request, array $containers, array $unpacked, PackingConfig $config): void
    {
        $report = (new IndependentSolutionValidator())->validate(
            $request, $containers, $config->minimumSupportRatio, $config->clearance, $unpacked,
        );
        $codes = array_map(static fn($issue): string => $issue->code . ':' . $issue->detail, $report->issues);
        self::assertTrue($report->valid, implode(', ', $codes));
    }

    // ------------------------------------------------------------- narrowing the spread

    public static function testAMoveStrictlyReducesThePayloadSpreadBetweenTwoContainers(): void
    {
        // One container holds a 5kg and a 1kg item (6kg), the other holds a lone 1kg
        // item. Moving the 5kg item would only flip which side is heavier (1kg vs 6kg,
        // still a 5kg spread) -- the greedy search must reject that overshoot and move
        // the 1kg item instead, landing on a 5kg/2kg split (3kg spread).
        $heavy = Support::item('heavy', 40, 40, 40, ['weight' => '5000 g']);
        $light = Support::item('light', 40, 40, 40, ['weight' => '1000 g']);
        $alone = Support::item('alone', 40, 40, 40, ['weight' => '1000 g']);
        $boxType = Support::box('box', 200, 200, 200);
        $request = new PackingRequest([$heavy, $light, $alone], [$boxType]);
        $packed = [
            new PackedContainer($boxType, 1, [self::floorPlacement($heavy, 1, 0, 0), self::floorPlacement($light, 1, 50, 0)]),
            new PackedContainer($boxType, 2, [self::floorPlacement($alone, 1, 0, 0)]),
        ];
        $before = self::weights($packed);
        self::assertSame(5000 * 8_000_000, max($before) - min($before));

        $config = new PackingConfig();
        $outcome = WeightRebalancer::rebalance($request, $packed, [], $config);

        $after = self::weights($outcome->containers);
        sort($after);
        self::assertSame([2000 * 8_000_000, 5000 * 8_000_000], $after);
        self::assertTrue(max($after) - min($after) < max($before) - min($before));
        // A cross-language fixture kept one level above this package; a published copy
        // does not carry it, and everything above this line has already been checked.
        $shared = __DIR__ . '/../../conformance/scene/rebalance-fixtures.json';
        if (!is_file($shared)) {
            self::skip('the shared cross-language scene fixture is not part of this package');
        }
        $fixture = json_decode((string) file_get_contents($shared), true, flags: JSON_THROW_ON_ERROR)['cases'][0];
        self::assertCount(1, $outcome->moves);
        self::assertSame($fixture['expected_move']['item_id'], $outcome->moves[0]->itemId);
        self::assertSame($fixture['expected_move']['from_container_id'], $outcome->moves[0]->fromContainerId);
        self::assertSame($fixture['expected_move']['to_container_id'], $outcome->moves[0]->toContainerId);
        self::assertSame(
            $fixture['expected_container_item_ids'],
            array_map(
                static fn(PackedContainer $container): array => array_map(
                    static fn(Placement $placement): string => $placement->instance->id(),
                    $container->placements,
                ),
                $outcome->containers,
            ),
        );
        self::assertTrue($outcome->improved());

        self::assertAccountingHolds($request, $outcome->containers, []);
        self::assertValid($request, $outcome->containers, [], $config);
    }

    public static function testAMoveThatWouldStrandASupportedItemIsDeclined(): void
    {
        // One container holds a 5kg base item with a support-sensitive 1kg item
        // resting directly on top of it; the other container is empty. Moving the base
        // (the larger weight, and the greedy search's first choice) would leave the
        // item above it floating with no support -- exactly the kind of "looks fine,
        // isn't" result `WeightRedistributor` never checked for. The rebalance must
        // decline that move and fall back to moving the top item instead.
        $base = Support::item('base', 40, 40, 40, ['weight' => '5000 g']);
        $top = Support::item('top', 40, 40, 40, ['weight' => '1000 g', 'minimumSupportRatio' => 1.0]);
        $boxType = Support::box('box', 200, 200, 200);
        $request = new PackingRequest([$base, $top], [$boxType]);
        $basePlacement = self::floorPlacement($base, 1, 0, 0);
        $topPlacement = self::stackedPlacement($top, 1, $basePlacement);
        $packed = [
            new PackedContainer($boxType, 1, [$basePlacement, $topPlacement]),
            new PackedContainer($boxType, 2, []),
        ];
        $before = self::weights($packed);
        self::assertSame([6000 * 8_000_000, 0], $before);

        $config = new PackingConfig();
        $outcome = WeightRebalancer::rebalance($request, $packed, [], $config);

        $baseContainer = null;
        foreach ($outcome->containers as $c) {
            if ($c->id() === 'box#1') {
                $baseContainer = $c;
            }
        }
        self::assertNotNull($baseContainer);
        self::assertCount(1, $baseContainer->placements);
        self::assertSame('base#1', $baseContainer->placements[0]->instance->id());

        $after = self::weights($outcome->containers);
        sort($after);
        self::assertSame([1000 * 8_000_000, 5000 * 8_000_000], $after);
        self::assertCount(1, $outcome->moves);
        self::assertSame('top#1', $outcome->moves[0]->itemId);

        self::assertAccountingHolds($request, $outcome->containers, []);
        self::assertValid($request, $outcome->containers, [], $config);
    }

    public static function testASingleContainerHasNothingToRebalance(): void
    {
        $solo = Support::item('solo', 40, 40, 40, ['weight' => '500 g']);
        $boxType = Support::box('box', 200, 200, 200);
        $request = new PackingRequest([$solo], [$boxType]);
        $packed = [new PackedContainer($boxType, 1, [self::floorPlacement($solo, 1, 0, 0)])];

        $outcome = WeightRebalancer::rebalance($request, $packed, [], new PackingConfig());

        self::assertSame([], $outcome->moves);
        self::assertFalse($outcome->improved());
        self::assertSame($packed, $outcome->containers);
    }

    public static function testAnAlreadyBalancedPairIsLeftUntouched(): void
    {
        $a = Support::item('a', 40, 40, 40, ['weight' => '1000 g']);
        $b = Support::item('b', 40, 40, 40, ['weight' => '1000 g']);
        $boxType = Support::box('box', 200, 200, 200);
        $request = new PackingRequest([$a, $b], [$boxType]);
        $packed = [
            new PackedContainer($boxType, 1, [self::floorPlacement($a, 1, 0, 0)]),
            new PackedContainer($boxType, 2, [self::floorPlacement($b, 1, 0, 0)]),
        ];

        $outcome = WeightRebalancer::rebalance($request, $packed, [], new PackingConfig());

        self::assertSame([], $outcome->moves);
        self::assertSame($packed, $outcome->containers);
    }

    // --------------------------------------------------------------- randomised property

    /** @return array{0:list<Item>,1:list<\Packvium\Domain\Container>} */
    private static function generate(int $seed): array
    {
        mt_srand($seed);
        $items = [];
        $types = mt_rand(2, 6);
        for ($index = 0; $index < $types; $index++) {
            $items[] = Support::item("i{$index}", mt_rand(2, 7) * 5, mt_rand(2, 7) * 5, mt_rand(2, 7) * 5, [
                'quantity' => mt_rand(1, 3),
                'weight' => mt_rand(100, 900) . ' g',
            ]);
        }
        $boxType = Support::box('c0', 150, 150, 150, [
            'quantity' => mt_rand(2, 4),
            'maxPayload' => mt_rand(1, 2) . ' kg',
        ]);
        return [$items, [$boxType]];
    }

    public static function testRebalanceNeverLosesOrDuplicatesAnItem(): void
    {
        // Holds regardless of how many containers a given order lands in -- a
        // single-container result has nothing to rebalance, but the accounting
        // invariant is exactly as much a requirement for it as for any other.
        for ($seed = 0; $seed < self::TRIALS; $seed++) {
            [$items, $containers] = self::generate($seed);
            $request = new PackingRequest($items, $containers);
            $result = Support::pack($items, $containers, new PackingConfig(timeLimitMs: 250));

            $config = new PackingConfig(timeLimitMs: 200);
            $outcome = WeightRebalancer::rebalance($request, $result->containers, $result->unpacked, $config);

            self::assertAccountingHolds($request, $outcome->containers, $result->unpacked);
            self::assertValid($request, $outcome->containers, $result->unpacked, $config);
        }
    }

    public static function testRebalanceNeverWidensThePayloadSpread(): void
    {
        for ($seed = 0; $seed < self::TRIALS; $seed++) {
            [$items, $containers] = self::generate($seed);
            $request = new PackingRequest($items, $containers);
            $result = Support::pack($items, $containers, new PackingConfig(timeLimitMs: 250));

            $before = self::weights($result->containers);
            $beforeSpread = $before === [] ? 0 : max($before) - min($before);

            $config = new PackingConfig(timeLimitMs: 200);
            $outcome = WeightRebalancer::rebalance($request, $result->containers, $result->unpacked, $config);

            $after = self::weights($outcome->containers);
            $afterSpread = $after === [] ? 0 : max($after) - min($after);
            self::assertTrue($afterSpread <= $beforeSpread, "seed {$seed}: spread widened");
            if ($outcome->moves !== []) {
                self::assertNotSame($before, $after, "seed {$seed}: a move was recorded but nothing changed");
            }
        }
    }
}
