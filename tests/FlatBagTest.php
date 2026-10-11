<?php
declare(strict_types=1);

namespace Packvium\Tests;

use InvalidArgumentException;
use Packvium\Algorithm\{ContainerState, Deadline, GridSolver, HomogeneousBlockSolver, SearchStats};
use Packvium\Config\PackingConfig;
use Packvium\Constraint\{ConstraintContext, StopAccessibilityConstraint};
use Packvium\Domain\{Container, Dimensions, FlatBag, Item, ItemInstance, PackedContainer, PackingRequest, Placement, Point, Rotation};
use Packvium\Serialization\{ArrayCodec, InvalidRequestException};
use Packvium\Unit\{Length, Weight};
use Packvium\Validation\{FixedPlacementException, IndependentSolutionValidator};

/**
 * Flat bags, admitted by the girth rule a + c <= L and b + c <= W.
 * Mirrors `packvium-python/tests/test_flat_bag.py` on the oracle's fixed cases.
 */
final class FlatBagTest extends TestCase
{
    private const BAG = ['deformable_boundary' => ['kind' => 'flat_bag']];

    public static function testTheWorkedExamplesOfTheOracle(): void
    {
        $inner = Dimensions::mm(240, 140, 100);
        self::assertTrue(FlatBag::girthAdmits($inner, self::ticks(200), self::ticks(100), self::ticks(40)));
        self::assertFalse(FlatBag::girthAdmits($inner, self::ticks(200), self::ticks(100), self::ticks(40) + 1));
        self::assertFalse(FlatBag::girthAdmits(Dimensions::mm(1000, 480, 300), self::ticks(1000), self::ticks(10), self::ticks(10)));
        self::assertFalse(FlatBag::fitsAlone(Dimensions::mm(300, 300, 100), Dimensions::mm(250, 150, 80)));
        self::assertTrue(FlatBag::fitsAlone(Dimensions::mm(300, 300, 100), Dimensions::mm(220, 150, 80)));
        self::assertSame([0, 0, 0], FlatBag::extentOf([]));
    }

    public static function testTheRunningExtentEqualsTheExtentFromScratch(): void
    {
        $book = self::book(3);
        $state = new ContainerState(self::bag(), 1);
        $placements = [];
        foreach ([[0, 0, 0], [0, 0, 20], [30, 10, 0]] as $index => [$x, $y, $z]) {
            $placement = self::placed($book, $index + 1, $x, $y, $z);
            $placements[] = $placement;
            $state->add($placement);
            self::assertSame(FlatBag::extentOf($placements), [$state->maxX, $state->maxY, $state->maxZ]);
        }
    }

    public static function testABagRefusesEveryWallAnchoredField(): void
    {
        self::assertTrue(self::bag()->isFlatBag());
        $refusals = [
            fn() => new Container('m', Dimensions::mm(240, 140, 100), Dimensions::mm(250, 150, 110), deformableBoundary: 'flat_bag'),
            fn() => Container::create('m', Dimensions::mm(240, 140, 100), 0, null, null, 0, null, [], [], null, [], 0.1, [], null, null, null, [], 'flat_bag'),
            fn() => Container::create('m', Dimensions::mm(240, 140, 100), 0, null, null, 0, null, [], [], null, [], 0.0, [], 5, null, null, [], 'flat_bag'),
            fn() => Container::create('m', Dimensions::mm(240, 140, 100), 0, null, null, 0, null, [], [], null, [], 0.0, [], null, null, null, ['+x'], 'flat_bag'),
            fn() => Container::create('m', Dimensions::mm(1, 1, 1), 0, null, null, 0, null, [], [], null, [], 0.0, [], null, null, null, [], 'gusseted'),
        ];
        foreach ($refusals as $refusal) {
            self::assertThrows(InvalidArgumentException::class, $refusal);
        }
    }

    public static function testAMalformedBagIsRefusedWithItsPointer(): void
    {
        $cases = [
            [['deformable_boundary' => 'flat_bag'], '/deformable_boundary', 'wrong_type', 'must be an object'],
            [['deformable_boundary' => []], '/deformable_boundary/kind', 'missing_field', 'is required'],
            [['deformable_boundary' => ['kind' => 'pouch']], '/deformable_boundary/kind', 'not_allowed', 'must be one of ["flat_bag"]'],
            [['deformable_boundary' => ['kind' => 'flat_bag', 'seam' => 1]], '/deformable_boundary/seam', 'not_allowed', 'is not a known field'],
            [self::BAG + ['made_to_measure' => []], '/made_to_measure', 'not_allowed', 'cannot be combined with deformable_boundary'],
            [self::BAG + ['obstacles' => [['id' => 'o', 'dimensions' => ['length' => '1', 'width' => '1', 'height' => '1']]]], '/obstacles', 'not_allowed', 'cannot be combined with deformable_boundary'],
            [self::BAG + ['void_fill_reserve_ratio' => 0.5], '/void_fill_reserve_ratio', 'not_allowed', 'cannot be combined with deformable_boundary'],
        ];
        foreach ($cases as [$fields, $field, $reason, $detail]) {
            try {
                ArrayCodec::pack(self::request([self::books(1)], [['id' => 'mailer', 'inner_dimensions' => ['length' => '300', 'width' => '200', 'height' => '100']] + $fields]));
                throw new \RuntimeException("{$field} was not refused");
            } catch (InvalidRequestException $error) {
                self::assertSame(["/containers/0{$field}", $reason, $detail], [$error->field(), $error->reason(), $error->detail()]);
            }
        }
        $inert = self::mailer() + ['obstacles' => [], 'void_fill_reserve_ratio' => 0];
        self::assertCount(1, ArrayCodec::pack(self::request([self::books(1)], [$inert]))['containers']);
    }

    public static function testGirthNotTheFrameDecidesHowHighABagStacks(): void
    {
        $result = ArrayCodec::pack(self::request([self::books(3)], [self::mailer() + ['quantity' => 1]]));
        self::assertSame([[200, 100, 40]], array_map([self::class, 'extentMm'], $result['containers']));
        self::assertSame('container_inventory_exhausted', $result['unpacked_items'][0]['reason']);
        $opened = ArrayCodec::pack(self::request([self::books(3)], [self::mailer()]));
        self::assertCount(2, $opened['containers']);
    }

    public static function testAnItemNoRotationAdmitsIsProvenUnplaceable(): void
    {
        $carton = ['id' => 'carton', 'dimensions' => ['length' => '250', 'width' => '150', 'height' => '80']];
        $result = ArrayCodec::pack(self::request([$carton], [self::mailer('300', '300')]));
        self::assertSame('no_compatible_container_dimensions', $result['unpacked_items'][0]['reason']);
        $restricted = ArrayCodec::pack(self::request([$carton + ['allowed_rotations' => ['LHW']]], [self::mailer('330', '300')]));
        self::assertSame('rotation_restricted', $restricted['unpacked_items'][0]['reason']);
    }

    public static function testTheLatticeAndBlockSolversDeferToTheCandidateCheck(): void
    {
        $items = self::book(3)->instances();
        $config = new PackingConfig();
        $solution = (new GridSolver())->packOne(self::bag(), 1, $items, $config, new SearchStats(), Deadline::ofMilliseconds(60_000));
        self::assertFalse($solution->dominantLattice);
        self::assertCount(2, $solution->state->placements);
        self::assertFalse((new HomogeneousBlockSolver())->supports(self::bag(), $items, $config));
    }

    public static function testABagIsBilledOnItsAchievedPackage(): void
    {
        $packed = new PackedContainer(self::bag(300, 250), 1, [self::placed(self::book(1), 1, 0, 0, 0)]);
        self::assertEquals(Dimensions::mm(200, 100, 20), $packed->billedDimensions());
        self::assertEquals(Dimensions::mm(200, 100, 20), $packed->asItem()->dimensions);
        $rigid = new PackedContainer(Container::create('box', Dimensions::mm(300, 250, 100)), 1, []);
        self::assertNull($rigid->achievedDimensions());
        $config = new PackingConfig(dimensionalWeightDivisor: 5000, dimensionalWeightLengthUnit: 'cm', dimensionalWeightWeightUnit: 'kg');
        $state = new ContainerState(self::bag(), 1);
        self::assertSame(0, $state->billedDimensionalWeight($config));
        $state->add(self::placed(self::book(1), 1, 0, 0, 0));
        self::assertSame(Weight::parse('80 g')->ticks, $state->billedDimensionalWeight($config));
        foreach (['lowest_landed_cost' => 400, 'shipping_cost' => Weight::parse('300 g')->ticks] as $objective => $expected) {
            foreach (['fast', 'exact_small'] as $profile) {
                $rate = ['weight_brackets_g' => [500, 1000, 2000], 'prices_minor' => [400, 700, 1100]];
                $carton = ['id' => 'carton', 'inner_dimensions' => ['length' => '300', 'width' => '200', 'height' => '150'], 'rate_table' => $rate];
                $result = ArrayCodec::pack(self::request([self::books(1)], [$carton, self::mailer('300', '250') + ['rate_table' => $rate]], [
                    'objective' => $objective, 'solver_profile' => $profile, 'dimensional_weight_divisor' => 5000,
                    'dimensional_weight_length_unit' => 'cm', 'dimensional_weight_weight_unit' => 'kg',
                ]));
                self::assertSame('mailer', $result['containers'][0]['container_type']);
                self::assertSame($expected, $result['score'][1]);
            }
        }
    }

    public static function testTheValidatorAndFixedAdmissionReportABagPastItsGirth(): void
    {
        $book = self::book(3);
        $packed = new PackedContainer(self::bag(), 1, [self::placed($book, 1, 0, 0, 0), self::placed($book, 2, 0, 0, 20), self::placed($book, 3, 0, 0, 40)]);
        $report = (new IndependentSolutionValidator())->validate(new PackingRequest([$book], [self::bag()]), [$packed]);
        self::assertSame('bag_girth_exceeded', $report->issues[0]->code);
        self::assertSame('mailer#1', $report->issues[0]->detail);
        $fixed = [];
        foreach ([0, 20, 40] as $z) {
            $fixed[] = ['item_type' => 'book', 'container_type' => 'mailer', 'orientation' => 'LWH', 'position' => ['x' => '0', 'y' => '0', 'z' => (string) $z]];
        }
        try {
            ArrayCodec::pack(self::request([self::books(3)], [self::mailer()]) + ['fixed_placements' => $fixed]);
            throw new \RuntimeException('the fixed set was admitted');
        } catch (FixedPlacementException $error) {
            self::assertSame('invalid_fixed_placement: bag_girth_exceeded: mailer#1', $error->getMessage());
        }
    }

    public static function testABagInheritsNoDoors(): void
    {
        $item = Item::create('parcel', Dimensions::mm(100, 100, 20), 0, 2, [Rotation::LWH], stopIndex: 1);
        $context = new ConstraintContext(self::bag(), [], $item->instances()[0], new Point(0, 0, 0), Rotation::LWH,
            $item->dimensions, $item->dimensions, true, true);
        self::assertTrue((new StopAccessibilityConstraint(['+x']))->evaluate($context)->allowed);
        $packer = new \Packvium\Packer(new PackingConfig(accessDirections: ['+x']));
        self::assertCount(2, $packer->pack([$item], [self::bag()])->containers[0]->placements);
    }

    private static function ticks(int $millimetres): int
    {
        return Length::mm($millimetres)->ticks;
    }

    private static function bag(int $length = 240, int $width = 140): Container
    {
        return Container::create('mailer', Dimensions::mm($length, $width, 100), 0, null, null, 0, null, [], [], null, [], 0.0, [], null, null, null, [], 'flat_bag');
    }

    private static function book(int $quantity): Item
    {
        return Item::create('book', Dimensions::mm(200, 100, 20), '300 g', $quantity, [Rotation::LWH]);
    }

    private static function placed(Item $item, int $sequence, int $x, int $y, int $z): Placement
    {
        $origin = new Point(self::ticks($x), self::ticks($y), self::ticks($z));
        return new Placement(new ItemInstance($item, $sequence), $origin, Rotation::LWH, $item->dimensions, $origin, $item->dimensions);
    }

    /** @return array<string,mixed> */
    private static function books(int $quantity): array
    {
        return ['id' => 'book', 'quantity' => $quantity, 'weight' => '300 g', 'allowed_rotations' => ['LWH'],
            'dimensions' => ['length' => '200', 'width' => '100', 'height' => '20']];
    }

    /** @return array<string,mixed> */
    private static function mailer(string $length = '240', string $width = '140'): array
    {
        return ['id' => 'mailer', 'inner_dimensions' => ['length' => $length, 'width' => $width, 'height' => '100']] + self::BAG;
    }

    /**
     * @param list<array<string,mixed>> $items @param list<array<string,mixed>> $containers @param array<string,mixed> $configuration
     * @return array<string,mixed>
     */
    private static function request(array $items, array $containers, array $configuration = []): array
    {
        return ['items' => $items, 'containers' => $containers,
            'configuration' => $configuration + ['solver_profile' => 'fast', 'time_limit_ms' => 60000, 'effort_budget' => ['max_search_nodes' => 100000]]];
    }

    /** @param array<string,mixed> $container @return list<int> */
    private static function extentMm(array $container): array
    {
        return array_map(static fn(string $side): int => (int) $container['achieved_dimensions'][$side]['value'], ['length', 'width', 'height']);
    }
}
