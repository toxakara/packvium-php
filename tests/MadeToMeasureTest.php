<?php
declare(strict_types=1);

namespace Packvium\Tests;

use InvalidArgumentException;
use Packvium\Algorithm\{ContainerState, Deadline, SearchStats, SingleContainerSolution, SingleContainerSolver, SolverOrchestrator};
use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container, Dimensions, Item, MadeToMeasure, PackedContainer, PackingRequest, Placement, Point, Rotation};
use Packvium\Serialization\{ArrayCodec, InvalidRequestException, UnsupportedFeatureException};
use Packvium\Unit\Length;
use Packvium\Validation\IndependentSolutionValidator;
use ReflectionMethod;

/**
 * Made-to-measure containers, pinned to the worked numbers in
 * docs/MADE-TO-MEASURE-CONTAINERS.md. Mirrors `packvium-python/tests/test_made_to_measure.py`.
 */
final class MadeToMeasureTest extends TestCase
{
    private const MM = Length::TICKS_PER_MM;

    public static function testGridRoundsUpOntoTheMachineGridAndNeverBelowTheMinimum(): void
    {
        $machine = self::machine();
        self::assertSame(210 * self::MM, $machine->grid(0, 200 * self::MM));
        self::assertSame(210 * self::MM, $machine->grid(0, 210 * self::MM));
        self::assertSame(60 * self::MM, $machine->grid(0, 1));
        self::assertSame(1510 * self::MM, $machine->clamp(0, 2000 * self::MM, 1510 * self::MM));
    }

    public static function testTheSplitCostEqualsTheDirectCeiling(): void
    {
        $generator = 2702;
        for ($case = 0; $case < 50; $case++) {
            $generator = ($generator * 1103515245 + 12345) % 2147483648;
            $rate = $generator * 4194304 + $case;
            $area = (string) ($generator * 977 + $case) . '000000000000000000';
            $numerator = \Packvium\Support\BigInt::multiply($rate, $area);
            $direct = \Packvium\Support\BigInt::divide(\Packvium\Support\BigInt::add($numerator, '255999999999999'), MadeToMeasure::SQUARE_METRE_TICKS2);
            self::assertSame($direct, MadeToMeasure::perSquareMetre($rate, $area));
        }
        self::assertSame('1', MadeToMeasure::perSquareMetre(1, '1'));
        self::assertSame(305, self::machine(costPerSquareMetreMinor: 1000)->boxCostMinor(40, self::ticks(210, 210, 210)));
    }

    public static function testTheIntegerCubeRootIsTheLeastCubeAtOrAbove(): void
    {
        foreach (['0' => '0', '1' => '1', '7' => '2', '8' => '2', '9' => '3',
            '1000000000000000000000' => '10000000', '1000000000000000000001' => '10000001'] as $value => $root) {
            self::assertSame($root, MadeToMeasure::icbrtCeil((string) $value));
        }
    }

    public static function testTheWorkedRungListsMatchTheContract(): void
    {
        self::assertSame(
            [self::ticks(260, 260, 260), self::ticks(310, 310, 310), self::ticks(410, 410, 410), self::ticks(1510, 1510, 1510)],
            self::machine()->solvingEnvelopes(self::ticks(1510, 1510, 1510), array_fill(0, 8, self::ticks(100, 100, 100))),
        );
        $pinned = new MadeToMeasure(self::ticks(200, 100, 100), self::ticks(70, 10, 10));
        self::assertSame([self::ticks(1950, 100, 100)],
            $pinned->solvingEnvelopes(self::ticks(1950, 100, 100), array_fill(0, 5, self::ticks(100, 100, 100))));
        self::assertSame(
            [self::ticks(190, 190, 190), self::ticks(220, 220, 220), self::ticks(290, 290, 290), self::ticks(300, 300, 300)],
            self::machine(50, 10)->solvingEnvelopes(self::ticks(300, 300, 300), array_fill(0, 3, self::ticks(100, 100, 100))),
        );
        $rod = self::machine()->solvingEnvelopes(self::ticks(1510, 1510, 1510), [self::ticks(1000, 50, 50), self::ticks(100, 100, 100)])[0];
        self::assertTrue($rod[0] >= 1000 * self::MM && $rod[1] < 1000 * self::MM && $rod[2] < 1000 * self::MM);
    }

    public static function testAMachineOffItsOwnGridIsRefused(): void
    {
        self::assertThrows(InvalidArgumentException::class, static fn() => new Container('odd', Dimensions::mm(1500, 1510, 1510), madeToMeasure: self::machine()));
        self::assertThrows(InvalidArgumentException::class, static fn() => new MadeToMeasure([0, 1, 1], [1, 1, 1]));
    }

    public static function testTheFirstCompleteRungIsKeptWithoutTryingALargerOne(): void
    {
        $solver = new ScriptedRungSolver([[3, 100], [3, 100], [3, 100], [3, 100]]);
        [$one, $trials] = self::chain($solver);
        self::assertSame(1, $trials);
        self::assertCount(1, $solver->envelopes);
        self::assertEquals(Dimensions::mm(310, 110, 110), $one->state->container->innerDimensions);
        self::assertFalse($one->exhaustive);
    }

    public static function testAnIncompleteChainKeepsTheRoundKeyChoiceAndTheSmallerRungOnATie(): void
    {
        [$one, $trials] = self::chain(new ScriptedRungSolver([[1, 100], [2, 100], [1, 100], [2, 105]]));
        self::assertSame(4, $trials);
        self::assertSame([0, 100 * self::MM], array_map(static fn(Placement $p): int => $p->position->x, $one->state->placements));
    }

    public static function testAChainThatPlacesNothingStaysAZeroPlacementTrial(): void
    {
        [$one, $trials] = self::chain(new ScriptedRungSolver(array_fill(0, 4, [0, 100])));
        self::assertSame(4, $trials);
        self::assertSame(0, $one->state->placementCount());
    }

    public static function testACountedLimitReachedMidChainStopsItWithTheBestSoFar(): void
    {
        $stats = new SearchStats();
        $solver = new ScriptedRungSolver(array_fill(0, 4, [1, 100]), $stats);
        [$one, $trials] = self::chain($solver, new Deadline(60_000_000_000, null, new \Packvium\Algorithm\EffortBudget(maxSearchNodes: 1), $stats));
        self::assertSame(1, $trials);
        self::assertTrue($one->timeLimitReached);
    }

    public static function testTheWorkedOrderIsCutToA210MillimetreBox(): void
    {
        $packed = ArrayCodec::pack(self::request())['containers'][0];
        self::assertSame('210', $packed['inner_dimensions']['length']['value']);
        self::assertSame('213', $packed['outer_dimensions']['height']['value']);
        self::assertSame('200', $packed['achieved_dimensions']['width']['value']);
        self::assertSame(305, $packed['made_to_measure']['cost_minor']);
    }

    public static function testTheBoardWeightGrowsTheTare(): void
    {
        $request = self::request();
        $request['containers'][0]['made_to_measure']['tare_per_square_metre'] = '500';
        $packed = ArrayCodec::pack($request)['containers'][0];
        self::assertSame('132.3', $packed['made_to_measure']['tare_weight']['value']);
        self::assertSame('932.3', $packed['gross_weight']['value']);
    }

    public static function testTheRoundKeyReadsTheFabricatedPriceAndTheLatticeStandsDown(): void
    {
        $request = self::request();
        $request['containers'][] = ['id' => 'stock', 'cost_minor' => 300, 'inner_dimensions' => self::mm(210, 210, 210)];
        self::assertSame('stock', ArrayCodec::pack($request)['containers'][0]['container_type']);
        $compact = self::request();
        $compact['configuration']['require_placement_coordinates'] = false;
        $packed = ArrayCodec::pack($compact)['containers'][0];
        self::assertFalse(array_key_exists('lattice_summary', $packed));
        self::assertCount(8, $packed['placements']);
        $exact = self::request();
        $exact['configuration']['solver_profile'] = 'exact_small';
        $exact['items'][0]['quantity'] = 2;
        self::assertSame('not_proven', ArrayCodec::pack($exact)['optimality']['code']);
    }

    public static function testEachRefusedCombinationIsNamed(): void
    {
        $cases = [
            'container.made_to_measure+axles' => ['axles' => [['position' => '0'], ['position' => '1510']]],
            'container.made_to_measure+obstacles' => ['obstacles' => [['id' => 'post', 'dimensions' => self::mm(10, 10, 10)]]],
            'container.made_to_measure+void_fill_reserve_ratio' => ['void_fill_reserve_ratio' => 0.5],
        ];
        foreach ($cases as $name => $fields) {
            $request = self::request();
            $request['containers'][0] = array_merge($request['containers'][0], $fields);
            self::assertRefusedWith(UnsupportedFeatureException::class, $name, $request);
        }
        $request = self::request();
        $request['fixed_placements'] = [['item_type' => 'cube', 'container_type' => 'machine', 'orientation' => 'LWH']];
        self::assertRefusedWith(UnsupportedFeatureException::class, 'fixed_placements+container.made_to_measure', $request);
    }

    public static function testEachRequestErrorNamesItsRule(): void
    {
        $cases = [
            ['/containers/0/made_to_measure/extra: is not a known field', ['extra' => 1]],
            ['/containers/0/made_to_measure/minimum/width: must be positive', ['minimum' => self::mm(60, 0, 60)]],
            ['/containers/0/made_to_measure/increment/height: must be positive', ['increment' => self::mm(25, 25, 0)]],
            ['/containers/0/made_to_measure/minimum/length: exceeds inner_dimensions', ['minimum' => self::mm(1600, 60, 60)]],
            ['/containers/0/made_to_measure/increment/width: does not divide inner_dimensions minus minimum', ['increment' => self::mm(25, 30, 25)]],
            ['/containers/0/made_to_measure/cost_per_square_metre_minor: prices the largest box above 9007199254740991', ['cost_per_square_metre_minor' => 9007199254740991]],
        ];
        foreach ($cases as [$message, $fields]) {
            $request = self::request();
            $request['containers'][0]['made_to_measure'] = array_merge($request['containers'][0]['made_to_measure'], $fields);
            self::assertRefusedWith(InvalidRequestException::class, 'invalid_request: ' . $message, $request);
        }
        $request = self::request();
        $request['containers'][0]['outer_dimensions'] = self::mm(1600, 1600, 1600);
        self::assertRefusedWith(InvalidRequestException::class, '/containers/0/outer_dimensions: cannot be combined with made_to_measure', $request);
    }

    public static function testTheValidatorRecutsTheBoxFromTheDeclaredMachine(): void
    {
        $declared = new Container('machine', Dimensions::mm(1510, 1510, 1510), costMinor: 40, madeToMeasure: self::machine(costPerSquareMetreMinor: 1000));
        $item = Item::create('cube', Dimensions::mm(100, 100, 100));
        $instance = $item->instances()[0];
        $placement = new Placement($instance, new Point(0, 0, 0), Rotation::LWH, $instance->dimensions(), new Point(0, 0, 0), $instance->dimensions());
        $request = new PackingRequest([$item], [$declared]);
        $good = $declared->fabricate(self::ticks(100, 100, 100));
        $validator = new IndependentSolutionValidator();
        self::assertTrue($validator->validate($request, [new PackedContainer($good, 1, [$placement])])->valid);
        $stale = new Container('machine', $good->innerDimensions, $good->outerDimensions, costMinor: 40, madeToMeasure: $declared->madeToMeasure);
        $codes = array_map(static fn($issue) => $issue->code, $validator->validate($request, [new PackedContainer($stale, 1, [$placement])])->issues);
        self::assertSame(['made_to_measure_cost_mismatch'], $codes);
    }

    /** @return array{0:SingleContainerSolution,1:int} */
    private static function chain(ScriptedRungSolver $solver, ?Deadline $deadline = null): array
    {
        $declared = new Container('machine', Dimensions::mm(1510, 1510, 1510), costMinor: 40, madeToMeasure: self::machine(costPerSquareMetreMinor: 1000));
        $items = Item::create('cube', Dimensions::mm(100, 100, 100), 0, 3)->instances();
        $method = new ReflectionMethod(SolverOrchestrator::class, 'madeToMeasureTrial');
        $method->setAccessible(true);
        return $method->invoke(new SolverOrchestrator(), $solver, $declared, 1, $items, PackingConfig::fast(), $solver->stats ?? new SearchStats(), $deadline ?? Deadline::ofMilliseconds(60_000));
    }

    private static function assertRefusedWith(string $class, string $fragment, array $request): void
    {
        try {
            ArrayCodec::pack($request);
        } catch (\Throwable $error) {
            self::assertTrue($error instanceof $class, get_class($error) . ': ' . $error->getMessage());
            self::assertTrue(str_contains($error->getMessage(), $fragment), $error->getMessage());
            return;
        }
        self::assertTrue(false, "expected a refusal naming {$fragment}");
    }

    private static function machine(int $minimum = 60, int $increment = 25, int $costPerSquareMetreMinor = 0): MadeToMeasure
    {
        return new MadeToMeasure(self::ticks($minimum, $minimum, $minimum), self::ticks($increment, $increment, $increment), $costPerSquareMetreMinor);
    }

    /** @return array{0:int,1:int,2:int} */
    private static function ticks(int $length, int $width, int $height): array
    {
        return [$length * self::MM, $width * self::MM, $height * self::MM];
    }

    /** @return array{length:string,width:string,height:string} */
    private static function mm(int $length, int $width, int $height): array
    {
        return ['length' => (string) $length, 'width' => (string) $width, 'height' => (string) $height];
    }

    /** @return array<string,mixed> */
    private static function request(): array
    {
        return [
            'units' => ['length' => 'mm'],
            'configuration' => ['solver_profile' => 'fast', 'minimum_support_ratio' => 1, 'time_limit_ms' => 300000,
                'effort_budget' => ['max_search_nodes' => 1000000]],
            'items' => [['id' => 'cube', 'quantity' => 8, 'weight' => '100', 'dimensions' => self::mm(100, 100, 100)]],
            'containers' => [['id' => 'machine', 'cost_minor' => 40, 'inner_dimensions' => self::mm(1510, 1510, 1510),
                'made_to_measure' => ['minimum' => self::mm(60, 60, 60), 'increment' => self::mm(25, 25, 25),
                    'cost_per_square_metre_minor' => 1000, 'outer_allowance' => self::mm(3, 3, 3)]]],
        ];
    }
}

/** Places a scripted number of items per rung, along x at the scripted pitch in millimetres. */
final class ScriptedRungSolver implements SingleContainerSolver
{
    /** @var list<Dimensions> */
    public array $envelopes = [];

    /** @param list<array{0:int,1:int}> $script */
    public function __construct(private array $script, public ?SearchStats $stats = null)
    {
    }

    public function name(): string
    {
        return 'scripted';
    }

    public function packOne(Container $container, int $sequence, array $items, PackingConfig $config, SearchStats $stats, Deadline $deadline): SingleContainerSolution
    {
        [$count, $pitch] = $this->script[count($this->envelopes)];
        $this->envelopes[] = $container->innerDimensions;
        $stats->searchNodesExpanded++;
        $state = new ContainerState($container, $sequence);
        foreach (array_slice($items, 0, $count) as $index => $instance) {
            $origin = new Point($index * $pitch * Length::TICKS_PER_MM, 0, 0);
            $state->addDirect(new Placement($instance, $origin, Rotation::LWH, $instance->dimensions(), $origin, $instance->dimensions()));
        }
        return new SingleContainerSolution($state, array_slice($items, $count));
    }
}
