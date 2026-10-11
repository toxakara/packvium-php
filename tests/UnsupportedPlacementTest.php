<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container, Dimensions, FixedPlacement, Item, Point, Rotation};
use Packvium\Packer;
use Packvium\Support\UnsupportedPlacements;
use Packvium\Serialization\ArrayCodec;
use Packvium\Unit\Length;

/**
 * The default minimum_support_ratio of 0 lets a placement rest on nothing, and says so.
 * Mirrors `packvium-python/tests/test_unsupported_placements.py`.
 */
final class UnsupportedPlacementTest extends TestCase
{
    private const WARNING = 'unsupported_placements: %d placement(s) rest on nothing above the container floor; '
        . 'set configuration.minimum_support_ratio above 0 to require support';

    public static function testAPlacementInMidAirAndOneOnAnObstacleAreBothCounted(): void
    {
        $result = ArrayCodec::pack(self::scene());
        self::assertSame(sprintf(self::WARNING, 2), $result['warnings'][count($result['warnings']) - 1]);
        self::assertCount(1, self::unsupportedWarnings($result));
    }

    public static function testEveryAlternativeCarriesItsOwnWarning(): void
    {
        $result = ArrayCodec::pack(self::scene());
        self::assertTrue($result['alternatives'] !== []);
        foreach ($result['alternatives'] as $alternative) {
            self::assertSame([sprintf(self::WARNING, 2)], self::unsupportedWarnings($alternative));
        }
    }

    public static function testAFullySupportedPackingHasNoWarning(): void
    {
        self::assertSame([], self::unsupportedWarnings(ArrayCodec::pack(self::scene(false))));
    }

    public static function testRequiringSupportSilencesTheWarning(): void
    {
        $result = ArrayCodec::pack(self::scene(false, ['minimum_support_ratio' => 0.5]));
        self::assertSame([], $result['warnings']);
    }

    public static function testANonzeroRatioNeverCountsEvenAnUnsupportedPlacement(): void
    {
        $items = [Item::create('cube', Dimensions::mm(100, 100, 100), '1000g', 1)];
        $containers = [Container::create('box', Dimensions::mm(100, 100, 300), 0, null, null, 0, 1)];
        $lifted = new FixedPlacement('cube', 'box', new Point(0, 0, Length::mm(100)->ticks), Rotation::LWH, 1);
        $packed = (new Packer(new PackingConfig()))->pack($items, $containers, [$lifted])->containers;
        self::assertSame(sprintf(self::WARNING, 1), UnsupportedPlacements::warning($packed, 0.0));
        self::assertNull(UnsupportedPlacements::warning($packed, 0.25));
    }

    public static function testNoContainersMeansNoWarning(): void
    {
        self::assertNull(UnsupportedPlacements::warning([], 0.0));
    }

    /**
     * Two stacked bases, plus a slab in mid-air and a slab on an obstacle when floating.
     *
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    private static function scene(bool $floating = true, array $configuration = []): array
    {
        $placements = [self::fixed('base', '0', '0', '0'), self::fixed('base', '0', '0', '100')];
        $items = [self::cube('base', 2), self::cube('loose', 4)];
        if ($floating) {
            $placements[] = self::fixed('slab', '100', '100', '300');
            $placements[] = self::fixed('slab', '200', '0', '100');
            $items[] = self::cube('slab', 2, '50');
        }
        return [
            'items' => $items,
            'containers' => [[
                'id' => 'crate', 'quantity' => 1,
                'inner_dimensions' => ['length' => '400', 'width' => '200', 'height' => '400'],
                'obstacles' => [['id' => 'shelf', 'origin' => ['x' => '200', 'y' => '0', 'z' => '0'],
                    'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100']]],
            ]],
            'fixed_placements' => $placements,
            'configuration' => ['solver_profile' => 'quality', 'alternatives' => 3,
                'effort_budget' => ['max_search_nodes' => 20000], 'time_limit_ms' => 60000] + $configuration,
        ];
    }

    /** @return array<string, mixed> */
    private static function cube(string $id, int $quantity, string $height = '100'): array
    {
        return ['id' => $id, 'quantity' => $quantity, 'weight' => '500',
            'dimensions' => ['length' => '100', 'width' => '100', 'height' => $height]];
    }

    /** @return array<string, mixed> */
    private static function fixed(string $itemType, string $x, string $y, string $z): array
    {
        return ['item_type' => $itemType, 'container_type' => 'crate',
            'position' => ['x' => $x, 'y' => $y, 'z' => $z], 'orientation' => 'LWH'];
    }

    /**
     * @param array<string, mixed> $result
     * @return list<string>
     */
    private static function unsupportedWarnings(array $result): array
    {
        return array_values(array_filter(
            $result['warnings'],
            static fn(string $line): bool => str_starts_with($line, 'unsupported_placements:'),
        ));
    }
}
