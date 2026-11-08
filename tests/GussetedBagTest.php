<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Algorithm\{CandidateFinder, ContainerState, Deadline, GridSolver, HomogeneousBlockSolver, SearchStats};
use Packvium\Extension\DefaultCandidateScorer;
use Packvium\Config\PackingConfig;
use Packvium\Constraint\ConstraintSet;
use Packvium\Domain\{Container, Dimensions, FlatBag, Item, ItemInstance, PackedContainer, PackingRequest, Placement, Point, Rotation};
use Packvium\Serialization\{ArrayCodec, InvalidRequestException};
use Packvium\Unit\{Length, Weight};
use Packvium\Validation\{FixedPlacementException, IndependentSolutionValidator};

/**
 * 1.10: gusseted bags, admitted by the flat-bag rule with the width girth b + c <= W + G.
 * Mirrors `packvium-python/tests/test_gusseted_bag.py`.
 */
final class GussetedBagTest extends TestCase
{
    public static function testTheGussetWidensOnlyTheWidthGirth(): void
    {
        $inner = Dimensions::mm(300, 150, 100);
        self::assertFalse(FlatBag::girthAdmits($inner, self::ticks(200), self::ticks(100), self::ticks(60)));
        self::assertTrue(FlatBag::girthAdmits($inner, self::ticks(200), self::ticks(100), self::ticks(60), self::ticks(10)));
        self::assertFalse(FlatBag::girthAdmits($inner, self::ticks(200), self::ticks(100), self::ticks(60) + 1, self::ticks(10)));
        self::assertFalse(FlatBag::girthAdmits($inner, self::ticks(241), 0, self::ticks(60), self::ticks(150)));
    }

    public static function testASingleBoxFitsAGussetedBagItsFlatTwinRefuses(): void
    {
        $inner = Dimensions::mm(200, 150, 100);
        self::assertFalse(FlatBag::fitsAlone($inner, Dimensions::mm(100, 100, 60)));
        self::assertTrue(FlatBag::fitsAlone($inner, Dimensions::mm(100, 100, 60), self::ticks(10)));
        self::assertFalse(FlatBag::fitsAlone($inner, Dimensions::mm(100, 160, 10), self::ticks(150)));
    }

    public static function testTheDepthIsKeptAndReadInTicks(): void
    {
        self::assertSame(self::ticks(25), self::pouch(25)->sideGussetTicks());
        self::assertTrue(self::pouch()->isFlatBag());
        self::assertSame(0, Container::create('box', Dimensions::mm(1, 1, 1))->sideGussetTicks());
        self::assertSame(self::ticks(150), self::pouch(150)->sideGussetTicks());
    }

    public static function testAMalformedGussetIsRefused(): void
    {
        $cases = [
            ['gusseted_bag', null, 'missing_field', 'a gusseted_bag requires side_gusset_depth'],
            ['flat_bag', new Length(1), 'not_allowed', 'side_gusset_depth requires deformable_boundary gusseted_bag'],
            [null, new Length(1), 'not_allowed', 'side_gusset_depth requires deformable_boundary gusseted_bag'],
            ['gusseted_bag', new Length(0), 'invalid_value', 'side_gusset_depth must be positive'],
            ['gusseted_bag', new Length(self::ticks(150) + 1), 'invalid_value', 'side_gusset_depth exceeds inner_dimensions width'],
        ];
        foreach ($cases as [$kind, $depth, $reason, $detail]) {
            $error = self::caught(static fn() => Container::create('pouch', Dimensions::mm(300, 150, 100), 0, null, null, 0, null, [], [], null, [], 0.0, [], null, null, null, [], $kind, $depth));
            self::assertSame([$reason, $detail], [$error->reason(), $error->detail()]);
        }
        $outer = self::caught(static fn() => new Container('pouch', Dimensions::mm(300, 150, 100), Dimensions::mm(310, 160, 110), deformableBoundary: 'gusseted_bag', sideGussetDepth: Length::mm(10)));
        self::assertSame('outer_dimensions cannot be combined with deformable_boundary', $outer->detail());
    }

    public static function testTheJsonPathReadsTheDepthInTheRequestUnit(): void
    {
        $result = ArrayCodec::pack(self::request(3, self::gusseted(['value' => '1', 'unit' => 'cm'])) + ['units' => ['length' => 'mm']]);
        self::assertSame([], $result['unpacked_items']);
    }

    public static function testAFlatBagRefusesAGussetAsAnUnknownField(): void
    {
        $error = self::caught(static fn() => ArrayCodec::pack(self::request(1, ['kind' => 'flat_bag', 'side_gusset_depth' => '10'])));
        self::assertSame(['/containers/0/deformable_boundary/side_gusset_depth', 'not_allowed'], [$error->field(), $error->reason()]);
    }

    public static function testTheGussetAdmitsTheLayerTheFlatBagRefuses(): void
    {
        $flat = ArrayCodec::pack(self::request(4, ['kind' => 'flat_bag']));
        $wide = ArrayCodec::pack(self::request(4, self::gusseted('25')));
        self::assertSame([2], array_map(static fn(array $c): int => count($c['placements']), $flat['containers']));
        self::assertSame([3], array_map(static fn(array $c): int => count($c['placements']), $wide['containers']));
        self::assertSame('60', $wide['containers'][0]['achieved_dimensions']['height']['value']);
    }

    public static function testTheLatticeAndBlockSolversDeferToTheCandidateCheck(): void
    {
        $items = self::book(4)->instances();
        $config = new PackingConfig();
        $solution = (new GridSolver())->packOne(self::pouch(), 1, $items, $config, new SearchStats(), Deadline::ofMilliseconds(60_000));
        self::assertFalse($solution->dominantLattice);
        self::assertCount(3, $solution->state->placements);
        self::assertFalse((new HomogeneousBlockSolver())->supports(self::pouch(), $items, $config));
    }

    public static function testACandidateOneTickPastTheWidenedGirthIsNeverProposed(): void
    {
        $book = self::book(4);
        $state = new ContainerState(self::pouch(), 1);
        foreach ([0, 1, 2] as $level) {
            $state->add(self::placed($book, $level + 1, 20 * $level));
        }
        $config = new PackingConfig();
        $candidates = CandidateFinder::find($state, new ItemInstance($book, 4), $config, ConstraintSet::defaults($config->minimumSupportRatio),
            new SearchStats(), Deadline::ofMilliseconds(60_000), new DefaultCandidateScorer());
        self::assertSame([], $candidates);
    }

    public static function testAnItemOnlyTheGussetAdmitsIsNotProvenUnfit(): void
    {
        $base = self::request(1, ['kind' => 'flat_bag']);
        $base['items'] = [['id' => 'brick', 'allowed_rotations' => ['LWH'], 'dimensions' => ['length' => '100', 'width' => '100', 'height' => '60']]];
        $base['containers'][0]['inner_dimensions']['length'] = '200';
        self::assertSame('no_compatible_container_dimensions', ArrayCodec::pack($base)['unpacked_items'][0]['reason']);
        $base['containers'][0]['deformable_boundary'] = self::gusseted('10');
        self::assertSame([], ArrayCodec::pack($base)['unpacked_items']);
    }

    public static function testAGussetedBagIsBilledOnItsAchievedPackage(): void
    {
        $packed = new PackedContainer(self::pouch(), 1, [self::placed(self::book(1), 1, 0)]);
        self::assertEquals(Dimensions::mm(200, 100, 20), $packed->billedDimensions());
        $config = new PackingConfig(dimensionalWeightDivisor: 5000, dimensionalWeightLengthUnit: 'cm', dimensionalWeightWeightUnit: 'kg');
        $state = new ContainerState(self::pouch(), 1);
        $state->add(self::placed(self::book(1), 1, 0));
        self::assertSame(Weight::parse('80 g')->ticks, $state->billedDimensionalWeight($config));
    }

    public static function testTheValidatorReadsTheGusset(): void
    {
        $book = self::book(3);
        $three = [self::placed($book, 1, 0), self::placed($book, 2, 20), self::placed($book, 3, 40)];
        $validator = new IndependentSolutionValidator();
        self::assertSame([], $validator->validate(new PackingRequest([$book], [self::pouch()]), [new PackedContainer(self::pouch(), 1, $three)])->issues);
        $narrow = self::pouch(9);
        $issues = $validator->validate(new PackingRequest([$book], [$narrow]), [new PackedContainer($narrow, 1, $three)])->issues;
        self::assertSame([['bag_girth_exceeded', 'pouch#1']], array_map(static fn($issue): array => [$issue->code, $issue->detail], $issues));
    }

    public static function testAFixedSetOneTickPastTheWidenedGirthCannotHold(): void
    {
        $fixed = [];
        foreach ([0, 20, 40] as $z) {
            $fixed[] = ['item_type' => 'book', 'container_type' => 'pouch', 'orientation' => 'LWH', 'position' => ['x' => '0', 'y' => '0', 'z' => (string) $z]];
        }
        $past = self::request(3, self::gusseted(['value' => (string) (self::ticks(10) - 1), 'unit' => 'ticks'])) + ['fixed_placements' => $fixed];
        try {
            ArrayCodec::pack($past);
            throw new \RuntimeException('the fixed set was admitted');
        } catch (FixedPlacementException $error) {
            self::assertSame('invalid_fixed_placement: bag_girth_exceeded: pouch#1', $error->getMessage());
        }
        self::assertSame([], ArrayCodec::pack(self::request(3, self::gusseted('10')) + ['fixed_placements' => $fixed])['unpacked_items']);
    }

    private static function ticks(int $millimetres): int
    {
        return Length::mm($millimetres)->ticks;
    }

    private static function pouch(int $gusset = 10): Container
    {
        return Container::create('pouch', Dimensions::mm(300, 150, 100), 0, null, null, 0, null, [], [], null, [], 0.0, [], null, null, null, [], 'gusseted_bag', Length::mm($gusset));
    }

    private static function book(int $quantity): Item
    {
        return Item::create('book', Dimensions::mm(200, 100, 20), '300 g', $quantity, [Rotation::LWH]);
    }

    private static function placed(Item $item, int $sequence, int $z): Placement
    {
        $origin = new Point(0, 0, self::ticks($z));
        return new Placement(new ItemInstance($item, $sequence), $origin, Rotation::LWH, $item->dimensions, $origin, $item->dimensions);
    }

    /** @param mixed $depth @return array<string,mixed> */
    private static function gusseted($depth): array
    {
        return ['kind' => 'gusseted_bag', 'side_gusset_depth' => $depth];
    }

    /** @param array<string,mixed> $boundary @return array<string,mixed> */
    private static function request(int $quantity, array $boundary): array
    {
        return ['items' => [['id' => 'book', 'quantity' => $quantity, 'weight' => '300 g', 'allowed_rotations' => ['LWH'],
                'dimensions' => ['length' => '200', 'width' => '100', 'height' => '20']]],
            'containers' => [['id' => 'pouch', 'quantity' => 1, 'deformable_boundary' => $boundary,
                'inner_dimensions' => ['length' => '300', 'width' => '150', 'height' => '100']]],
            'configuration' => ['solver_profile' => 'fast', 'time_limit_ms' => 60000, 'effort_budget' => ['max_search_nodes' => 100000]]];
    }

    private static function caught(callable $build): InvalidRequestException
    {
        try {
            $build();
        } catch (InvalidRequestException $error) {
            return $error;
        }
        throw new \RuntimeException('was not refused');
    }
}
