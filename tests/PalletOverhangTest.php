<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Algorithm\{ContainerState, Deadline, SearchStats, SingleContainerSolution, SingleContainerSolver, TimeLimitReached};
use Packvium\Algorithm\Internal\PalletTrial;
use Packvium\Config\PackingConfig;
use Packvium\Constraint\{ConstraintContext, SupportConstraint};
use Packvium\Domain\{AxisAlignedBox, Container, Deck, Dimensions, Item, ItemInstance, OverhangLimit, PalletOverhang, Placement, Point, Rotation};
use Packvium\Packer;
use Packvium\Serialization\{ArrayCodec, InvalidRequestException, UnsupportedFeatureException};
use Packvium\Support\ResultValidation\Validator;
use Packvium\Unit\{Length, Weight};

/**
 * A pallet whose load may overhang its deck by `pallet_overhang_limit`. Mirrors
 * `packvium-python/tests/test_pallet_overhang.py` on the conformance fixtures' shapes: a
 * 1200 x 800 deck, a 1250 x 850 crate that needs the overhang, and cartons the deck holds.
 */
final class PalletOverhangTest extends TestCase
{
    private const LIMIT = ['length' => '50', 'width' => '50'];

    public static function testTheFrameWidensByTheLimitAndCarriesTheDeck(): void
    {
        $pallet = self::pallet(new OverhangLimit(Length::mm(50), Length::mm(30)));
        $frame = PalletOverhang::solvingFrame($pallet);
        self::assertSame([self::ticks(1300), self::ticks(860), self::ticks(1000)], self::sides($frame->innerDimensions));
        self::assertEquals(new Deck(self::ticks(50), self::ticks(30), self::ticks(1200), self::ticks(800)), $frame->deck);
        self::assertNull($frame->palletOverhangLimit);
        self::assertSame($frame, PalletOverhang::solvingFrame($frame));
        self::assertSame([self::ticks(1200), self::ticks(800), self::ticks(1000)], self::sides($frame->declaredInner()));
        $deck = PalletOverhang::deckAlone($frame);
        self::assertNull($deck->deck);
        self::assertSame(self::sides($pallet->innerDimensions), self::sides($deck->innerDimensions));
        $plain = self::pallet(null);
        self::assertSame($plain, PalletOverhang::solvingFrame($plain));
    }

    public static function testAFloorPlacementRestsOnItsOverlapWithTheDeck(): void
    {
        $deck = new Deck(self::ticks(50), self::ticks(50), self::ticks(1200), self::ticks(800));
        $onTheEdge = self::box(1200, 100, 100);
        self::assertSame(1.0, PalletOverhang::floorSupportRatio(null, $onTheEdge));
        self::assertSame(0.5, PalletOverhang::floorSupportRatio($deck, $onTheEdge));
        self::assertSame(self::ticks(50) * self::ticks(100), PalletOverhang::deckContactArea($deck, $onTheEdge));
        self::assertSame(0, PalletOverhang::deckContactArea($deck, self::box(0, 0, 40)));
    }

    public static function testTheSupportRuleJudgesTheDeckAsItsOnlySupporter(): void
    {
        $frame = PalletOverhang::solvingFrame(self::pallet(new OverhangLimit(Length::mm(50), Length::mm(50))));
        // 50 of 100 mm on the deck: a ratio of 0.5, centroid on the deck's edge.
        self::assertSame([true, null], self::verdict($frame, 0.0, 1200));
        self::assertSame([false, 'insufficient_support'], self::verdict($frame, 0.75, 1200));
        self::assertSame([true, null], self::verdict($frame, 0.5, 1200));
        // 40 of 100 mm: the ratio passes at 0.4, but the centroid hangs past the deck.
        self::assertSame([false, 'centre_of_gravity_unsupported'], self::verdict($frame, 0.4, 1210));
        // Without a deck the floor is whole support.
        self::assertSame([true, null], self::verdict(self::pallet(null), 0.95, 1100));
    }

    public static function testTheDeckIsPackedFirstWhenItHoldsEverything(): void
    {
        $carton = ['id' => 'carton', 'quantity' => 6, 'weight' => '10 kg',
            'dimensions' => ['length' => '400', 'width' => '400', 'height' => '300']];
        $result = ArrayCodec::pack(self::request([$carton], [self::palletJson()]));
        self::assertSame(true, $result['complete']);
        $container = $result['containers'][0];
        self::assertSame([1200, 800], array_slice(self::millimetres($container['achieved_dimensions']), 0, 2));
        self::assertSame([1200, 800, 1000], self::millimetres($container['inner_dimensions']));
        foreach ($container['placements'] as $placement) {
            $x = (int) $placement['position']['x']['value'];
            $y = (int) $placement['position']['y']['value'];
            self::assertTrue($x >= 50 && $x + 400 <= 1250 && $y >= 50 && $y + 400 <= 850, "{$x},{$y} overhangs");
            if ((int) $placement['position']['z']['value'] === 0) {
                self::assertSame('1.000000', $placement['support_ratio']);
            }
        }
    }

    public static function testTheOverhangIsUsedOnlyWhenItPlacesMore(): void
    {
        $result = ArrayCodec::pack(self::request([self::crate()], [self::palletJson()]));
        self::assertSame(true, $result['complete']);
        $container = $result['containers'][0];
        // Longer than the deck on both axes, so seated centred: 25 mm past every edge.
        self::assertSame([1250, 850, 400], self::millimetres($container['achieved_dimensions']));
        self::assertSame(['25', '25'], [$container['placements'][0]['position']['x']['value'], $container['placements'][0]['position']['y']['value']]);
        self::assertSame([1200, 800, 1000], self::millimetres($container['outer_dimensions']));

        $forbidden = ArrayCodec::pack(self::request([self::crate()], [self::palletJson(['length' => '0', 'width' => '0'])]));
        self::assertSame(false, $forbidden['complete']);
        self::assertSame([], $forbidden['containers']);
    }

    public static function testTheDeckRefusesALoadItCannotCarry(): void
    {
        $carton = ['id' => 'carton', 'quantity' => 2, 'weight' => '5 kg', 'dimensions' => ['length' => '400', 'width' => '300', 'height' => '200']];
        $result = ArrayCodec::pack(self::request([self::crate(), $carton], [self::palletJson()], ['minimum_support_ratio' => 0.95]));
        self::assertSame(['insufficient_support'], array_column($result['unpacked_items'], 'reason'));
        self::assertSame([1200, 800, 200], self::millimetres($result['containers'][0]['achieved_dimensions']));
    }

    public static function testAPalletIsBilledAtTheFootprintItsLoadReached(): void
    {
        $limit = new OverhangLimit(Length::mm(50), Length::mm(50));
        $crate = Item::create('crate', Dimensions::mm(1250, 850, 400), '10 kg', 1, [Rotation::LWH]);
        $carton = Item::create('carton', Dimensions::mm(400, 400, 300), '10 kg', 1, [Rotation::LWH]);
        $outer = Dimensions::mm(1210, 810, 1150);
        self::assertSame([1250, 850, 1150], self::billedMm($crate, self::pallet($limit, $outer)));
        self::assertSame([1250, 850, 1000], self::billedMm($crate, self::pallet($limit)));
        self::assertSame([1210, 810, 1150], self::billedMm($carton, self::pallet($limit, $outer)));
        self::assertSame([1200, 800, 1000], self::billedMm($carton, self::pallet($limit)));
        $packed = (new Packer())->pack([$crate], [self::pallet($limit, $outer)])->containers[0];
        self::assertSame([self::ticks(1250), self::ticks(850), self::ticks(1150)], self::sides($packed->asItem()->dimensions));
    }

    public static function testEveryUnsupportedCombinationIsNamed(): void
    {
        $combinations = [
            'access_directions' => ['access_directions' => ['+x']],
            'axles' => ['axles' => [['position' => '0'], ['position' => '100']]],
            'made_to_measure' => ['made_to_measure' => ['minimum' => ['length' => '10', 'width' => '10', 'height' => '10'], 'increment' => ['length' => '10', 'width' => '10', 'height' => '10']]],
            'obstacles' => ['obstacles' => [['id' => 'o', 'dimensions' => ['length' => '1', 'width' => '1', 'height' => '1']]]],
            'void_fill_reserve_ratio' => ['void_fill_reserve_ratio' => 0.1],
        ];
        foreach ($combinations as $name => $fields) {
            self::assertSame(["container.pallet_overhang_limit+{$name}"], ArrayCodec::palletOverhangCombinations(self::request([self::crate()], [self::palletJson() + $fields])));
        }
        $inert = self::palletJson() + ['access_directions' => [], 'obstacles' => [], 'void_fill_reserve_ratio' => 0, 'axles' => null];
        self::assertSame([], ArrayCodec::palletOverhangCombinations(self::request([self::crate()], [$inert])));
        $routed = self::request([self::crate() + ['stop_index' => 1]], [self::palletJson()]);
        self::assertSame(['container.pallet_overhang_limit+item.stop_index'], ArrayCodec::palletOverhangCombinations($routed));
        self::assertThrows(UnsupportedFeatureException::class, static fn() => ArrayCodec::pack($routed));
        $unpalleted = self::request([self::crate() + ['stop_index' => 1]], [self::palletJson(null)]);
        self::assertSame([], ArrayCodec::palletOverhangCombinations($unpalleted));
    }

    public static function testAMalformedLimitIsRefusedWithItsPointer(): void
    {
        $cases = [
            ['50', '', 'wrong_type', 'must be an object'],
            [['width' => '50'], '/length', 'missing_field', 'is required'],
            [['length' => '50'], '/width', 'missing_field', 'is required'],
            [['length' => '50', 'width' => '50', 'height' => '1', 'depth' => '1'], '/depth', 'not_allowed', 'is not a known field'],
            [['length' => '-1', 'width' => '0'], '/length', 'negative_measure', 'cannot be negative'],
            [['length' => ['value' => '4503599627370496', 'unit' => 'ticks'], 'width' => '0'], '/length', 'above_maximum', 'must be at most 9007199254740991 ticks'],
            [['length' => '0', 'width' => ['value' => '4503599627370496', 'unit' => 'ticks']], '/width', 'above_maximum', 'must be at most 9007199254740991 ticks'],
            [['length' => ['value' => '300000000000', 'unit' => 'ticks'], 'width' => ['value' => '300000000000', 'unit' => 'ticks']], '', 'above_maximum', 'must enclose at most 10^30 cubic ticks'],
        ];
        foreach ($cases as [$limit, $suffix, $reason, $detail]) {
            self::assertRefused(self::palletJson($limit), "/containers/0/pallet_overhang_limit{$suffix}", $reason, $detail);
        }
        $bag = self::palletJson() + ['deformable_boundary' => ['kind' => 'flat_bag']];
        self::assertRefused($bag, '/containers/0/pallet_overhang_limit', 'not_allowed', 'cannot be combined with deformable_boundary');
    }

    public static function testTheObjectPathRefusesWhatTheJsonPathRefuses(): void
    {
        $limit = new OverhangLimit(Length::mm(50), Length::mm(50));
        $inner = Dimensions::mm(1200, 800, 1000);
        $axle = new \Packvium\Domain\Axle(Length::mm(0));
        $refusals = [
            'obstacles' => static fn() => new Container('p', $inner, null, new Weight(0), null, 0, null, [new \Packvium\Domain\Obstacle('o', new AxisAlignedBox(new Point(0, 0, 0), Dimensions::mm(1, 1, 1)))], [], null, [], 0.0, [], null, null, null, [], [], null, null, $limit),
            'axles' => static fn() => new Container('p', $inner, null, new Weight(0), null, 0, null, [], [], null, [], 0.0, [], null, [$axle, new \Packvium\Domain\Axle(Length::mm(100))], null, [], [], null, null, $limit),
            'access_directions' => static fn() => new Container('p', $inner, null, new Weight(0), null, 0, null, [], [], null, [], 0.0, [], null, null, null, ['+x'], [], null, null, $limit),
            'void_fill_reserve_ratio' => static fn() => new Container('p', $inner, null, new Weight(0), null, 0, null, [], [], null, [], 0.1, [], null, null, null, [], [], null, null, $limit),
            'made_to_measure' => static fn() => new Container('p', $inner, null, new Weight(0), null, 0, null, [], [], null, [], 0.0, [], null, null, null, [], [], null, new \Packvium\Domain\MadeToMeasure([1, 1, 1], [1, 1, 1], 0, 0, [0, 0, 0]), $limit),
        ];
        foreach ($refusals as $name => $build) {
            $error = self::caught($build);
            self::assertSame(['not_allowed', "{$name} cannot be combined with pallet_overhang_limit"], [$error->reason(), $error->detail()]);
        }
        $bag = self::caught(static fn() => new Container('p', $inner, null, new Weight(0), null, 0, null, [], [], null, [], 0.0, [], null, null, null, [], [], 'flat_bag', null, $limit));
        self::assertSame('pallet_overhang_limit cannot be combined with deformable_boundary', $bag->detail());
        $vast = new OverhangLimit(new Length(4503599627370496), new Length(0));
        $error = self::caught(static fn() => (new Packer())->pack([Item::create('c', Dimensions::mm(1, 1, 1))], [self::pallet($vast)]));
        self::assertSame(['/containers/0/pallet_overhang_limit/length', 'above_maximum'], [$error->field(), $error->reason()]);
        // A route beside a pallet is refused at the first routed item (follow-up).
        $plain = Item::create('plain', Dimensions::mm(1, 1, 1));
        $routed = Item::create('routed', Dimensions::mm(1, 1, 1), 0, 1, [Rotation::LWH], false, true, false, null, 0.0, null, [], [], 0, [], null, [], null, null, 2);
        $error = self::caught(static fn() => (new Packer())->pack([$plain, $routed], [new Container('box', Dimensions::mm(10, 10, 10)), self::pallet($limit)]));
        self::assertSame(['/items/1/stop_index', 'not_allowed', 'cannot be combined with pallet_overhang_limit'], [$error->field(), $error->reason(), $error->detail()]);
        self::assertCount(1, (new Packer())->pack([$routed], [self::pallet(null)])->containers);
        // The outer size is held to the declared deck, not to the wider frame.
        self::assertNotNull(PalletOverhang::solvingFrame(self::pallet($limit, Dimensions::mm(1210, 810, 1150)))->outerDimensions);
    }

    public static function testTheResultValidatorReadsTheDeck(): void
    {
        $carton = ['id' => 'carton', 'quantity' => 1, 'weight' => '10 kg', 'allowed_rotations' => ['LWH'],
            'dimensions' => ['length' => '400', 'width' => '400', 'height' => '300']];
        $request = self::request([$carton], [self::palletJson()], ['minimum_support_ratio' => 0.95]);
        $result = json_decode((string) json_encode(ArrayCodec::pack($request)), true);
        self::assertSame([], Validator::validate($request, $result));

        $mismatch = $result;
        $mismatch['containers'][0]['achieved_dimensions']['width']['ticks'] += 1;
        self::assertSame(['achieved_dimensions_mismatch'], self::codes(Validator::validate($request, $mismatch)));
        $missing = $result;
        unset($missing['containers'][0]['achieved_dimensions']);
        self::assertSame(['achieved_dimensions_mismatch'], self::codes(Validator::validate($request, $missing)));

        // Into the overhang zone's corner: 350 x 350 of 400 x 400 mm on the deck, below 0.95.
        $hanging = $result;
        $hanging['containers'][0]['placements'][0]['position']['x']['ticks'] = 0;
        $hanging['containers'][0]['placements'][0]['position']['y']['ticks'] = 0;
        self::assertContains('insufficient_support', self::codes(Validator::validate($request, $hanging)));
    }

    public static function testShippingCostBillsTheFootprintTheLoadReached(): void
    {
        $billing = ['objective' => 'shipping_cost', 'dimensional_weight_divisor' => 5000,
            'dimensional_weight_length_unit' => 'cm', 'dimensional_weight_weight_unit' => 'kg'];
        $request = self::request([self::crate()], [self::palletJson() + ['outer_dimensions' => ['length' => '1210', 'width' => '810', 'height' => '1150']]], $billing);
        $result = json_decode((string) json_encode(ArrayCodec::pack($request)), true);
        // 125 x 85 x 115 cm / 5000 = 244.375 kg, above the crate's 10 kg.
        self::assertSame(244375 * Weight::TICKS_PER_G, $result['score'][1]);
        self::assertSame([], Validator::validate($request, $result));
        // A bag is billed on its package, as before.
        $book = ['id' => 'book', 'quantity' => 1, 'weight' => '300 g', 'allowed_rotations' => ['LWH'],
            'dimensions' => ['length' => '200', 'width' => '100', 'height' => '20']];
        $bag = ['id' => 'mailer', 'inner_dimensions' => ['length' => '240', 'width' => '140', 'height' => '100'], 'deformable_boundary' => ['kind' => 'flat_bag']];
        $bagRequest = self::request([$book], [$bag], $billing);
        self::assertSame([], Validator::validate($bagRequest, json_decode((string) json_encode(ArrayCodec::pack($bagRequest)), true)));
    }

    public static function testTheDeckSupportCheckSkipsWhatItCannotJudge(): void
    {
        $carton = ['id' => 'carton', 'quantity' => 1, 'weight' => '10 kg', 'allowed_rotations' => ['LWH'],
            'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100']];
        $free = self::request([$carton], [self::palletJson()]);
        $result = json_decode((string) json_encode(ArrayCodec::pack($free)), true);
        // No ratio asked for: a placement half off the deck is not judged.
        $hanging = $result;
        $hanging['containers'][0]['placements'][0]['position']['x']['ticks'] = self::ticks(1200);
        $codes = self::codes(Validator::validate($free, $hanging));
        self::assertNotContains('insufficient_support', $codes);
        self::assertNotContains('centre_of_gravity_unsupported', $codes);
        // An unknown item type is reported once, never judged on the deck.
        $unknown = $result;
        $unknown['containers'][0]['placements'][0]['item_type'] = 'ghost';
        self::assertNotContains('insufficient_support', self::codes(Validator::validate($free, $unknown)));
        // 40 of 100 mm on the deck passes 0.4, but the centroid hangs past its edge.
        $strict = self::request([$carton], [self::palletJson()], ['minimum_support_ratio' => 0.4]);
        $past = json_decode((string) json_encode(ArrayCodec::pack($strict)), true);
        $past['containers'][0]['placements'][0]['position']['x']['ticks'] = self::ticks(1210);
        $codes = self::codes(Validator::validate($strict, $past));
        self::assertContains('centre_of_gravity_unsupported', $codes);
        self::assertNotContains('insufficient_support', $codes);
    }

    public static function testTheTrialKeepsTheDeckRungWhenTheFrameRunsOutOfTime(): void
    {
        $frame = PalletOverhang::solvingFrame(self::pallet(new OverhangLimit(Length::mm(50), Length::mm(50))));
        $items = Item::create('carton', Dimensions::mm(400, 400, 300), '10 kg', 3, [Rotation::LWH])->instances();
        $call = static fn(string $method, PackingConfig $config): PackingConfig => $config;
        $deadline = Deadline::ofMilliseconds(60000);
        [$timed, $solves] = PalletTrial::palletTrial($call, self::scripted(true), $frame, 1, $items, new PackingConfig(), new SearchStats(), $deadline);
        self::assertSame([1, false, true], [$solves, $timed->exhaustive, $timed->timeLimitReached]);
        self::assertSame([self::ticks(50), self::ticks(50)], [$timed->state->placements[0]->position->x, $timed->state->placements[0]->position->y]);
        // A stacked placement keeps the ratio it rests on; only the floor is measured again.
        self::assertSame(0.25, $timed->state->placements[1]->supportRatio);
        [$kept, $solves] = PalletTrial::palletTrial($call, self::scripted(false), $frame, 1, $items, new PackingConfig(), new SearchStats(), $deadline);
        self::assertSame([2, false], [$solves, $kept->exhaustive]);
        self::assertCount(2, $kept->state->placements);
    }

    /**
     * A solver that places one carton on the floor and one on top of it on the deck rung, leaving
     * the third, and
     * on the frame either runs out of time or places nothing.
     */
    private static function scripted(bool $frameTimesOut): SingleContainerSolver
    {
        return new class($frameTimesOut) implements SingleContainerSolver {
            public function __construct(private bool $frameTimesOut) {}
            public function name(): string { return 'scripted'; }
            public function packOne(Container $container, int $sequence, array $items, PackingConfig $config, SearchStats $stats, Deadline $deadline): SingleContainerSolution
            {
                $state = new ContainerState($container, $sequence);
                if ($container->deck !== null) {
                    if ($this->frameTimesOut) {
                        throw new TimeLimitReached('scripted');
                    }
                    return new SingleContainerSolution($state, $items);
                }
                $dimensions = $items[0]->dimensions();
                $floor = new Point(0, 0, 0);
                $top = new Point(0, 0, $dimensions->height->ticks);
                $state->add(new Placement($items[0], $floor, Rotation::LWH, $dimensions, $floor, $dimensions));
                $state->add(new Placement($items[1], $top, Rotation::LWH, $dimensions, $top, $dimensions, 0.25));
                return new SingleContainerSolution($state, [$items[2]], true);
            }
        };
    }

    /** @return array{0:bool,1:?string} */
    private static function verdict(Container $container, float $minimum, int $xMm): array
    {
        $item = Item::create('box', Dimensions::mm(100, 100, 100), '1 kg', 1, [Rotation::LWH]);
        $context = new ConstraintContext($container, [], new ItemInstance($item, 1), new Point(self::ticks($xMm), self::ticks(100), 0),
            Rotation::LWH, $item->dimensions, $item->dimensions);
        $result = (new SupportConstraint($minimum))->evaluate($context);
        return [$result->allowed, $result->allowed ? null : $result->code];
    }

    /** @return list<int> */
    private static function billedMm(Item $item, Container $pallet): array
    {
        $packed = (new Packer())->pack([$item], [$pallet])->containers[0];
        return array_map(static fn(int $ticks): int => intdiv($ticks, Length::TICKS_PER_MM), self::sides($packed->billedDimensions()));
    }

    private static function pallet(?OverhangLimit $limit, ?Dimensions $outer = null): Container
    {
        return new Container('pallet', Dimensions::mm(1200, 800, 1000), $outer, new Weight(0), Weight::parse('1000 kg'), 0, null, [], [], null, [], 0.0, [], null, null, null, [], [], null, null, $limit);
    }

    private static function box(int $xMm, int $yMm, int $lengthMm): AxisAlignedBox
    {
        return new AxisAlignedBox(new Point(self::ticks($xMm), self::ticks($yMm), 0), Dimensions::mm($lengthMm, 100, 100));
    }

    private static function ticks(int $millimetres): int
    {
        return Length::mm($millimetres)->ticks;
    }

    /** @return list<int> */
    private static function sides(Dimensions $dimensions): array
    {
        return [$dimensions->length->ticks, $dimensions->width->ticks, $dimensions->height->ticks];
    }

    /** @param array<string,mixed> $dimensions @return list<int> */
    private static function millimetres(array $dimensions): array
    {
        return array_map(static fn(string $side): int => (int) $dimensions[$side]['value'], ['length', 'width', 'height']);
    }

    /** @param list<\Packvium\Validation\ResultIssue> $issues @return list<string> */
    private static function codes(array $issues): array
    {
        return array_values(array_unique(array_map(static fn($issue): string => $issue->code, $issues)));
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

    /** @param array<string,mixed> $container */
    private static function assertRefused(array $container, string $field, string $reason, string $detail): void
    {
        $error = self::caught(static fn() => ArrayCodec::pack(self::request([self::crate()], [$container])));
        self::assertSame([$field, $reason, $detail], [$error->field(), $error->reason(), $error->detail()]);
    }

    /** @return array<string,mixed> */
    private static function crate(): array
    {
        return ['id' => 'crate', 'quantity' => 1, 'weight' => '10 kg', 'allowed_rotations' => ['LWH'],
            'dimensions' => ['length' => '1250', 'width' => '850', 'height' => '400']];
    }

    /** @param mixed $limit @return array<string,mixed> */
    private static function palletJson($limit = self::LIMIT): array
    {
        return ['id' => 'pallet', 'inner_dimensions' => ['length' => '1200', 'width' => '800', 'height' => '1000'],
            'max_payload' => '1000 kg', 'pallet_overhang_limit' => $limit];
    }

    /**
     * @param list<array<string,mixed>> $items @param list<array<string,mixed>> $containers @param array<string,mixed> $configuration
     * @return array<string,mixed>
     */
    private static function request(array $items, array $containers, array $configuration = []): array
    {
        return ['items' => $items, 'containers' => $containers,
            'configuration' => $configuration + ['solver_profile' => 'balanced', 'time_limit_ms' => 60000, 'effort_budget' => ['max_search_nodes' => 100000]]];
    }
}
