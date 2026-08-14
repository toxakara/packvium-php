<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Algorithm\RawSolution;
use Packvium\Algorithm\SearchStats;
use Packvium\Config\PackingConfig;
use Packvium\Config\SolverProfile;
use Packvium\Domain\Container;
use Packvium\Domain\Dimensions;
use Packvium\Domain\Item;
use Packvium\Domain\ItemInstance;
use Packvium\Domain\PackedContainer;
use Packvium\Domain\Placement;
use Packvium\Domain\Point;
use Packvium\Domain\RateTable;
use Packvium\Domain\Rotation;
use Packvium\Domain\UnpackedItem;
use Packvium\Domain\UnratedWeightException;
use Packvium\Objective\DefaultSolutionScorer;
use Packvium\Objective\LandedCostSolutionScorer;
use Packvium\Objective\LowestCostSolutionScorer;
use Packvium\Objective\ObjectiveScore;
use Packvium\Objective\OpenDimensionSolutionScorer;
use Packvium\Objective\ShippingCostSolutionScorer;
use Packvium\Objective\SolutionScorer;
use Packvium\Objective\UnknownObjectiveException;
use Packvium\Packer;
use Packvium\Unit\Weight;

/**
 * The objective vector — five lexicographic integer keys, lower is better.
 *
 * Its arithmetic is pinned here rather than left to whatever the solvers happen to
 * produce. Every implementation of this library must compute the identical formula, and
 * the expected values below are the ones the Python suite asserts. A container volume
 * in cubic ticks is far past the range where a double is exact, so a floating-point
 * score would silently disagree across languages. See docs/OBJECTIVE.md.
 */
final class ObjectiveTest extends TestCase
{
    private const MM = 16_000;

    /** @param list<ItemInstance> $instances @param list<array{0:int,1:int,2:int}> $positions */
    private static function filled(Container $box, array $instances, array $positions, int $sequence = 1): PackedContainer
    {
        $placements = [];
        foreach ($instances as $index => $instance) {
            [$x, $y, $z] = $positions[$index];
            $dims = $instance->item->dimensions;
            $placements[] = new Placement($instance, new Point($x, $y, $z), Rotation::LWH, $dims, new Point($x, $y, $z), $dims);
        }
        return new PackedContainer($box, $sequence, $placements);
    }

    /** @param list<PackedContainer> $containers @param list<UnpackedItem> $unpacked @return list<int> */
    private static function score(array $containers, array $unpacked = []): array
    {
        return (new DefaultSolutionScorer())
            ->score(new RawSolution('test', $containers, $unpacked, new SearchStats()))->components;
    }

    /** @param list<PackedContainer> $containers @param list<UnpackedItem> $unpacked @return list<int> */
    private static function costScore(array $containers, array $unpacked = []): array
    {
        return (new LowestCostSolutionScorer())
            ->score(new RawSolution('test', $containers, $unpacked, new SearchStats()))->components;
    }

    // --------------------------------------------------------------------- shape

    public static function testTheVectorHasFiveExactIntegerKeys(): void
    {
        $result = Support::pack([Support::item('a', 100, 100, 100)], [Support::box('c', 200, 200, 200)]);
        self::assertCount(5, $result->score);
        foreach ($result->score as $key) {
            self::assertTrue(is_int($key), 'every objective key must be an exact integer');
        }
    }

    public static function testAnEmptySolutionScoresOnlyItsUnpackedItems(): void
    {
        [$lost] = Item::create('a', Dimensions::mm(1, 1, 1))->instances();
        self::assertSame([1, 0, 0, 0, 0], self::score([], [new UnpackedItem($lost, 'no_feasible_placement')]));
    }

    // ---------------------------------------------------------------- arithmetic

    public static function testAPerfectlyFilledContainerWastesNoVolume(): void
    {
        // Eight 100 mm cubes exactly fill a 200 mm cube, so the unused-volume key is
        // zero and the stack reaches the full height.
        $cubes = Item::create('cube', Dimensions::mm(100, 100, 100), quantity: 8);
        $box = Container::create('box', Dimensions::mm(200, 200, 200));
        $lattice = [];
        foreach ([0, 1] as $z) {
            foreach ([0, 1] as $y) {
                foreach ([0, 1] as $x) {
                    $lattice[] = [$x * 100 * self::MM, $y * 100 * self::MM, $z * 100 * self::MM];
                }
            }
        }
        self::assertSame([0, 1, 0, 0, 1_000_000], self::score([self::filled($box, $cubes->instances(), $lattice)]));
    }

    public static function testTheRatiosAreHandCheckable(): void
    {
        // One eighth of the volume used, half the height reached.
        $cube = Item::create('cube', Dimensions::mm(100, 100, 100));
        $box = Container::create('box', Dimensions::mm(200, 200, 200));
        self::assertSame([0, 1, 0, 875_000, 500_000], self::score([self::filled($box, $cube->instances(), [[0, 0, 0]])]));
    }

    public static function testContainerCostIsSummedInMinorUnits(): void
    {
        $cubes = Item::create('cube', Dimensions::mm(100, 100, 100), quantity: 2);
        $box = Container::create('box', Dimensions::mm(200, 200, 200), costMinor: 395);
        [$first, $second] = $cubes->instances();
        $score = self::score([
            self::filled($box, [$first], [[0, 0, 0]], 1),
            self::filled($box, [$second], [[0, 0, 0]], 2),
        ]);
        self::assertSame([2, 790], [$score[1], $score[2]]);
    }

    public static function testTheVolumeRatioIsFlooredPerContainer(): void
    {
        // Per-container rather than pooled, so a large carton is not preferred merely
        // for being large; floored so the key stays an exact integer.
        $cube = Item::create('cube', Dimensions::mm(30, 30, 30));
        $box = Container::create('box', Dimensions::mm(100, 100, 100));
        $score = self::score([self::filled($box, $cube->instances(), [[0, 0, 0]])]);

        $volume = $box->innerDimensions->volumeString();
        $free = \Packvium\Support\BigInt::subtract($volume, $cube->dimensions->volumeString());
        $expected = (int)\Packvium\Support\BigInt::divide(
            \Packvium\Support\BigInt::multiply($free, '1000000'), $volume);
        self::assertSame($expected, $score[3]);
    }

    // ------------------------------------------------------------------ ordering

    public static function testPlacingEverythingDominatesEveryOtherKey(): void
    {
        // Key 0 first: an answer that leaves an item behind loses to one that does not,
        // however elegantly it fills its cartons.
        [$lost] = Item::create('a', Dimensions::mm(1, 1, 1))->instances();
        self::assertTrue(self::score([]) < self::score([], [new UnpackedItem($lost, 'x')]));
    }

    public static function testFewerContainersBeatMore(): void
    {
        $cubes = Item::create('cube', Dimensions::mm(100, 100, 100), quantity: 2);
        $box = Container::create('box', Dimensions::mm(200, 200, 200));
        [$first, $second] = $cubes->instances();

        $together = self::score([self::filled($box, [$first, $second], [[0, 0, 0], [100 * self::MM, 0, 0]])]);
        $apart = self::score([
            self::filled($box, [$first], [[0, 0, 0]], 1),
            self::filled($box, [$second], [[0, 0, 0]], 2),
        ]);
        self::assertTrue($together < $apart);
    }

    public static function testALowerCentreOfLoadRanksBetter(): void
    {
        $cubes = Item::create('cube', Dimensions::mm(100, 100, 100), quantity: 2);
        $box = Container::create('box', Dimensions::mm(200, 200, 200));

        $sideBySide = self::score([self::filled($box, $cubes->instances(), [[0, 0, 0], [100 * self::MM, 0, 0]])]);
        $stacked = self::score([self::filled($box, $cubes->instances(), [[0, 0, 0], [0, 0, 100 * self::MM]])]);
        self::assertTrue($sideBySide < $stacked);
    }

    // ------------------------------------------------------------- reported result

    public static function testTheReportedScoreMatchesARecomputationFromThePlacements(): void
    {
        // The score a caller receives must describe the packing they received. This is
        // the same check the cross-language conformance runner makes of every
        // implementation.
        $items = [
            Support::item('a', 40, 30, 20, ['quantity' => 6, 'weight' => '500 g']),
            Support::item('b', 60, 60, 60, ['quantity' => 2]),
        ];
        $containers = [
            Support::box('small', 100, 100, 100, ['costMinor' => 200]),
            Support::box('large', 200, 200, 200, ['costMinor' => 350]),
        ];
        $result = Support::pack($items, $containers, PackingConfig::quality(2_000));

        self::assertSame([], Support::problems($result, $items, $containers));
        self::assertSame($result->score, self::score($result->containers, $result->unpacked));
    }

    public static function testAlternativesAreNeverBetterThanTheChosenAnswer(): void
    {
        $items = [Support::item('a', 40, 30, 20, ['quantity' => 8])];
        $containers = [Support::box('c', 100, 100, 100, ['quantity' => 4])];
        $result = Support::pack($items, $containers, PackingConfig::quality(2_000, 5));

        foreach ($result->alternatives as $alternative) {
            self::assertTrue($result->score <= $alternative->score);
        }
    }

    public static function testEveryProfileReportsAScoreItCanDefend(): void
    {
        $items = [Support::item('cube', 100, 100, 100, ['quantity' => 8])];
        $containers = [Support::box('box', 200, 200, 200)];
        foreach ([PackingConfig::fast(), PackingConfig::balanced(), PackingConfig::quality(2_000)] as $config) {
            $result = Support::pack($items, $containers, $config);
            self::assertSame($result->score, self::score($result->containers, $result->unpacked),
                $config->profile->value);
        }
    }

    // ------------------------------------------------------------- selectable objective

    public static function testLowestCostPrefersFewerDollarsOverFewerContainers(): void
    {
        // The default objective ranks by container count ahead of cost -- fewer boxes
        // wins even if it costs more. lowest_cost inverts that: cost decides before count.
        $bigExpensive = Container::create('big', Dimensions::mm(200, 200, 200), costMinor: 1000);
        $smallCheap = Container::create('small', Dimensions::mm(100, 100, 100), costMinor: 100);
        [$first, $second] = Item::create('cube', Dimensions::mm(100, 100, 100), quantity: 2)->instances();

        $oneContainer = [self::filled($bigExpensive, [$first, $second], [[0, 0, 0], [100 * self::MM, 0, 0]])];
        $twoContainers = [
            self::filled($smallCheap, [$first], [[0, 0, 0]]),
            new PackedContainer($smallCheap, 2, self::filled($smallCheap, [$second], [[0, 0, 0]])->placements),
        ];

        $candidates = [$oneContainer, $twoContainers];
        usort($candidates, static fn($a, $b) => self::score($a) <=> self::score($b));
        self::assertSame($oneContainer, $candidates[0]); // fewer containers wins by default (cost 1000 > 200)

        usort($candidates, static fn($a, $b) => self::costScore($a) <=> self::costScore($b));
        self::assertSame($twoContainers, $candidates[0]); // lowest_cost prefers 200 over 1000, even as 2 boxes
    }

    public static function testTheDefaultObjectiveNameReproducesTodaysRankingExactly(): void
    {
        $items = [Support::item('cube', 100, 100, 100, ['quantity' => 8])];
        $containers = [Support::box('box', 200, 200, 200)];
        $explicitDefault = Support::pack($items, $containers, new PackingConfig(objective: 'default'));
        $unspecified = Support::pack($items, $containers, PackingConfig::balanced());
        self::assertSame($unspecified->score, $explicitDefault->score);
        self::assertSame('default', $explicitDefault->objective);
        self::assertSame('default', $unspecified->objective);
    }

    public static function testTheResultReportsWhichObjectiveWasUsed(): void
    {
        $items = [Support::item('a', 40, 40, 40)];
        $containers = [Support::box('c', 100, 100, 100)];
        $result = Support::pack($items, $containers, new PackingConfig(objective: 'lowest_cost'));
        self::assertSame('lowest_cost', $result->objective);
    }

    public static function testAnUnknownObjectiveNameIsRejected(): void
    {
        $items = [Support::item('a', 40, 40, 40)];
        $containers = [Support::box('c', 100, 100, 100)];
        self::assertThrows(UnknownObjectiveException::class, static fn() =>
            (new Packer(new PackingConfig(objective: 'cheapest_shipping_ever')))->pack($items, $containers));
    }

    public static function testAnExplicitSolutionScorerOverridesTheNamedObjective(): void
    {
        $scorer = new class implements SolutionScorer {
            public function score(RawSolution $solution): ObjectiveScore
            {
                return new ObjectiveScore([0, 0, 0, 0, 99]);
            }
        };
        $items = [Support::item('a', 40, 40, 40)];
        $containers = [Support::box('c', 100, 100, 100)];
        $result = (new Packer(new PackingConfig(objective: 'lowest_cost'), solutionScorer: $scorer))
            ->pack($items, $containers);
        self::assertSame([0, 0, 0, 0, 99], $result->score);
    }

    // ------------------------------------------------------------- shipping cost

    public static function testShippingCostIsHandCheckableFromACarrierDivisor(): void
    {
        // A 200mm cube container (20cm/side) with divisor 5000 (cm/kg): dimensional
        // weight is 20*20*20/5000 = 1.6kg. A 1g item's actual gross weight is far
        // below that, so the billable weight is the dimensional weight, in weight
        // ticks (1 tick = 1/8 microgram, so 1.6kg = 1,600g = 1,600 * 8,000,000 ticks).
        $cube = Item::create('cube', Dimensions::mm(100, 100, 100), weight: '1g');
        $box = Container::create('box', Dimensions::mm(200, 200, 200));
        $scorer = new ShippingCostSolutionScorer(5000, 'cm', 'kg');
        $score = $scorer->score(new RawSolution('test', [self::filled($box, $cube->instances(), [[0, 0, 0]])], [], new SearchStats()))->components;
        self::assertSame([0, 1_600 * 8_000_000, 1, 875_000, 500_000], $score);
    }

    public static function testShippingCostBillsTheGreaterOfActualAndDimensionalWeight(): void
    {
        $cube = Item::create('cube', Dimensions::mm(100, 100, 100), weight: '5kg');
        $box = Container::create('box', Dimensions::mm(200, 200, 200));
        $scorer = new ShippingCostSolutionScorer(5000, 'cm', 'kg');
        $score = $scorer->score(new RawSolution('test', [self::filled($box, $cube->instances(), [[0, 0, 0]])], [], new SearchStats()))->components;
        self::assertSame(Weight::parse('5kg')->ticks, $score[1]); // actual weight beats the 1.6kg dimensional figure
    }

    public static function testShippingCostUsesOuterDimensionsWhenDeclaredNotUsableCapacity(): void
    {
        // A carrier measures the box that moves, not its usable interior -- a declared
        // outer size must win over the (smaller) inner capacity used everywhere else.
        $cube = Item::create('cube', Dimensions::mm(10, 10, 10), weight: '1g');
        $box = Container::create('box', Dimensions::mm(20, 20, 20), outerDimensions: Dimensions::mm(30, 30, 30));
        $scorer = new ShippingCostSolutionScorer(5000, 'cm', 'kg');
        $score = $scorer->score(new RawSolution('test', [self::filled($box, $cube->instances(), [[0, 0, 0]])], [], new SearchStats()))->components;
        // 30mm = 3cm/side -> 27cm^3 / 5000 = 5.4g, not the 20mm inner's 1.6g.
        self::assertSame(43_200_000, $score[1]);
    }

    public static function testShippingCostPrefersTheSmallerBulkyContainer(): void
    {
        // Same light item, two container sizes: the smaller one has less dimensional
        // weight, so shipping_cost prefers it even though default is indifferent.
        $lightItem = Item::create('cube', Dimensions::mm(10, 10, 10), weight: '1g');
        $snug = Container::create('snug', Dimensions::mm(20, 20, 20));
        $oversized = Container::create('oversized', Dimensions::mm(30, 30, 30));
        $scorer = new ShippingCostSolutionScorer(5000, 'cm', 'kg');

        $inSnug = [self::filled($snug, $lightItem->instances(), [[0, 0, 0]])];
        $inOversized = [self::filled($oversized, $lightItem->instances(), [[0, 0, 0]])];
        $candidates = [$inSnug, $inOversized];
        usort($candidates, static fn($a, $b) =>
            $scorer->score(new RawSolution('test', $a, [], new SearchStats()))->compare(
                $scorer->score(new RawSolution('test', $b, [], new SearchStats()))
            ));
        self::assertSame($inSnug, $candidates[0]);
    }

    public static function testShippingCostRequiresADivisorAndReportsTheObjectiveUsed(): void
    {
        $items = [Support::item('a', 40, 40, 40)];
        $containers = [Support::box('c', 100, 100, 100)];
        self::assertThrows(UnknownObjectiveException::class, static fn() =>
            (new Packer(new PackingConfig(objective: 'shipping_cost')))->pack($items, $containers));

        $result = Support::pack($items, $containers, new PackingConfig(objective: 'shipping_cost', dimensionalWeightDivisor: 5000));
        self::assertSame('shipping_cost', $result->objective);
        self::assertCount(5, $result->score);
    }

    // -------------------------------------------------------- landed cost

    /**
     * Every assertion here is about money, and every number is hand-checkable from the
     * table above it. The class had no PHP test at all until now: it was exercised only
     * through the cross-language fixture, which proves four engines agree but says
     * nothing about which of this class's own branches ever ran.
     */
    private static function landedScore(Container $box, Item $item, int $divisor = 5_000): array
    {
        return (new LandedCostSolutionScorer($divisor, 'cm', 'kg'))
            ->score(new RawSolution('test', [self::filled($box, $item->instances(), [[0, 0, 0]])], [], new SearchStats()))
            ->components;
    }

    public static function testLandedCostIsHandCheckableFromABracketTable(): void
    {
        // 200mm cube = 20cm/side -> 8,000cm^3 / 5,000 = 1.6kg = 1,600g billed, which
        // beats the 1g item's actual weight. 1,600 is above the 1,000g bracket and
        // inside the 2,000g one, so the price is 900 minor units.
        $cube = Item::create('cube', Dimensions::mm(100, 100, 100), weight: '1g');
        $box = Container::create('box', Dimensions::mm(200, 200, 200),
            rateTable: new RateTable([1_000, 2_000], [500, 900]));
        self::assertSame([0, 900, 1, 875_000, 500_000], self::landedScore($box, $cube));
    }

    public static function testLandedCostAndShippingCostDisagreeWhenAPriceBandDips(): void
    {
        // The one case that justifies a second objective. The snug box bills 2g and the
        // oversized one 6g, so `shipping_cost` -- which ranks in grams -- always prefers
        // snug. This tariff's second band is cheaper than its first, so the money runs
        // the other way. A rate card that dips is a real promotional band, which is why
        // RateTable accepts one rather than rejecting it as malformed.
        $tariff = new RateTable([4, 10], [500, 300]);
        $light = Item::create('cube', Dimensions::mm(10, 10, 10), weight: '1g');
        $snug = Container::create('snug', Dimensions::mm(20, 20, 20), rateTable: $tariff);
        $oversized = Container::create('oversized', Dimensions::mm(30, 30, 30), rateTable: $tariff);

        self::assertSame(500, self::landedScore($snug, $light)[1]);
        self::assertSame(300, self::landedScore($oversized, $light)[1]);

        $inSnug = [self::filled($snug, $light->instances(), [[0, 0, 0]])];
        $inOversized = [self::filled($oversized, $light->instances(), [[0, 0, 0]])];
        $byWeight = new ShippingCostSolutionScorer(5_000, 'cm', 'kg');
        $byMoney = new LandedCostSolutionScorer(5_000, 'cm', 'kg');
        $rank = static function (SolutionScorer $scorer, array $candidates): array {
            usort($candidates, static fn($a, $b) =>
                $scorer->score(new RawSolution('test', $a, [], new SearchStats()))->compare(
                    $scorer->score(new RawSolution('test', $b, [], new SearchStats()))
                ));
            return $candidates[0];
        };
        self::assertSame($inSnug, $rank($byWeight, [$inSnug, $inOversized]));
        self::assertSame($inOversized, $rank($byMoney, [$inSnug, $inOversized]));
    }

    public static function testLandedCostBillsWholeGramsRoundedUp(): void
    {
        // 20mm cube -> 8cm^3 / 5,000 = 1.6g. A carrier reads a scale upward, so this is
        // 2g and lands in the second band. Rounding down would price it at 100 -- below
        // what the carrier charges, which is the one direction that must never happen.
        $light = Item::create('cube', Dimensions::mm(10, 10, 10), weight: '1g');
        $box = Container::create('box', Dimensions::mm(20, 20, 20),
            rateTable: new RateTable([1, 5], [100, 700]));
        self::assertSame(700, self::landedScore($box, $light)[1]);
    }

    public static function testLandedCostPricesTheOuterBoxACarrierActuallyMoves(): void
    {
        // 30mm outer -> 5.4g -> 6g, in the second band; the 20mm interior would have
        // billed 2g and priced in the first. The outer size must win, as it does for
        // `shipping_cost`.
        $light = Item::create('cube', Dimensions::mm(10, 10, 10), weight: '1g');
        $box = Container::create('box', Dimensions::mm(20, 20, 20),
            outerDimensions: Dimensions::mm(30, 30, 30),
            rateTable: new RateTable([4, 10], [500, 300]));
        self::assertSame(300, self::landedScore($box, $light)[1]);
    }

    public static function testAContainerWithoutARateTableIsRefusedRatherThanRankedFree(): void
    {
        // Rating some containers and not others would compare a priced packing against
        // an unpriced one as though the unpriced were free -- and the objective would
        // then prefer exactly the answer nobody quoted.
        $cube = Item::create('cube', Dimensions::mm(100, 100, 100), weight: '1g');
        $unrated = Container::create('unrated', Dimensions::mm(200, 200, 200));
        self::assertThrows(UnknownObjectiveException::class, static fn() => self::landedScore($unrated, $cube));
        $message = '';
        try {
            self::landedScore($unrated, $cube);
        } catch (UnknownObjectiveException $error) {
            $message = $error->getMessage();
        }
        // Naming the container is the whole value of the refusal: a caller with thirty
        // containers needs to know which rate card is missing, not that one is.
        self::assertTrue(str_contains($message, "'unrated'"), $message);
    }

    public static function testAWeightAboveTheLastBracketHasNoPriceAndSaysSo(): void
    {
        // Clamping to the top price would under-quote every oversize shipment silently.
        $light = Item::create('cube', Dimensions::mm(10, 10, 10), weight: '1g');
        $box = Container::create('box', Dimensions::mm(20, 20, 20),
            rateTable: new RateTable([1], [100])); // 2g billed, one 1g bracket
        self::assertThrows(UnratedWeightException::class, static fn() => self::landedScore($box, $light));
    }

    // ------------------------------------------------------ open-dimension objective

    /** @param list<PackedContainer> $containers @param list<UnpackedItem> $unpacked @return list<int> */
    private static function openDimensionScore(array $containers, array $unpacked = []): array
    {
        return (new OpenDimensionSolutionScorer())
            ->score(new RawSolution('test', $containers, $unpacked, new SearchStats()))->components;
    }

    public static function testOpenDimensionRanksByRawAchievedHeightAheadOfContainerCount(): void
    {
        // The default objective checks containerCount before height, so one tall
        // container of 300mm beats two shorter containers summing to 200mm even though
        // the second answer is objectively less tall overall -- exactly backwards from
        // what an "open dimension" caller ("how tall does this end up")
        // wants. openDimensionHeight reorders the vector so the raw summed achieved
        // height decides before container count.
        $tallBox = Container::create('tall', Dimensions::mm(100, 100, 1000));
        $shortBox = Container::create('short', Dimensions::mm(100, 100, 1000));
        $stacked = Item::create('stacked', Dimensions::mm(100, 100, 300));
        [$a, $b] = Item::create('half', Dimensions::mm(100, 100, 100), quantity: 2)->instances();

        $oneTallContainer = [self::filled($tallBox, $stacked->instances(), [[0, 0, 0]])];
        $twoShortContainers = [
            self::filled($shortBox, [$a], [[0, 0, 0]]),
            new PackedContainer($shortBox, 2, self::filled($shortBox, [$b], [[0, 0, 0]])->placements),
        ];

        $candidates = [$oneTallContainer, $twoShortContainers];
        usort($candidates, static fn($a, $b) => self::score($a) <=> self::score($b));
        self::assertSame($oneTallContainer, $candidates[0]); // fewer containers wins by default

        usort($candidates, static fn($a, $b) => self::openDimensionScore($a) <=> self::openDimensionScore($b));
        self::assertSame($twoShortContainers, $candidates[0]); // lower summed height wins here
    }

    public static function testOpenDimensionHeightIsReportedAndSelectableEndToEnd(): void
    {
        $items = [Support::item('cube', 100, 100, 100, ['quantity' => 4])];
        $containers = [Support::box('pallet', 200, 200, 1_000)]; // generous, "open" height
        $result = Support::pack($items, $containers, new PackingConfig(objective: 'open_dimension_height'));
        self::assertSame('open_dimension_height', $result->objective);
        self::assertCount(5, $result->score);
        self::assertSame([], Support::problems($result, $items, $containers));
    }

    public static function testOpenDimensionHeightMatchesTheExactSolverOnASmallInstance(): void
    {
        // Cross-check against exactSmall: for a small enough instance the heuristic
        // profile must reach the same, provably minimal achieved height the exhaustive
        // solver finds -- not merely a valid one.
        $items = [Support::item('cube', 100, 100, 100, ['quantity' => 4])];
        $containers = [Support::box('pallet', 200, 200, 1_000)];

        $heuristic = Support::pack($items, $containers, new PackingConfig(profile: SolverProfile::Balanced, objective: 'open_dimension_height'));
        $exact = Support::pack($items, $containers, new PackingConfig(profile: SolverProfile::ExactSmall, objective: 'open_dimension_height'));

        self::assertSame([], Support::problems($heuristic, $items, $containers));
        self::assertSame([], Support::problems($exact, $items, $containers));
        self::assertSame([], $heuristic->unpacked);
        self::assertSame([], $exact->unpacked);
        $heuristicHeight = self::openDimensionScore($heuristic->containers)[1];
        $exactHeight = self::openDimensionScore($exact->containers)[1];
        // Four 100mm cubes fit in one 200x200 layer of two by two: the exact minimum
        // achievable height is exactly one cube's height, 100mm (1_600_000 ticks).
        self::assertSame(1_600_000, $exactHeight);
        self::assertSame($exactHeight, $heuristicHeight);
    }

    // ------------------------------------------------------ maximum-value objective

    /** @param list<PackedContainer> $containers @param list<UnpackedItem> $unpacked @return list<int> */
    private static function maximumValueScore(array $containers, array $unpacked = []): array
    {
        return (new \Packvium\Objective\MaximumValueSolutionScorer())
            ->score(new RawSolution('test', $containers, $unpacked, new SearchStats()))->components;
    }

    public static function testMaximumValueRanksForgoneValueAheadOfContainerCount(): void
    {
        $cheapLost = Item::create('foam', Dimensions::mm(1, 1, 1), value: 1)->instances();
        $priceyLost = Item::create('goods', Dimensions::mm(1, 1, 1), value: 1000)->instances();
        $cheap = self::maximumValueScore([], array_map(static fn($i) => new UnpackedItem($i, 'no_feasible_placement'), $cheapLost));
        $pricey = self::maximumValueScore([], array_map(static fn($i) => new UnpackedItem($i, 'no_feasible_placement'), $priceyLost));
        self::assertSame([1, 1, 0, 0, 0], $cheap);
        self::assertSame([1, 1000, 0, 0, 0], $pricey);
    }

    public static function testMaximumValueNeverPrefersAnIncompleteAnswerOverACompleteOne(): void
    {
        $complete = self::maximumValueScore([]);
        $lostWorthless = Item::create('a', Dimensions::mm(1, 1, 1))->instances(); // value unset -> 0
        $incompleteButWorthless = self::maximumValueScore(
            [], array_map(static fn($i) => new UnpackedItem($i, 'no_feasible_placement'), $lostWorthless)
        );
        self::assertSame(-1, (new ObjectiveScore($complete))->compare(new ObjectiveScore($incompleteButWorthless)));
    }

    public static function testMaximumValueSelectsTheHigherValueItemToKeepRegardlessOfInputOrder(): void
    {
        $onlyRoomForOne = Support::box('c', 50, 50, 50, ['quantity' => 1]);
        $config = new PackingConfig(objective: 'maximum_value');

        foreach ([
            [Support::item('cheap', 50, 50, 50, ['value' => 1]), Support::item('pricey', 50, 50, 50, ['value' => 100])],
            [Support::item('pricey', 50, 50, 50, ['value' => 100]), Support::item('cheap', 50, 50, 50, ['value' => 1])],
        ] as $items) {
            $result = Support::pack($items, [$onlyRoomForOne], $config);
            $kept = array_unique(array_map(
                static fn($p) => $p->instance->item->id,
                array_merge(...array_map(static fn($c) => $c->placements, $result->containers)),
            ));
            self::assertSame(['pricey'], $kept);
        }
    }

    public static function testMaximumValueSelectsTheHigherValueItemUnderASingleStartProfile(): void
    {
        // The test above passes under the default profile for the wrong
        // reason: the ordering never read the objective, and the multi-start portfolio
        // happened to contain a start that packed the valuable item. Fast runs exactly
        // one ordering, which is where the defect was visible -- identical dimensions
        // made every key tie and fall through to the item id.
        $onlyRoomForOne = Support::box('c', 50, 50, 50, ['quantity' => 1]);
        $config = new PackingConfig(profile: SolverProfile::Fast, objective: 'maximum_value');

        foreach ([
            [Support::item('cheap', 50, 50, 50, ['value' => 1]), Support::item('precious', 50, 50, 50, ['value' => 500])],
            [Support::item('precious', 50, 50, 50, ['value' => 500]), Support::item('cheap', 50, 50, 50, ['value' => 1])],
        ] as $items) {
            $result = Support::pack($items, [$onlyRoomForOne], $config);
            $kept = array_unique(array_map(
                static fn($p) => $p->instance->item->id,
                array_merge(...array_map(static fn($c) => $c->placements, $result->containers)),
            ));
            self::assertSame(['precious'], $kept);
            self::assertSame(1, $result->score[1]);
        }
    }

    public static function testMaximumValueLeavesAnExplicitPriorityBiasInCharge(): void
    {
        // Value sits behind priority, not ahead of it: a caller who explicitly ranks the
        // cheap item first still gets it packed.
        $result = Support::pack(
            [Support::item('cheap', 50, 50, 50, ['value' => 1, 'priority' => 5]),
             Support::item('precious', 50, 50, 50, ['value' => 500])],
            [Support::box('c', 50, 50, 50, ['quantity' => 1])],
            new PackingConfig(profile: SolverProfile::Fast, objective: 'maximum_value'),
        );
        $kept = array_unique(array_map(
            static fn($p) => $p->instance->item->id,
            array_merge(...array_map(static fn($c) => $c->placements, $result->containers)),
        ));
        self::assertSame(['cheap'], $kept);
    }

    public static function testARequestThatOmitsValueReproducesTheDefaultResultByteForByte(): void
    {
        $containers = [Support::box('c', 120, 40, 40)];
        $withoutValue = Support::pack([Support::item('a', 40, 40, 40, ['quantity' => 3])], $containers, new PackingConfig());
        $withValue = Support::pack([Support::item('a', 40, 40, 40, ['quantity' => 3, 'value' => 7])], $containers, new PackingConfig());
        self::assertSame($withoutValue->score, $withValue->score);
        self::assertCount(count($withoutValue->containers), $withValue->containers);
        foreach ($withoutValue->containers as $index => $left) {
            $right = $withValue->containers[$index];
            $leftPositions = array_map(static fn($p) => [$p->position, $p->rotation], $left->placements);
            $rightPositions = array_map(static fn($p) => [$p->position, $p->rotation], $right->placements);
            self::assertEquals($leftPositions, $rightPositions);
        }
    }

    public static function testANegativeValueFailsAdmissionInsteadOfBeingIgnored(): void
    {
        self::assertThrows(\InvalidArgumentException::class, static fn() =>
            Item::create('a', Dimensions::mm(10, 10, 10), value: -1));
    }
}
