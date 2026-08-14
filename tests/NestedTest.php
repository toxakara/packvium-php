<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Config\PackingConfig;
use Packvium\Domain\Dimensions;
use Packvium\Nested\NestedPacker;
use Packvium\Nested\PackingLevel;
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
}
