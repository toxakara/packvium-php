<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Config\PackingConfig;
use Packvium\Domain\Dimensions;
use Packvium\Domain\PackedContainer;
use Packvium\Nested\NestedPacker;
use Packvium\Nested\PackingLevel;
use Packvium\Result\PackingResult;
use Packvium\Serialization\ArrayCodec;
use Packvium\Support\RequestRules;
use InvalidArgumentException;
use ReflectionMethod;
use TypeError;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;

/**
 * Multi-level packing: items into cartons, cartons onto a pallet.
 *
 * Each level re-packs the previous level's full containers as items, so the handover —
 * outer dimensions, gross weight, upright orientation — is what these tests pin down.
 */
final class NestedTest extends TestCase
{
    /**
     * The level beam's answers, held once for all four engines. A published copy of
     * the package does not carry the corpus, so the test that reads it skips there.
     */
    private const BEAM_SCENE = __DIR__ . '/../../conformance/scene/nested-beam.json';

    public static function testTwoLevelsArePackedInOrder(): void
    {
        $result = (new NestedPacker())->pack(
            [Support::item('cube', 100, 100, 100, ['quantity' => 8])],
            [
                new PackingLevel('carton', [Support::box('carton', 200, 200, 100)]),
                new PackingLevel('pallet', [Support::box('pallet', 200, 200, 200)]),
            ],
        );

        self::assertCount(2, $result->levels);
        foreach ($result->levels as $level) {
            self::assertTrue($level->complete());
        }
        self::assertCount(2, $result->levels[0]->containers);
        self::assertCount(1, $result->levels[1]->containers);
    }

    public static function testTheSecondLevelPacksTheFirstLevelContainersByTheirIds(): void
    {
        $result = (new NestedPacker())->pack(
            [Support::item('cube', 100, 100, 100, ['quantity' => 8])],
            [
                new PackingLevel('carton', [Support::box('carton', 200, 200, 100)]),
                new PackingLevel('pallet', [Support::box('pallet', 200, 200, 200)]),
            ],
        );

        $onPallet = array_map(
            static fn($p): string => $p->instance->item->id,
            $result->levels[1]->containers[0]->placements,
        );
        sort($onPallet);
        self::assertSame(['carton#1', 'carton#2'], $onPallet);
    }

    public static function testGrossWeightCarriesUpToTheNextLevel(): void
    {
        // The pallet has to bear the cartons and their contents, not just the contents.
        $result = (new NestedPacker())->pack(
            [Support::item('cube', 100, 100, 100, ['quantity' => 8, 'weight' => '1 kg'])],
            [
                new PackingLevel('carton', [Support::box('carton', 200, 200, 100, ['tareWeight' => '500 g'])]),
                new PackingLevel('pallet', [Support::box('pallet', 200, 200, 200, ['maxPayload' => '10 kg'])]),
            ],
        );

        $carton = $result->levels[1]->containers[0]->placements[0]->instance->item;
        self::assertSame(Weight::of('4.5', 'kg')->ticks, $carton->weight->ticks);
    }

    public static function testOuterDimensionsAreWhatTheNextLevelSees(): void
    {
        $result = (new NestedPacker())->pack(
            [Support::item('cube', 100, 100, 100, ['quantity' => 2])],
            [
                new PackingLevel('carton', [Support::box('carton', 100, 100, 100,
                    ['outerDimensions' => Dimensions::mm(110, 110, 110)])]),
                new PackingLevel('pallet', [Support::box('pallet', 220, 110, 110)]),
            ],
        );

        $carton = $result->levels[1]->containers[0]->placements[0]->instance->item;
        self::assertSame(Length::mm(110)->ticks, $carton->dimensions->length->ticks);
    }

    public static function testPackingStopsAtTheFirstLevelThatCouldNotFinish(): void
    {
        // Feeding an incomplete level upward would silently drop whatever it left behind.
        $result = (new NestedPacker())->pack(
            [Support::item('cube', 90, 90, 90, ['quantity' => 4])],
            [
                new PackingLevel('carton', [Support::box('carton', 100, 100, 100, ['quantity' => 1])]),
                new PackingLevel('pallet', [Support::box('pallet', 1_000, 1_000, 1_000)]),
            ],
        );

        self::assertCount(1, $result->levels);
        self::assertFalse($result->levels[0]->complete());
    }

    public static function testALevelMayCarryItsOwnConfiguration(): void
    {
        $result = (new NestedPacker())->pack(
            [Support::item('cube', 100, 100, 100, ['quantity' => 8])],
            [
                new PackingLevel('carton', [Support::box('carton', 200, 200, 100)],
                    new PackingConfig(seed: 5)),
                new PackingLevel('pallet', [Support::box('pallet', 200, 200, 200)],
                    PackingConfig::quality(1_000)),
            ],
        );

        self::assertSame(5, $result->levels[0]->algorithm->seed);
        self::assertSame('balanced', $result->levels[0]->algorithm->profile);
        self::assertSame('quality', $result->levels[1]->algorithm->profile);
    }

    public static function testASingleLevelBehavesLikeAnOrdinaryPack(): void
    {
        $result = (new NestedPacker())->pack(
            [Support::item('cube', 100, 100, 100, ['quantity' => 4])],
            [new PackingLevel('carton', [Support::box('carton', 200, 200, 100)])],
        );

        self::assertCount(1, $result->levels);
        self::assertTrue($result->levels[0]->complete());
    }

    public static function testALevelBeamWidthOfOneIsTheGreedyChain(): void
    {
        [$items, $levels] = self::deadEnd();

        self::assertSame(
            self::shape((new NestedPacker())->pack($items, $levels)->levels),
            self::shape((new NestedPacker(1))->pack($items, $levels)->levels),
        );
    }

    public static function testAWiderBeamFindsTheCartonSetThatFitsThePallet(): void
    {
        // The single long carton is the better carton level and cannot stand on the tower.
        [$items, $levels] = self::deadEnd();

        $greedy = (new NestedPacker(1))->pack($items, $levels);
        $beam = (new NestedPacker(2))->pack($items, $levels);

        self::assertSame([true, false], array_map(static fn(PackingResult $l): bool => $l->complete(), $greedy->levels));
        self::assertSame([true, true], array_map(static fn(PackingResult $l): bool => $l->complete(), $beam->levels));
        self::assertSame(['single', 'single'], array_map(
            static fn($c): string => $c->container->id,
            $beam->levels[0]->containers,
        ));
    }

    public static function testHandoffsThatAreTheSameItemsOccupyOneBeamSlot(): void
    {
        // Packing against every carton and against `long` alone both hand up one `long#1`;
        // counting that twice would fill a width of two and drop the chain that fits.
        [$items, $levels] = self::deadEnd();

        $result = (new NestedPacker(2))->pack($items, $levels);

        self::assertCount(2, $result->levels);
        foreach ($result->levels as $level) {
            self::assertTrue($level->complete());
        }
    }

    public static function testNoLevelsIsAnEmptyAnswerAtAnyWidth(): void
    {
        self::assertSame([], (new NestedPacker(3))->pack([Support::item('cube', 100, 100, 100)], [])->levels);
    }

    public static function testTheLevelBeamWidthIsAPositiveInteger(): void
    {
        foreach ([0, -1] as $width) {
            self::assertThrows(InvalidArgumentException::class, static fn() => new NestedPacker($width));
        }
        // Strict types refuse what Python refuses by its own check: a boolean, a float, text.
        foreach ([true, 1.0, '2'] as $width) {
            self::assertThrows(TypeError::class, static fn() => new NestedPacker($width));
        }
        self::assertSame(2, (new NestedPacker(2))->levelBeamWidth);
    }

    public static function testTheLevelBeamWidthRefusalNamesTheParameter(): void
    {
        try {
            new NestedPacker(0);
        } catch (InvalidArgumentException $error) {
            self::assertSame('level_beam_width must be a positive integer', $error->getMessage());
            return;
        }
        self::assertTrue(false, 'a width of zero was accepted');
    }

    public static function testChainScoresCompareAsExactNumbersNotText(): void
    {
        // A component past PHP's integer range is a decimal string; as text, "1000..." < "999...".
        $compare = new ReflectionMethod(NestedPacker::class, 'compareScores');
        $compare->setAccessible(true);

        self::assertSame(1, $compare->invoke(null, [0, '100000000000000000000'], [0, '99999999999999999999']));
        self::assertSame(-1, $compare->invoke(null, [0, 5], [0, '99999999999999999999']));
        self::assertSame(-1, $compare->invoke(null, [1.5], [2]));
        self::assertSame(0, $compare->invoke(null, [3, 4], [3, 4]));
    }

    public static function testAHandoffIsTheMultisetOfContainersNotTheirNumberingOrOrder(): void
    {
        // Engines may number and emit equal instances differently; the same containers
        // handed up must still collapse to one beam slot.
        $packed = Support::pack([Support::item('cube', 100, 100, 100, ['quantity' => 3])],
            [Support::box('pair', 200, 100, 100, ['quantity' => 1]), Support::box('single', 100, 100, 100)]);
        self::assertCount(2, $packed->containers);
        $renumbered = array_map(
            static fn(PackedContainer $c): PackedContainer => new PackedContainer($c->container, $c->sequence + 7, $c->placements),
            array_reverse($packed->containers),
        );
        $shuffled = new PackingResult($packed->status, $renumbered, $packed->unpacked, $packed->algorithm, $packed->score);
        $fewer = new PackingResult($packed->status, [$packed->containers[0]], $packed->unpacked, $packed->algorithm, $packed->score);

        $key = new ReflectionMethod(NestedPacker::class, 'handoffKey');
        $key->setAccessible(true);
        self::assertSame($key->invoke(null, $packed), $key->invoke(null, $shuffled));
        self::assertNotSame($key->invoke(null, $packed), $key->invoke(null, $fewer));
    }

    public static function testTheLevelBeamMatchesTheSharedScene(): void
    {
        if (!is_file(self::BEAM_SCENE)) {
            self::skip('the shared cross-language scene corpus is not part of this package');
        }
        $payload = json_decode((string) file_get_contents(self::BEAM_SCENE), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotSame([], $payload['cases'], 'an empty scene would pass without asserting anything');

        foreach ($payload['cases'] as $case) {
            self::assertSame(self::canonical($case['expected']), self::canonical(self::sceneAnswer($case)), "nested-beam case {$case['name']}");
        }
    }

    /** @param array<string,mixed> $case @return list<array<string,mixed>> */
    private static function sceneAnswer(array $case): array
    {
        $items = [];
        $levels = [];
        foreach ($case['levels'] as $index => $entry) {
            RequestRules::check($entry['request']);
            [$config, , , $levelItems, $containers] = self::codec('model', $entry['request']);
            if ($index === 0) {
                $items = $levelItems;
            }
            $levels[] = new PackingLevel($entry['name'], $containers, $config);
        }
        $result = (new NestedPacker($case['level_beam_width']))->pack($items, $levels);
        $answer = [];
        foreach ($result->levels as $index => $level) {
            $answer[] = self::projection($case['levels'][$index]['name'], $level->toArray('mm', 'g'));
        }
        return $answer;
    }

    /**
     * What the beam decides about a level, read from its JSON result as any engine emits it:
     * the contract's multiset view, with instance numbers dropped and every list sorted.
     *
     * @param array<string,mixed> $result @return array<string,mixed>
     */
    private static function projection(string $name, array $result): array
    {
        return [
            'name' => $name,
            'complete' => $result['complete'],
            'score' => $result['score'],
            'containers' => array_map(static fn(array $container): array => [
                'container_type' => $container['container_type'],
                'placements' => array_map(static fn(array $placement): array => [
                    self::kind($placement['item_type']),
                    $placement['position']['x']['ticks'],
                    $placement['position']['y']['ticks'],
                    $placement['position']['z']['ticks'],
                    $placement['orientation'],
                ], $container['placements']),
            ], $result['containers']),
            'unpacked' => array_map(static fn(array $unpacked): string => self::kind($unpacked['item_type']),
                $result['unpacked_items']),
        ];
    }

    /**
     * One order for both sides of the comparison. The generator sorts by Python's own
     * ordering; equal multisets sort equal under any total order, so this is exactly as strict.
     *
     * @param list<array<string,mixed>> $levels @return list<array<string,mixed>>
     */
    private static function canonical(array $levels): array
    {
        return array_map(static function (array $level): array {
            $containers = array_map(static function (array $container): array {
                $container['placements'] = self::sortedByJson($container['placements']);
                return $container;
            }, $level['containers']);
            $level['containers'] = self::sortedByJson($containers);
            $level['unpacked'] = self::sortedByJson($level['unpacked']);
            return $level;
        }, $levels);
    }

    /** @param list<mixed> $values @return list<mixed> */
    private static function sortedByJson(array $values): array
    {
        usort($values, static fn($left, $right): int => strcmp(
            json_encode($left, JSON_THROW_ON_ERROR),
            json_encode($right, JSON_THROW_ON_ERROR),
        ));
        return $values;
    }

    /** A handed-up item is named after its packed container, `quad#2`; its type is `quad`. */
    private static function kind(string $itemType): string
    {
        return explode('#', $itemType, 2)[0];
    }

    /** @return mixed */
    private static function codec(string $method, mixed ...$arguments)
    {
        $builder = new ReflectionMethod(ArrayCodec::class, $method);
        $builder->setAccessible(true);
        return $builder->invoke(null, ...$arguments);
    }

    /** @return array{0:list<\Packvium\Domain\Item>,1:list<PackingLevel>} */
    private static function deadEnd(): array
    {
        return [
            [Support::item('cube', 100, 100, 100, ['quantity' => 2])],
            [
                new PackingLevel('carton', [Support::box('long', 200, 100, 100), Support::box('single', 100, 100, 100)]),
                new PackingLevel('pallet', [Support::box('tower', 100, 100, 200)]),
            ],
        ];
    }

    /** @param list<PackingResult> $levels @return list<array{0:bool,1:list<string>,2:list<int|float|string>}> */
    private static function shape(array $levels): array
    {
        return array_map(static fn(PackingResult $level): array => [
            $level->complete(),
            array_map(static fn($c): string => $c->id(), $level->containers),
            $level->score,
        ], $levels);
    }
}
