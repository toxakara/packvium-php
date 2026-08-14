<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Config\PackingConfig;
use Packvium\Domain\Container;
use Packvium\Domain\Dimensions;
use Packvium\Domain\Item;
use Packvium\Domain\PackedContainer;
use Packvium\Domain\PackingRequest;
use Packvium\Domain\UnpackedItem;
use Packvium\Packer;
use Packvium\Result\PackingResult;
use Packvium\Unit\Length;
use Packvium\Validation\IndependentSolutionValidator;

/**
 * Shared construction helpers.
 *
 * Tests read better when the interesting fact is the only thing on the line, so the
 * boilerplate of building an item, a container or a request lives here.
 */
final class Support
{
    /** Ticks in one millimetre — the unit almost every fixture is written in. */
    public const MM = Length::TICKS_PER_MM;

    public static function dims(int $length, int $width, int $height): Dimensions
    {
        return Dimensions::mm($length, $width, $height);
    }

    /** @param array<string,mixed> $options forwarded to Item::create as named arguments */
    public static function item(string $id, int $length, int $width, int $height, array $options = []): Item
    {
        return Item::create($id, self::dims($length, $width, $height), ...$options);
    }

    /** @param array<string,mixed> $options forwarded to Container::create as named arguments */
    public static function box(string $id, int $length, int $width, int $height, array $options = []): Container
    {
        return Container::create($id, self::dims($length, $width, $height), ...$options);
    }

    /** @param list<Item> $items @param list<Container> $containers */
    public static function pack(array $items, array $containers, ?PackingConfig $config = null, ?\Closure $clock = null): PackingResult
    {
        return (new Packer($config ?? PackingConfig::balanced(), clock: $clock))->pack($items, $containers);
    }

    /**
     * Validation codes raised against a hand-built or solver-produced solution.
     *
     * @param list<Item> $items @param list<Container> $containers @param list<PackedContainer> $packed
     * @return list<string>
     */
    public static function issues(array $items, array $containers, array $packed,
                                  float $minimumSupportRatio = 0.0, ?Length $clearance = null,
                                  ?array $unpacked = null): array
    {
        $report = (new IndependentSolutionValidator())
            ->validate(new PackingRequest($items, $containers), $packed, $minimumSupportRatio, $clearance, $unpacked);
        return array_map(static fn($issue): string => $issue->code, $report->issues);
    }

    /**
     * Every guarantee the library owes a caller, re-derived from the placements alone.
     *
     * A solver that reports a placement it never verified is caught here rather than by
     * an assertion that only counts items.
     *
     * @param list<Item> $items @param list<Container> $containers @return list<string>
     */
    public static function problems(PackingResult $result, array $items, array $containers,
                                    float $minimumSupportRatio = 0.0, ?Length $clearance = null): array
    {
        $problems = self::issues($items, $containers, $result->containers, $minimumSupportRatio, $clearance);

        $placed = [];
        foreach ($result->containers as $container) {
            foreach ($container->placements as $placement) {
                $placed[] = $placement->instance->id();
            }
        }
        if (count($placed) !== count(array_unique($placed))) {
            $problems[] = 'duplicate_placement';
        }

        $accounted = $placed;
        foreach ($result->unpacked as $unpacked) {
            $accounted[] = $unpacked->instance->id();
        }
        $expected = array_map(static fn($i): string => $i->id(), (new PackingRequest($items, $containers))->instances());
        sort($accounted);
        sort($expected);
        if ($accounted !== $expected) {
            $problems[] = 'items_lost_or_invented';
        }
        return $problems;
    }

    /** @param list<PackedContainer> $containers @return list<string> */
    public static function placedIds(array $containers): array
    {
        $out = [];
        foreach ($containers as $container) {
            foreach ($container->placements as $placement) {
                $out[] = $placement->instance->id();
            }
        }
        return $out;
    }
}
