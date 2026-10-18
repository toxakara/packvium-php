<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Algorithm\EffortBudget;
use Packvium\Config\PackingConfig;
use Packvium\Config\SolverProfile;
use Packvium\Domain\Container;
use Packvium\Domain\Dimensions;
use Packvium\Domain\FixedPlacement;
use Packvium\Domain\Item;
use Packvium\Domain\MadeToMeasure;
use Packvium\Domain\Point;
use Packvium\Domain\RateTable;
use Packvium\Domain\Rotation;
use Packvium\Packer;
use Packvium\Serialization\ArrayCodec;
use Packvium\Serialization\InvalidRequestException;
use Packvium\Unit\Length;
use RuntimeException;

/**
 * A request built from objects is refused as the same request sent as JSON is.
 * Mirrors `packvium-python/tests/test_request_objects.py`.
 *
 * Each case is one request two ways: built from objects and passed to `Packer::pack`, and
 * spelled as JSON and passed to `ArrayCodec::pack`. Both must throw `InvalidRequestException`
 * with the same reason. A value refused once the objects are in a request carries the JSON
 * field too; one an object's own constructor refuses has no position yet, so its field is empty.
 */
final class RequestObjectsTest extends TestCase
{
    private const TOO_LARGE = 2 ** 60;
    private const JSON_MACHINE = ['minimum' => ['length' => '100', 'width' => '100', 'height' => '100'],
        'increment' => ['length' => '50', 'width' => '50', 'height' => '50']];

    public static function testARequestOfObjectsIsRefusedWithTheJsonReasonAndField(): void
    {
        $cases = [
            'quantity past 2^53 - 1' => [self::packed([self::cube(quantity: self::TOO_LARGE)]),
                static function (array &$r): void { $r['items'][0]['quantity'] = self::TOO_LARGE; }],
            'repeated item id' => [self::packed([self::cube(), self::cube()]),
                static function (array &$r): void { $r['items'][] = $r['items'][0]; }],
            'repeated container id' => [self::packed(null, [self::box(), self::box()]),
                static function (array &$r): void { $r['containers'][] = $r['containers'][0]; }],
            'empty stacking group' => [self::packed([self::cube(stackingGroup: '')]),
                static function (array &$r): void { $r['items'][0]['stacking_group'] = ''; }],
            'max_stacked_items past 2^53 - 1' => [self::packed([self::cube(maxStackedItems: self::TOO_LARGE)]),
                static function (array &$r): void { $r['items'][0]['max_stacked_items'] = self::TOO_LARGE; }],
            'container cost past 2^53 - 1' => [self::packed(null, [self::box(costMinor: self::TOO_LARGE)]),
                static function (array &$r): void { $r['containers'][0]['cost_minor'] = self::TOO_LARGE; }],
            'tag limit past 2^53 - 1' => [self::packed(null, [self::box(tagLimits: ['fragile' => self::TOO_LARGE])]),
                static function (array &$r): void { $r['containers'][0]['tag_limits'] = ['fragile' => self::TOO_LARGE]; }],
            'rate table price past 2^53 - 1' => [self::packed(null, [self::box(rateTable: new RateTable([1000], [self::TOO_LARGE]))]),
                static function (array &$r): void { $r['containers'][0]['rate_table'] = ['weight_brackets_g' => [1000], 'prices_minor' => [self::TOO_LARGE]]; }],
            'time limit past 2^53 - 1' => [self::packed(null, null, PackingConfig::fast(self::TOO_LARGE)),
                static function (array &$r): void { $r['configuration']['time_limit_ms'] = self::TOO_LARGE; }],
            'effort limit past 2^53 - 1' => [self::packed(null, null, new PackingConfig(SolverProfile::Fast, effortBudget: new EffortBudget(maxRestarts: self::TOO_LARGE))),
                static function (array &$r): void { $r['configuration']['effort_budget'] = ['max_restarts' => self::TOO_LARGE]; }],
            'made to measure beside outer dimensions' => [self::packed(null, [self::madeToMeasureBox(self::machine(), Dimensions::mm(210, 110, 210))]),
                static function (array &$r): void {
                    $r['containers'][0]['made_to_measure'] = self::JSON_MACHINE;
                    $r['containers'][0]['outer_dimensions'] = ['length' => '210', 'width' => '110', 'height' => '210'];
                }],
            'made to measure priced past 2^53 - 1' => [self::packed(null, [self::madeToMeasureBox(self::machine(10 ** 18))]),
                static function (array &$r): void { $r['containers'][0]['made_to_measure'] = self::JSON_MACHINE + ['cost_per_square_metre_minor' => 10 ** 18]; }],
        ];
        foreach ($cases as $name => [$build, $edit]) {
            $fromObjects = self::refusal($build, $name);
            $fromJson = self::jsonRefusal($edit, $name);
            self::assertSame(self::identity($fromJson), self::identity($fromObjects), $name);
            self::assertSame($fromJson->getMessage(), $fromObjects->getMessage(), $name);
        }
    }

    public static function testAnObjectRefusesWithTheJsonReasonAndNoField(): void
    {
        $cases = [
            'zero quantity' => [static fn() => self::cube(quantity: 0),
                static function (array &$r): void { $r['items'][0]['quantity'] = 0; }],
            'negative side' => [static fn() => Dimensions::mm('-1', 100, 100),
                static function (array &$r): void { $r['items'][0]['dimensions']['length'] = '-1'; }],
            'unknown unit' => [static fn() => Length::of(1, 'furlong'),
                static function (array &$r): void { $r['units'] = ['length' => 'furlong']; }],
            'support ratio above one' => [static fn() => self::cube(minimumSupportRatio: 2.0),
                static function (array &$r): void { $r['items'][0]['minimum_support_ratio'] = 2; }],
            'zero container quantity' => [static fn() => self::box(quantity: 0),
                static function (array &$r): void { $r['containers'][0]['quantity'] = 0; }],
            'too few candidate points' => [static fn() => new PackingConfig(SolverProfile::Fast, maxCandidatePoints: 8),
                static function (array &$r): void { $r['configuration']['max_candidate_points'] = 8; }],
            'zero effort limit' => [static fn() => new EffortBudget(maxRestarts: 0),
                static function (array &$r): void { $r['configuration']['effort_budget'] = ['max_restarts' => 0]; }],
        ];
        foreach ($cases as $name => [$build, $edit]) {
            $fromObjects = self::refusal($build, $name);
            $fromJson = self::jsonRefusal($edit, $name);
            self::assertSame([$fromJson->errorCode(), $fromJson->reason(), ''], self::identity($fromObjects), $name);
        }
    }

    /** It used to be expanded into that many instances, which never finished. */
    /**: the bag exclusion `Container` itself does not refuse, and an empty fixed placement id. */
    public static function testTheRulesOnlyARequestCanCheckAreRefusedAsOnTheJsonPath(): void
    {
        $bag = new Container('box', Dimensions::mm(200, 100, 200), deformableBoundary: 'flat_bag', madeToMeasure: self::machine());
        $unnamed = new FixedPlacement('', 'box', new Point(0, 0, 0), Rotation::LWH);
        $cases = [
            'made to measure beside a flat bag' => [self::packed(null, [$bag]),
                static function (array &$r): void {
                    $r['containers'][0]['deformable_boundary'] = ['kind' => 'flat_bag'];
                    $r['containers'][0]['made_to_measure'] = self::JSON_MACHINE;
                }],
            'fixed placement without an item type' => [
                static fn() => (new Packer(PackingConfig::fast()))->pack([self::cube()], [self::box()], [$unnamed]),
                static function (array &$r): void {
                    $r['fixed_placements'] = [['item_type' => '', 'container_type' => 'box', 'container_instance' => 1,
                        'position' => ['x' => '0', 'y' => '0', 'z' => '0'], 'orientation' => 'LWH']];
                }],
        ];
        foreach ($cases as $name => [$build, $edit]) {
            $fromObjects = self::refusal($build, $name);
            $fromJson = self::jsonRefusal($edit, $name);
            self::assertSame(self::identity($fromJson), self::identity($fromObjects), $name);
            self::assertSame($fromJson->getMessage(), $fromObjects->getMessage(), $name);
        }
    }

    public static function testAQuantityPastTheExactRangeIsRefusedBeforeAnySolve(): void
    {
        $error = self::refusal(self::packed([self::cube(quantity: self::TOO_LARGE)]), 'quantity');
        self::assertSame(['above_maximum', '/items/0/quantity'], [$error->reason(), $error->field()]);
    }

    /** A set has no order, so insertion order must not pick which value an error names. */
    public static function testAcceptedStackingGroupsAreCheckedInCodePointOrder(): void
    {
        $error = self::refusal(self::packed([self::cube(acceptsStackingGroups: ['pallet', '', 'box'])]), 'groups');
        self::assertSame(['invalid_value', '/items/0/accepts_stacking_groups/0'], [$error->reason(), $error->field()]);
    }

    /** A wrapper that quotes the text (a commerce document) reads what it always read. */
    public static function testAConstructorRefusalKeepsItsOwnMessage(): void
    {
        $error = self::refusal(static fn() => self::cube(quantity: 0), 'zero quantity');
        self::assertSame('Item quantity must be positive', $error->getMessage());
    }

    /** A model rule the JSON table lacks is answered as every engine answers it. */
    public static function testTheJsonPathStillReportsWhatItsRulesDoNotNameAsInvalidValue(): void
    {
        $error = self::jsonRefusal(static function (array &$r): void {
            $r['containers'][0]['rate_table'] = ['weight_brackets_g' => [5000, 1000], 'prices_minor' => [500, 900]];
        }, 'descending brackets');
        self::assertSame(['invalid_value', ''], [$error->reason(), $error->field()]);
        self::assertSame('invalid_request: rate_table weight brackets must be strictly ascending', $error->getMessage());
    }

    /** @return array{0:string,1:string,2:string} */
    private static function identity(InvalidRequestException $error): array
    {
        return [$error->errorCode(), $error->reason(), $error->field()];
    }

    private static function cube(mixed ...$fields): Item
    {
        return Item::create('cube', Dimensions::mm(100, 100, 100), ...$fields);
    }

    private static function box(mixed ...$fields): Container
    {
        return Container::create('box', Dimensions::mm(200, 100, 200), ...$fields);
    }

    private static function madeToMeasureBox(MadeToMeasure $machine, ?Dimensions $outer = null): Container
    {
        return new Container('box', Dimensions::mm(200, 100, 200), $outer, madeToMeasure: $machine);
    }

    private static function machine(int $cost = 0): MadeToMeasure
    {
        $minimum = Length::mm(100)->ticks;
        $increment = Length::mm(50)->ticks;
        return new MadeToMeasure([$minimum, $minimum, $minimum], [$increment, $increment, $increment], $cost);
    }

    /**
     * @param list<Item>|null $items
     * @param list<Container>|null $containers
     */
    private static function packed(?array $items = null, ?array $containers = null, ?PackingConfig $config = null): callable
    {
        return static fn() => (new Packer($config ?? PackingConfig::fast()))->pack($items ?? [self::cube()], $containers ?? [self::box()]);
    }

    private static function refusal(callable $build, string $name): InvalidRequestException
    {
        try {
            $build();
        } catch (InvalidRequestException $error) {
            return $error;
        }
        throw new RuntimeException("{$name}: the objects were accepted");
    }

    private static function jsonRefusal(callable $edit, string $name): InvalidRequestException
    {
        $request = [
            'items' => [['id' => 'cube', 'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100']]],
            'containers' => [['id' => 'box', 'inner_dimensions' => ['length' => '200', 'width' => '100', 'height' => '200']]],
            'configuration' => ['solver_profile' => 'fast'],
        ];
        $edit($request);
        return self::refusal(static fn() => ArrayCodec::pack($request), "{$name} (JSON)");
    }
}
