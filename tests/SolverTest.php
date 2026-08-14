<?php
declare(strict_types=1);

namespace Packvium\Tests;

use InvalidArgumentException;
use ReflectionMethod;
use Packvium\Algorithm\ContainerState;
use Packvium\Algorithm\BeamPacker;
use Packvium\Algorithm\CandidateFinder;
use Packvium\Algorithm\Deadline;
use Packvium\Algorithm\DeterministicRandom;
use Packvium\Algorithm\EffortBudget;
use Packvium\Algorithm\ExactSmallSolver;
use Packvium\Algorithm\ExtremePointSolver;
use Packvium\Algorithm\GridSolver;
use Packvium\Algorithm\HomogeneousBlockSolver;
use Packvium\Algorithm\GroupBatcher;
use Packvium\Algorithm\LayerSolver;
use Packvium\Algorithm\MaximalSpaceSolver;
use Packvium\Algorithm\SearchStats;
use Packvium\Algorithm\SingleContainerSolution;
use Packvium\Algorithm\SolverOrchestrator;
use Packvium\Algorithm\Space;
use Packvium\Algorithm\TimeLimitReached;
use Packvium\Algorithm\UnknownSolverException;
use Packvium\Config\PackingConfig;
use Packvium\Config\SolverProfile;
use Packvium\Constraint\ConstraintSet;
use Packvium\Constraint\LoadCalculator;
use Packvium\Domain\AxisAlignedBox;
use Packvium\Domain\Container;
use Packvium\Domain\Dimensions;
use Packvium\Domain\Item;
use Packvium\Domain\ItemInstance;
use Packvium\Domain\Obstacle;
use Packvium\Domain\PackedContainer;
use Packvium\Domain\Placement;
use Packvium\Domain\Point;
use Packvium\Domain\Rotation;
use Packvium\Extension\DefaultCandidateScorer;
use Packvium\Support\BigInt;
use Packvium\Unit\Length;

/**
 * The search machinery: time budgets, seeded randomness, free-space bookkeeping and
 * each of the single-container solvers.
 *
 * Every solver is exercised through the same two questions — does it place what it
 * claims, and is what it placed physically sound — because a solver that reports a
 * placement it never verified is the failure mode that matters.
 */
final class SolverTest extends TestCase
{
    /** @param array<string,mixed> $options @return list<\Packvium\Domain\ItemInstance> */
    private static function instances(string $id, int $l, int $w, int $h, array $options = []): array
    {
        return Item::create($id, Dimensions::mm($l, $w, $h), ...$options)->instances();
    }

    private static function generous(): Deadline
    {
        return Deadline::ofMilliseconds(60_000);
    }

    /** Place `$item` at the state's best candidate point, mutating the state in place. */
    private static function extend(ContainerState $state, ItemInstance $item, ?PackingConfig $config = null): ContainerState
    {
        $config ??= PackingConfig::balanced();
        $constraints = ConstraintSet::defaults($config->minimumSupportRatio);
        [$candidate] = CandidateFinder::find($state, $item, $config, $constraints, new SearchStats(), self::generous(), new DefaultCandidateScorer(), 1);
        $state->add(new Placement($item, $candidate->position, $candidate->rotation, $candidate->dimensions,
            $candidate->point, $candidate->envelopeDimensions));
        return $state;
    }

    public static function testContainerStateCachesExactVolumeAndRuleSensitivityAcrossCopies(): void
    {
        $box=Container::create('c',Dimensions::mm(100,100,220));
        $instances=self::instances('crate',100,100,100,[
            'quantity'=>3,'nestingHeight'=>Length::mm(40),'maxStackedItems'=>3,'stopIndex'=>1,
        ]);
        $state=new ContainerState($box,1);
        foreach($instances as $index=>$instance){
            $point=new Point(0,0,Length::mm($index*60)->ticks);
            $dimensions=$instance->dimensions();
            $state->addDirect(new Placement($instance,$point,\Packvium\Domain\Rotation::LWH,$dimensions,$point,$dimensions));
        }
        self::assertSame(\Packvium\Domain\Nesting::usedVolume($state->placements),$state->usedVolume);
        self::assertTrue($state->stackSensitive);
        self::assertTrue($state->routeSensitive);
        $keys=array_map(static fn(Point $point):array=>[$point->z,$point->y,$point->x],$state->orderedPoints);
        $sorted=$keys;sort($sorted);
        self::assertSame($sorted,$keys);
        $copy=$state->copy();
        self::assertSame($state->usedVolume,$copy->usedVolume);
        self::assertTrue($copy->stackSensitive);
        self::assertTrue($copy->routeSensitive);
        self::assertEquals($state->orderedPoints,$copy->orderedPoints);
    }

    public static function testADeadlineCaughtInsideTheBeamIsPreservedInTheSolution(): void
    {
        $box=Container::create('c',Dimensions::mm(100,100,100));
        $items=self::instances('a',20,20,20,['quantity'=>2]);
        $config=PackingConfig::balanced();
        $solution=BeamPacker::pack(
            $box,1,$items,$config,\Packvium\Constraint\ConstraintSet::defaults(0.0),
            new SearchStats(),new Deadline(-1),new \Packvium\Extension\DefaultCandidateScorer(),
        );
        self::assertTrue($solution->timeLimitReached);
        self::assertSame(
            array_map(static fn($item)=>$item->id(),$items),
            array_map(static fn($item)=>$item->id(),$solution->unpacked),
        );
    }

    public static function testAnExpiredPortfolioPreservesAStructuralDimensionProof(): void
    {
        $oversized=self::instances('oversized',200,200,200);
        $searchable=self::instances('searchable',10,10,10);
        $container=Container::create('small',Dimensions::mm(100,100,100));
        $solution=(new SolverOrchestrator())->solve(
            [...$oversized,...$searchable],
            [$container],
            PackingConfig::fast(),
            new Deadline(-1),
        )[0];

        self::assertTrue($solution->timeLimitReached);
        $rejected=[];
        foreach($solution->unpacked as $item)$rejected[$item->instance->item->id]=$item;
        self::assertSame('no_compatible_container_dimensions',$rejected['oversized']->reason);
        self::assertSame('proven',$rejected['oversized']->proof->level);
        self::assertSame('time_limit',$rejected['searchable']->reason);
        self::assertSame('unknown_due_to_limit',$rejected['searchable']->proof->level);
    }

    public static function testAHighPriorityItemLeadsEveryBuiltInOrdering(): void
    {
        // Priority is a preference, not a guarantee: a container that can only hold one
        // of the two items should hold the high-priority one, even though it is far smaller.
        $small=self::instances('small',10,10,10,['priority'=>5]);
        $big=self::instances('big',100,100,100);
        $container=Container::create('box',Dimensions::mm(100,100,100),quantity:1);
        $config=new PackingConfig(profile:SolverProfile::Balanced,multiStartOrders:1,timeLimitMs:2_000);

        $solutions=(new SolverOrchestrator())->solve([...$big,...$small],[$container],$config,Deadline::ofMilliseconds(2_000));

        $packedIds=[];
        foreach($solutions as $solution)
            foreach($solution->containers as $packedContainer)
                foreach($packedContainer->placements as $placement)
                    $packedIds[$placement->instance->id()]=true;
        self::assertSame(['small#1'=>true],$packedIds);
    }

    public static function testALatticeSolverRunsOnceRatherThanOncePerOrdering(): void
    {
        // GridSolver already declares this; LayerSolver re-sorts by a total key ending
        // in item id, discarding whatever order it is handed, so it must declare it too.
        $items=[...self::instances('a',20,20,20,['quantity'=>3]),...self::instances('b',10,10,10,['quantity'=>3])];
        $container=Container::create('box',Dimensions::mm(100,100,100));
        $run=(new SolverOrchestrator())->solvePortfolio(
            $items,[$container],PackingConfig::balanced(),Deadline::ofMilliseconds(2_000),
        );
        $layerStarts=array_values(array_filter($run->starts,static fn($start)=>str_starts_with($start->id,'layer:')));
        self::assertCount(1,$layerStarts);
    }

    /** @return list<\Packvium\Domain\ItemInstance> */
    private static function threeTypeOrderScenario(): array
    {
        // Three item types, each diverse on a different axis (volume, base area,
        // weight, rotation freedom) so all four built-in named ordering strategies
        // produce a genuinely distinct order, plus enough instances (two of each)
        // that the remaining slots up to the default multiStartOrders=8 are filled
        // by seeded random shuffles distinct from those four and from each other.
        return [
            ...self::instances('tall_thin',10,50,2,['weight'=>300,'quantity'=>2,'allowedRotations'=>[\Packvium\Domain\Rotation::LWH]]),
            ...self::instances('cube',20,20,20,['weight'=>100,'quantity'=>2]),
            ...self::instances('heavy_flat',30,10,20,['weight'=>900,'quantity'=>2,'allowedRotations'=>[\Packvium\Domain\Rotation::LWH,\Packvium\Domain\Rotation::WLH,\Packvium\Domain\Rotation::LHW]]),
        ];
    }

    public static function testThePortfolioDropsFromSixteenStartsToNineOnAThreeTypeOrder(): void
    {
        // The acceptance criterion, made executable rather than only claimed in prose:
        // this scene naively runs 2 solvers (extreme_points, layer) x 8 orders = 16
        // starts; declaring LayerSolver order-insensitive collapses its eight down to
        // one, leaving 8 + 1 = 9.
        $items=self::threeTypeOrderScenario();
        $container=Container::create('box',Dimensions::mm(100,100,100));
        $config=PackingConfig::balanced();
        self::assertSame(8,$config->multiStartOrders);

        $run=(new SolverOrchestrator())->solvePortfolio($items,[$container],$config,self::generous());

        self::assertCount(9,$run->starts);
        $bySolver=['extreme_points'=>0,'layer'=>0];
        foreach($run->starts as $start){
            [$solverName]=explode(':',$start->id,2);
            self::assertTrue(array_key_exists($solverName,$bySolver),"unexpected solver in start id: {$start->id}");
            $bySolver[$solverName]++;
        }
        self::assertSame(['extreme_points'=>8,'layer'=>1],$bySolver);
    }

    public static function testALayerSolverProducesTheIdenticalPackingRegardlessOfTheOrderItIsHanded(): void
    {
        // Declaring orderInsensitive() true is only safe if it is actually true.
        // LayerSolver re-sorts by its own total key before placing anything, so
        // feeding it several genuinely different orders of the same items must
        // produce the identical packing every time.
        $items=self::threeTypeOrderScenario();
        $container=Container::create('box',Dimensions::mm(100,100,100));
        $config=PackingConfig::balanced();
        $solver=new LayerSolver();

        $signature=static function(array $order) use ($solver,$container,$config):string{
            $solution=$solver->packOne($container,0,$order,$config,new SearchStats(),self::generous());
            $rows=array_map(
                static fn(Placement $p)=>$p->instance->id().'|'.$p->position->x.','.$p->position->y.','.$p->position->z.'|'.$p->rotation->value,
                $solution->state->placements,
            );
            sort($rows);
            return implode(';',$rows);
        };

        $orderings=[
            $items,
            array_reverse($items),
            [...array_slice($items,3),...array_slice($items,0,3)],
            (static function(array $arr):array{shuffle($arr);return $arr;})($items),
        ];
        $signatures=array_unique(array_map($signature,$orderings));
        self::assertCount(1,$signatures);
    }

    public static function testLayerSolverProducesTheIdenticalPackingOnRealBr1Data(): void
    {
        // A third, previously-unattempted claim: measured BR1 utilisation
        // does not regress. The corpus that claim needed did not exist in this
        // workspace when this was last measured; the corpus has since been committed
        // (benchmarks/datasets/br/thpack1.txt, Python side only -- there is no PHP
        // BR parser). This test uses that real published instance's own item
        // dimensions and quantities (instance 1, hand-verified against the raw file
        // in benchmarks/tests/test_br_dataset.py) rather than fabricating scene
        // data, and applies the exact same technique as the companion test above:
        // if LayerSolver produces a byte-identical packing regardless of order on
        // this real BR1 scene, collapsing its eight redundant per-order starts down
        // to one cannot have changed -- let alone regressed -- the achieved
        // utilisation.
        $items=[
            ...self::instances('type-1',108,76,30,['quantity'=>40]),
            ...self::instances('type-2',110,43,25,['quantity'=>33]),
            ...self::instances('type-3',92,81,55,['quantity'=>39]),
        ];
        $container=Container::create('br-container',Dimensions::mm(587,233,220));
        $config=PackingConfig::balanced();
        $solver=new LayerSolver();

        $signature=static function(array $order) use ($solver,$container,$config):string{
            $solution=$solver->packOne($container,0,$order,$config,new SearchStats(),self::generous());
            $rows=array_map(
                static fn(Placement $p)=>$p->instance->id().'|'.$p->position->x.','.$p->position->y.','.$p->position->z.'|'.$p->rotation->value,
                $solution->state->placements,
            );
            sort($rows);
            return implode(';',$rows);
        };

        $split=intdiv(count($items),2);
        $orderings=[
            $items,
            array_reverse($items),
            [...array_slice($items,$split),...array_slice($items,0,$split)],
            (static function(array $arr):array{shuffle($arr);return $arr;})($items),
        ];
        $signatures=array_unique(array_map($signature,$orderings));
        self::assertCount(1,$signatures);
    }

    public static function testExplicitSolverSelectionDrivesWhichSolverActuallyWins(): void
    {
        $items=self::instances('a',40,40,40,['quantity'=>4]);
        $container=Container::create('box',Dimensions::mm(100,100,100),quantity:1);
        $config=new PackingConfig(solvers:['grid']);

        $solutions=(new SolverOrchestrator())->solve($items,[$container],$config,Deadline::ofMilliseconds(2_000));

        foreach($solutions as $solution)self::assertTrue(str_starts_with($solution->solverName,'grid'),$solution->solverName);
    }

    public static function testAnUnknownSolverNameIsAStructuredErrorNotASilentFallback(): void
    {
        $items=self::instances('a',10,10,10);
        $container=Container::create('box',Dimensions::mm(100,100,100));
        $config=new PackingConfig(solvers:['brute_force']);

        self::assertThrows(UnknownSolverException::class, static fn() =>
            (new SolverOrchestrator())->solve($items,[$container],$config,Deadline::ofMilliseconds(2_000)));
    }

    public static function testMoreBlockSearchEffortCannotWorsenTheObjective(): void
    {
        $box=Container::create('box',Dimensions::mm(300,200,200),quantity:1);
        $items=[...self::instances('large',100,100,100,['quantity'=>8]),...self::instances('small',60,50,40,['quantity'=>20])];
        $solver=new HomogeneousBlockSolver();
        $low=$solver->packOne($box,1,$items,new PackingConfig(profile:SolverProfile::Quality,maxCandidatesPerItem:16,containerPlanBeamWidth:16,containerPlanNodeLimit:1),new SearchStats(),self::generous());
        $high=$solver->packOne($box,1,$items,new PackingConfig(profile:SolverProfile::Quality,maxCandidatesPerItem:16,containerPlanBeamWidth:16,containerPlanNodeLimit:100_000),new SearchStats(),self::generous());
        self::assertTrue(count($high->unpacked)<count($low->unpacked)||(count($high->unpacked)===count($low->unpacked)&&BigInt::compare($high->state->usedVolume,$low->state->usedVolume)>=0));
        self::assertPhysicallySound($high->state);
    }

    public static function testBlockSearchFallsBackWhenABusinessRuleCanDistinguishPlacements(): void
    {
        $box=Container::create('box',Dimensions::mm(100,100,200),quantity:1);
        $items=self::instances('fragile',100,100,100,['quantity'=>2,'stackable'=>false]);
        $solution=(new HomogeneousBlockSolver())->packOne($box,1,$items,PackingConfig::quality(),new SearchStats(),self::generous());
        self::assertCount(1,$solution->state->placements);
        self::assertCount(1,$solution->unpacked);
        self::assertPhysicallySound($solution->state);
    }

    private static function assertPhysicallySound(ContainerState $state): void
    {
        $boundary = new AxisAlignedBox(new Point(0, 0, 0), $state->container->innerDimensions);
        $boxes = array_map(static fn($p) => $p->envelopeBox(), $state->placements);
        foreach ($boxes as $index => $box) {
            self::assertTrue($boundary->contains($box), 'a placement escaped the container');
            foreach ($state->container->obstacles as $obstacle) {
                foreach ($obstacle->boxes() as $obstacleBox) {
                    self::assertFalse($box->intersects($obstacleBox), 'a placement hit an obstacle');
                }
            }
            for ($other = $index + 1; $other < count($boxes); $other++) {
                self::assertFalse($box->intersects($boxes[$other]), 'two placements overlap');
            }
        }
    }

    private static function space(int $x, int $y, int $z, int $l, int $w, int $h): Space
    {
        return new Space(new Point($x, $y, $z), new Dimensions(new Length($l), new Length($w), new Length($h)));
    }

    private static function solid(int $x, int $y, int $z, int $l, int $w, int $h): AxisAlignedBox
    {
        return new AxisAlignedBox(new Point($x, $y, $z), new Dimensions(new Length($l), new Length($w), new Length($h)));
    }

    // ------------------------------------------------------------------ time budget

    public static function testAFreshDeadlineHasItsWholeBudget(): void
    {
        $deadline = Deadline::ofMilliseconds(1_000);
        self::assertTrue($deadline->remainingNs() > 0);
        self::assertLessThanOrEqual(1_000_000_000, $deadline->remainingNs());
        self::assertFalse($deadline->expired());
    }

    private static function checkBudgetClock(): \Closure
    {
        $reads=0;
        return static function()use(&$reads):int{return $reads++*1_000_000;};
    }

    /** Same idea as checkBudgetClock, but a thousand times faster per read -- stands
     * in for a near-idle machine versus a heavily loaded one, without real timing. */
    private static function fastClock(): \Closure
    {
        $reads=0;
        return static function()use(&$reads):int{return $reads++*1_000;};
    }

    public static function testAnInjectedClockExpiresBeforeTheFirstPlacementWithoutSleeping(): void
    {
        $box=Container::create('c',Dimensions::mm(100,100,100));
        $items=self::instances('a',20,20,20,['quantity'=>2]);
        $config=PackingConfig::balanced();
        $solution=BeamPacker::pack(
            $box,1,$items,$config,\Packvium\Constraint\ConstraintSet::defaults(0.0),
            new SearchStats(),Deadline::ofMilliseconds(1,self::checkBudgetClock()),
            new \Packvium\Extension\DefaultCandidateScorer(),
        );

        self::assertTrue($solution->timeLimitReached);
        self::assertCount(0,$solution->state->placements);
        self::assertSame(
            array_map(static fn($item)=>$item->id(),$items),
            array_map(static fn($item)=>$item->id(),$solution->unpacked),
        );
    }

    public static function testAnInjectedClockExpiresMidSearchWithoutSleeping(): void
    {
        $box=Container::create('c',Dimensions::mm(100,100,100));
        $items=self::instances('a',20,20,20,['quantity'=>8]);
        $config=PackingConfig::balanced();
        $solution=BeamPacker::pack(
            $box,1,$items,$config,\Packvium\Constraint\ConstraintSet::defaults(0.0),
            new SearchStats(),Deadline::ofMilliseconds(8,self::checkBudgetClock()),
            new \Packvium\Extension\DefaultCandidateScorer(),
        );
        $packed=count($solution->state->placements);

        self::assertTrue($solution->timeLimitReached);
        self::assertGreaterThan(0,$packed);
        self::assertTrue($packed<count($items));
        self::assertSame(count($items),$packed+count($solution->unpacked));
    }

    public static function testASliceKeepsTheInjectedClock(): void
    {
        $clock=self::checkBudgetClock();
        $parent=Deadline::ofMilliseconds(10,$clock);
        $child=$parent->slice(2);

        self::assertFalse($child->expired());
        while(!$child->expired()){}
        self::assertTrue($parent->remainingNs()<10*1_000_000);
        while(!$parent->expired()){}
    }

    public static function testPackerPassesTheInjectedClockToTheWholePortfolio(): void
    {
        $result=(new \Packvium\Packer(
            new PackingConfig(timeLimitMs:1),
            clock:self::checkBudgetClock(),
        ))->pack(
            [Item::create('a',Dimensions::mm(20,20,20),quantity:8)],
            [Container::create('c',Dimensions::mm(100,100,100))],
        );

        self::assertTrue($result->algorithm->timeLimitReached);
        self::assertSame('time_limit',$result->termination->code);
    }

    public static function testAnExhaustedDeadlineRaisesRatherThanReturningQuietly(): void
    {
        $expired = new Deadline(0);
        self::assertTrue($expired->expired());
        self::assertThrows(TimeLimitReached::class, static fn() => $expired->check());
    }

    public static function testASliceIsNeverTooSmallToPlaceAnything(): void
    {
        // A long multi-start list would otherwise hand out budgets no solver can use.
        $deadline = new Deadline(500 * Deadline::MINIMUM_SLICE_NS);
        self::assertTrue($deadline->slice(10_000)->remainingNs() >= Deadline::MINIMUM_SLICE_NS - 1_000_000);
    }

    public static function testASliceNeverExceedsWhatIsLeft(): void
    {
        $deadline = Deadline::ofMilliseconds(1_000);
        self::assertLessThanOrEqual($deadline->remainingNs() + 1_000_000, $deadline->slice(4)->remainingNs());
    }

    // ------------------------------------------------------- effort budgets

    public static function testAnEffortBudgetBoundsSearchNodesRegardlessOfClockSpeed(): void
    {
        // The reproducibility the effort budget promises: a caller who bounds by effort alone
        // gets the same stopping point under a slow clock and a fast one, because
        // nothing in the stopping decision depends on elapsed time.
        $box = Container::create('c', Dimensions::mm(200, 200, 200));
        $order = self::instances('cube', 20, 20, 20, ['quantity' => 20]);
        $budget = new EffortBudget(maxSearchNodes: 5);
        $config = new PackingConfig(timeLimitMs: 60_000, multiStartOrders: 1, effortBudget: $budget);

        $run = static fn(\Closure $clock) => (new SolverOrchestrator())->solvePortfolio($order, [$box], $config, new Deadline($config->timeLimitMs * 1_000_000, $clock));

        $slow = $run(self::checkBudgetClock());
        $fast = $run(self::fastClock());
        $placedKey = static fn($solution) => array_map(
            static fn($p) => $p->instance->id() . '@' . $p->position->x . ',' . $p->position->y . ',' . $p->position->z,
            $solution->solutions[0]->containers[0]->placements,
        );
        self::assertSame($placedKey($slow), $placedKey($fast));
        self::assertCount(5, $slow->solutions[0]->containers[0]->placements);
    }

    public static function testAnEffortBudgetLeavesTimeOnlyBehaviourUnchangedWhenAbsent(): void
    {
        $deadline = new Deadline(1_000_000);
        self::assertFalse($deadline->expired());
        self::assertFalse($deadline->effortExceeded());
    }

    public static function testAnEffortBudgetDoesNotOverrideATighterWallClock(): void
    {
        // The wall clock stays a live safety cutoff even with an effort budget set.
        $stats = new SearchStats();
        $alreadyExpired = new Deadline(-1);
        $bounded = $alreadyExpired->withEffort(new EffortBudget(maxSearchNodes: 1_000_000), $stats);
        self::assertTrue($bounded->expired());
    }

    public static function testAnEffortBudgetSearchNodeCountActuallyStopsTheSearch(): void
    {
        $stats = new SearchStats();
        $generousTime = Deadline::ofMilliseconds(60_000);
        $bounded = $generousTime->withEffort(new EffortBudget(maxSearchNodes: 3), $stats);
        self::assertFalse($bounded->expired());
        $stats->searchNodesExpanded = 3;
        self::assertTrue($bounded->expired());
    }

    public static function testMaxRestartsBoundsThePortfolioStartCount(): void
    {
        $items = [
            ...self::instances('a', 20, 20, 20),
            ...self::instances('b', 15, 15, 15),
            ...self::instances('c', 10, 10, 10),
        ];
        $box = Container::create('box', Dimensions::mm(200, 200, 200));
        $budget = new EffortBudget(maxRestarts: 2);
        $config = new PackingConfig(solvers: ['extreme_points'], multiStartOrders: 8, effortBudget: $budget);

        $run = (new SolverOrchestrator())->solvePortfolio($items, [$box], $config, Deadline::ofMilliseconds(5_000));

        self::assertCount(2, $run->starts);
    }

    public static function testACompleteGridStartStopsThePortfolioBeforeASlowerOneWastesTheDeadline(): void
    {
        // A single item type's regular lattice is always at least as good as any other
        // in-portfolio solver on every objective key once it has placed everything, so
        // a slower start after it can only spend what remains of the deadline on an
        // answer that is provably no better. 2,000 identical cubes used to take
        // the full 60s limit under the default balanced portfolio, with extreme_points
        // alone reaching only 297/2000 -- grid's already-complete answer sat unused the
        // whole time.
        $items = self::instances('cube', 20, 20, 20, ['quantity' => 2000]);
        $box = Container::create('box', Dimensions::mm(400, 400, 400), quantity: 20);
        $config = PackingConfig::balanced(60_000);

        $run = (new SolverOrchestrator())->solvePortfolio($items, [$box], $config, Deadline::ofMilliseconds(60_000));

        self::assertCount(1, $run->solutions);
        self::assertSame('grid:volume', $run->solutions[0]->solverName);
        self::assertSame([], $run->solutions[0]->unpacked);
        $started = array_values(array_map(
            static fn($start) => $start->id,
            array_filter($run->starts, static fn($start) => $start->started),
        ));
        self::assertSame(['grid:volume'], $started);
    }

    // --------------------------------------------------------------- solver metrics

    public static function testCandidateMetricsCountGeometryAndSupportWork(): void
    {
        $box=Container::create('c',Dimensions::mm(100,100,100));
        [$base,$upper]=self::instances('a',40,40,40,['quantity'=>2]);
        $config=new PackingConfig(minimumSupportRatio:0.5);
        $constraints=\Packvium\Constraint\ConstraintSet::defaults(0.5);
        $scorer=new \Packvium\Extension\DefaultCandidateScorer();
        $state=new ContainerState($box,1);
        $first=\Packvium\Algorithm\CandidateFinder::find(
            $state,$base,$config,$constraints,new SearchStats(),self::generous(),$scorer,1,
        )[0];
        $state=BeamPacker::extended($state,$base,$first);
        $stats=new SearchStats();

        \Packvium\Algorithm\CandidateFinder::find(
            $state,$upper,$config,$constraints,$stats,self::generous(),$scorer,
        );
        $metrics=$stats->metrics();

        self::assertGreaterThan(0,$metrics->candidatePointsConsidered);
        self::assertSame($stats->placementsAttempted,$metrics->orientationsConsidered);
        self::assertSame($stats->candidatesEvaluated,$metrics->feasibleCandidates);
        self::assertGreaterThan(0,$metrics->collisionChecks);
        self::assertGreaterThan(0,$metrics->supportChecks);
    }

    public static function testSpaceAndNodeMetricsCountTheirOwnSolverWork(): void
    {
        $stats=new SearchStats();
        MaximalSpaceSolver::subtractAll(
            [self::space(0,0,0,100,100,100)],
            [self::solid(20,20,20,30,30,30)],
            $stats,
        );
        $box=Container::create('c',Dimensions::mm(100,100,100));
        $config=PackingConfig::balanced();
        BeamPacker::pack(
            $box,1,self::instances('a',20,20,20,['quantity'=>2]),$config,
            \Packvium\Constraint\ConstraintSet::defaults(0.0),$stats,self::generous(),
            new \Packvium\Extension\DefaultCandidateScorer(),
        );

        self::assertGreaterThan(0,$stats->spacePartitions);
        self::assertGreaterThan(0,$stats->searchNodesExpanded);
    }

    // ------------------------------------------------------------ seeded randomness

    public static function testTheSameSeedReplaysTheSameSequence(): void
    {
        // Reproducibility is a documented promise, and the multi-start orderings are
        // drawn from here.
        $first = array_map(static fn() => (new DeterministicRandom(42))->nextInt(1_000), range(1, 3));
        $again = array_map(static fn() => (new DeterministicRandom(42))->nextInt(1_000), range(1, 3));
        self::assertSame($first, $again);
    }

    public static function testShufflesAreReproducibleAndArePermutations(): void
    {
        $values = range(0, 19);
        $once = (new DeterministicRandom(7))->shuffled($values);
        self::assertSame($once, (new DeterministicRandom(7))->shuffled($values));

        $sorted = $once;
        sort($sorted);
        self::assertSame($values, $sorted);
        self::assertNotSame($values, $once, 'a shuffle that changes nothing is not a shuffle');
    }

    public static function testDifferentSeedsDiverge(): void
    {
        $values = range(0, 19);
        self::assertNotSame((new DeterministicRandom(1))->shuffled($values),
            (new DeterministicRandom(2))->shuffled($values));
    }

    public static function testDegenerateBoundsAreAnsweredWithoutDrawing(): void
    {
        $rng = new DeterministicRandom(3);
        self::assertSame(0, $rng->nextInt(0));
        self::assertSame(0, $rng->nextInt(1));
    }

    public static function testDrawsStayInRangeAndReachEveryValue(): void
    {
        // Rejection sampling removes the modulo bias a plain `% upper` would leave.
        $rng = new DeterministicRandom(11);
        $counts = [0, 0, 0];
        for ($draw = 0; $draw < 3_000; $draw++) {
            $counts[$rng->nextInt(3)]++;
        }
        foreach ($counts as $value => $count) {
            self::assertTrue($count > 300 && $count < 1_700, "value {$value} appeared {$count} times");
        }
    }

    public static function testTheSequenceMatchesPythonForTheSameSeed(): void
    {
        // Golden vectors taken from the Python generator. The two implementations must
        // draw identically or the seeded multi-start orderings diverge and the languages
        // stop agreeing on the chosen packing. The mirror of this test lives in
        // packvium-python/tests/test_solvers.py.
        $rng = new DeterministicRandom(42);
        $draws = [];
        for ($index = 0; $index < 5; $index++) {
            $draws[] = $rng->nextInt(100);
        }
        self::assertSame([98, 91, 19, 44, 31], $draws);
        self::assertSame([2, 1, 0], (new DeterministicRandom(42))->shuffled([0, 1, 2]));
        self::assertSame([1, 2, 4, 3, 5, 0], (new DeterministicRandom(7))->shuffled(range(0, 5)));
    }

    // ----------------------------------------------------------------- group batches

    public static function testUngroupedItemsAreOfferedOneAtATime(): void
    {
        $items = self::instances('a', 10, 10, 10, ['quantity' => 3]);
        self::assertSame([[$items[0]], [$items[1]], [$items[2]]], GroupBatcher::batches($items));
    }

    public static function testAGroupIsCollectedIntoASingleBatch(): void
    {
        // Group members share a container, so a solver is offered all of them at once
        // and can reject them as a whole.
        $pair = self::instances('pair', 10, 10, 10, ['quantity' => 2, 'group' => 'kit']);
        $loose = self::instances('loose', 10, 10, 10);
        self::assertSame([$pair, [$loose[0]]], GroupBatcher::batches([$pair[0], $loose[0], $pair[1]]));
    }

    public static function testAGroupKeepsThePositionOfItsFirstMember(): void
    {
        $pair = self::instances('pair', 10, 10, 10, ['quantity' => 2, 'group' => 'kit']);
        $loose = self::instances('loose', 10, 10, 10);
        self::assertSame([[$loose[0]], $pair], GroupBatcher::batches([$loose[0], $pair[0], $pair[1]]));
    }

    public static function testFlattenIsTheInverseOfBatching(): void
    {
        $items = array_merge(
            self::instances('pair', 10, 10, 10, ['quantity' => 2, 'group' => 'kit']),
            self::instances('loose', 10, 10, 10),
        );
        self::assertSame($items, GroupBatcher::flatten(GroupBatcher::batches($items)));
    }

    // ---------------------------------------------------------------- maximal spaces

    public static function testCarvingNothingLeavesTheSpaceUntouched(): void
    {
        $whole = self::space(0, 0, 0, 100, 100, 100);
        self::assertCount(1, MaximalSpaceSolver::subtractAll([$whole], []));
    }

    public static function testCarvingTheWholeSpaceLeavesNothing(): void
    {
        self::assertSame([], MaximalSpaceSolver::subtractAll(
            [self::space(0, 0, 0, 100, 100, 100)], [self::solid(0, 0, 0, 100, 100, 100)]));
    }

    public static function testNoRemainingSpaceOverlapsTheCarvedSolid(): void
    {
        $obstacle = self::solid(20, 20, 0, 30, 30, 100);
        foreach (MaximalSpaceSolver::subtractAll([self::space(0, 0, 0, 100, 100, 100)], [$obstacle]) as $remaining) {
            self::assertFalse((new AxisAlignedBox($remaining->origin, $remaining->dimensions))->intersects($obstacle));
        }
    }

    public static function testNoRemainingSpaceIsContainedInAnother(): void
    {
        // Without this filter every placement multiplies the space list and the solver
        // degenerates into an exponential scan of overlapping duplicates.
        $spaces = MaximalSpaceSolver::subtractAll(
            [self::space(0, 0, 0, 100, 100, 100)], [self::solid(20, 20, 20, 30, 30, 30)]);
        foreach ($spaces as $index => $one) {
            foreach ($spaces as $position => $other) {
                if ($position === $index) {
                    continue;
                }
                self::assertFalse(
                    (new AxisAlignedBox($other->origin, $other->dimensions))
                        ->contains(new AxisAlignedBox($one->origin, $one->dimensions)),
                );
            }
        }
    }

    public static function testTheFreeSpaceListNeverExceedsTheBudget(): void
    {
        // Adversarial size variety defeats the containment-dominance filter above --
        // each carve keeps producing slabs that merely overlap rather than nest -- so
        // the surviving list would otherwise grow roughly linearly with no bound
        //. Sizes and offsets are a fixed deterministic pattern, not
        // randomness, so a failure reproduces from the test alone.
        $spaces = [self::space(0, 0, 0, 3000, 3000, 3000)];
        for ($k = 0; $k < 120; $k++) {
            $size = 20 + ($k * 7) % 60;
            $x = ($k * 131) % 2900;
            $y = ($k * 277) % 2900;
            $z = ($k * 419) % 2900;
            $spaces = MaximalSpaceSolver::subtractAll($spaces, [self::solid($x, $y, $z, $size, $size, $size)]);
            self::assertLessThanOrEqual(MaximalSpaceSolver::MAX_MAXIMAL_SPACES, count($spaces));
        }
    }

    public static function testAnAdversarialItemMixStillPlacesCompletelyWithinTheSpaceBudget(): void
    {
        // The same growth pattern, driven through the real solver: varied item sizes
        // that keep carving non-nested residual spaces. Without a budget this reaches
        // several hundred surviving spaces for 200 items; capped, the solver still
        // places every one soundly.
        $sizes = [8, 12, 16, 20, 24, 10, 14, 18, 22, 26];
        $items = [];
        for ($k = 0; $k < 200; $k++) {
            $l = $sizes[$k % count($sizes)];
            $w = $sizes[($k * 3) % count($sizes)];
            $h = $sizes[($k * 7) % count($sizes)];
            $items = [...$items, ...self::instances("i{$k}", $l, $w, $h)];
        }
        $box = Container::create('c', Dimensions::mm(600, 600, 600));
        // The wall clock here is a hang guard, never part of what this test asserts, so
        // it is set where no plausible host reaches it: the workload measured 88s on a
        // loaded developer machine and the earlier 60s valve cut it off at 166 of 200
        // items, which reported as a packing defect when it was a stopwatch. A budget
        // that binds turns this into a test of how busy the machine was -- 's
        // class of failure -- so the deadline is asserted not to have fired below rather
        // than tuned until it usually does not.
        $timeLimitMs = 600_000;
        $config = PackingConfig::balanced($timeLimitMs);
        $deadline = Deadline::ofMilliseconds($timeLimitMs);

        $solution = (new MaximalSpaceSolver())->packOne(
            $box, 1, $items, $config, new SearchStats(), $deadline
        );

        self::assertFalse(
            $deadline->expired(),
            'the deadline fired, so this run measured the host rather than the space budget'
        );
        self::assertSame([], $solution->unpacked);
        self::assertCount(200, $solution->state->placements);
        self::assertPhysicallySound($solution->state);
    }

    public static function testEveryFreePointIsStillCoveredAfterCarving(): void
    {
        // The decisive property of a maximal-space decomposition: carving may not lose
        // room. Sampled on a lattice across the container.
        $obstacles = [self::solid(20, 20, 0, 30, 30, 100), self::solid(70, 0, 0, 30, 100, 40)];
        $spaces = MaximalSpaceSolver::subtractAll([self::space(0, 0, 0, 100, 100, 100)], $obstacles);
        for ($x = 0; $x < 100; $x += 7) {
            for ($y = 0; $y < 100; $y += 7) {
                for ($z = 0; $z < 100; $z += 13) {
                    $point = new Point($x, $y, $z);
                    $blocked = false;
                    foreach ($obstacles as $obstacle) {
                        $blocked = $blocked || $obstacle->containsPoint($point);
                    }
                    if ($blocked) {
                        continue;
                    }
                    $covered = false;
                    foreach ($spaces as $space) {
                        $covered = $covered
                            || (new AxisAlignedBox($space->origin, $space->dimensions))->containsPoint($point);
                    }
                    self::assertTrue($covered, "lost free point {$x},{$y},{$z}");
                }
            }
        }
    }

    // ------------------------------------------------------------------- solvers

    /** @return list<array{0:string,1:object}> */
    private static function solvers(): array
    {
        return [
            ['extreme_points', new ExtremePointSolver()],
            ['layer', new LayerSolver()],
            ['grid', new GridSolver()],
            ['maximal_spaces', new MaximalSpaceSolver()],
            ['exact_small', new ExactSmallSolver()],
        ];
    }

    public static function testEverySolverFillsAnExactlyDivisibleContainer(): void
    {
        $box = Container::create('c', Dimensions::mm(200, 200, 200));
        $items = self::instances('cube', 100, 100, 100, ['quantity' => 4]);
        foreach (self::solvers() as [$name, $solver]) {
            $solution = $solver->packOne($box, 1, $items, PackingConfig::balanced(), new SearchStats(), self::generous());
            self::assertCount(4, $solution->state->placements, $name);
            self::assertSame([], $solution->unpacked, $name);
            self::assertPhysicallySound($solution->state);
        }
    }

    public static function testEverySolverRespectsAnObstacle(): void
    {
        $post = new Obstacle('post', new AxisAlignedBox(new Point(0, 0, 0), Dimensions::mm(50, 50, 100)));
        $box = Container::create('c', Dimensions::mm(100, 100, 100), obstacles: [$post]);
        $items = self::instances('a', 40, 40, 40, ['quantity' => 2]);
        foreach (self::solvers() as [$name, $solver]) {
            $solution = $solver->packOne($box, 1, $items, PackingConfig::balanced(), new SearchStats(), self::generous());
            self::assertPhysicallySound($solution->state);
        }
    }

    public static function testAMultiBoxObstacleBlocksEveryOneOfItsBoxes(): void
    {
        // A wheel-arch-style union: both boxes must be avoided, not just
        // the first one an obstacle happened to be constructed with.
        $near = new AxisAlignedBox(new Point(0, 0, 0), Dimensions::mm(40, 100, 100));
        $far = new AxisAlignedBox(new Point(Length::mm(60)->ticks, 0, 0), Dimensions::mm(40, 100, 100));
        $arch = new Obstacle('arch', $near, [$far]);
        $box = Container::create('c', Dimensions::mm(100, 100, 100), obstacles: [$arch]);
        $items = self::instances('gap-filler', 20, 100, 100);
        $solution = (new ExtremePointSolver())->packOne($box, 1, $items, PackingConfig::balanced(), new SearchStats(), self::generous());
        self::assertCount(1, $solution->state->placements);
        $placed = $solution->state->placements[0]->envelopeBox();
        self::assertFalse($placed->intersects($near));
        self::assertFalse($placed->intersects($far));
    }

    public static function testEverySolverReportsWhatItCouldNotPlace(): void
    {
        $box = Container::create('c', Dimensions::mm(100, 100, 100));
        $items = self::instances('cube', 90, 90, 90, ['quantity' => 3]);
        foreach (self::solvers() as [$name, $solver]) {
            $solution = $solver->packOne($box, 1, $items, PackingConfig::balanced(), new SearchStats(), self::generous());
            $placed = array_map(static fn($p): string => $p->instance->id(), $solution->state->placements);
            $unpacked = array_map(static fn($i): string => $i->id(), $solution->unpacked);
            $accounted = array_merge($placed, $unpacked);
            sort($accounted);
            self::assertSame(['cube#1', 'cube#2', 'cube#3'], $accounted, $name);
            self::assertSame([], array_intersect($placed, $unpacked), $name);
        }
    }

    // ------------------------------------------------------------- lattice solver

    public static function testTheLatticeAppliesToOneGeometricallyFungibleProfile(): void
    {
        self::assertTrue((new GridSolver())->supports(self::instances('a', 10, 10, 10, ['quantity' => 4])));
        self::assertFalse((new GridSolver())->supports(array_merge(
            self::instances('a', 10, 10, 10), self::instances('b', 20, 20, 20))));
        self::assertFalse((new GridSolver())->supports([]));
    }

    public static function testTheLatticeAdmitsADeclaredTypeThatIsARotationOfAnother(): void
    {
        // Two declared types whose dimensions are 90-degree rotations
        // of each other, with full rotation freedom, are the same physical item for
        // lattice purposes -- a real catalog pattern (the same carton under two SKUs).
        self::assertTrue((new GridSolver())->supports(array_merge(
            self::instances('a', 6, 12, 20, ['quantity' => 11]), self::instances('b', 12, 6, 20))));
    }

    public static function testTheLatticeKeepsDifferentDeclaredNestingTypesOutOfOneColumn(): void
    {
        // Nesting permits physical overlap only within one declared item type. Equal
        // profiles previously borrowed the first type's 60mm lattice step and placed
        // the second type illegally above it in this 160mm-high container.
        $box = Container::create('c', Dimensions::mm(100, 100, 160));
        $nesting = ['nestingHeight' => Length::mm(40), 'allowedRotations' => [Rotation::LWH]];
        $items = [
            ...self::instances('a', 100, 100, 100, $nesting),
            ...self::instances('b', 100, 100, 100, $nesting),
        ];
        $solver = new GridSolver();

        self::assertFalse($solver->supports($items));
        $solution = $solver->packOne(
            $box, 1, $items, PackingConfig::fast(), new SearchStats(), self::generous(),
        );

        self::assertCount(1, $solution->state->placements);
        self::assertCount(1, $solution->unpacked);
        self::assertPhysicallySound($solution->state);
    }

    public static function testTwelveRotationEquivalentItemsSplitAcrossTwoTypesStillShareOneContainer(): void
    {
        $box = Container::create('c', Dimensions::mm(25, 36, 21));
        $a = self::instances('a', 6, 12, 20, ['quantity' => 11]);
        $b = self::instances('b', 12, 6, 20);
        $solution = (new GridSolver())->packOne($box, 1, [...$a, ...$b], PackingConfig::balanced(), new SearchStats(), self::generous());

        self::assertSame([], $solution->unpacked);
        self::assertCount(12, $solution->state->placements);
        foreach ($solution->state->placements as $placement) {
            // The invariant a mixed-declared-type lattice must never break: the
            // stored rotation, applied to that instance's own declared dimensions,
            // must reproduce the physical box actually placed -- not a rotation
            // borrowed from a different declared type sharing the same lattice.
            self::assertEquals($placement->instance->item->dimensions->rotated($placement->rotation), $placement->dimensions);
        }
    }

    public static function testTheLatticeFallsBackRatherThanPlacingMixedTypesAtTheWrongSize(): void
    {
        // packOne is not only ever reached through the orchestrator's pre-filter -- a
        // caller naming solvers explicitly, or a future extension, could hand it a
        // mixed-type list directly. Without its own guard it would place every item at
        // the first item's envelope size, which is silently wrong geometry, not merely
        // a missed placement.
        $box = Container::create('c', Dimensions::mm(100, 100, 100));
        $small = self::instances('small', 20, 20, 20, ['quantity' => 2]);
        $big = self::instances('big', 80, 80, 80);
        $solution = (new GridSolver())->packOne($box, 1, [...$small, ...$big], PackingConfig::balanced(), new SearchStats(), self::generous());

        foreach ($solution->state->placements as $placement) {
            self::assertEquals($placement->instance->item->dimensions->rotated($placement->rotation), $placement->dimensions);
        }
    }

    public static function testTheLatticeKeepsNonStackableItemsOnTheFloor(): void
    {
        // A regular stack cannot express a per-item rule, so the rule is folded into the
        // layer count before any placement is made.
        $box = Container::create('c', Dimensions::mm(100, 100, 100));
        $items = self::instances('flat', 40, 40, 40, ['quantity' => 8, 'stackable' => false]);
        $solution = (new GridSolver())->packOne($box, 1, $items, PackingConfig::fast(), new SearchStats(), self::generous());

        foreach ($solution->state->placements as $placement) {
            self::assertSame(0, $placement->envelopeOrigin->z);
        }
        self::assertPhysicallySound($solution->state);
    }

    public static function testTheLatticeHonoursAFloorOnlyItem(): void
    {
        $box = Container::create('c', Dimensions::mm(100, 100, 100));
        $items = self::instances('f', 40, 40, 40, ['quantity' => 8, 'mustBeOnFloor' => true]);
        $solution = (new GridSolver())->packOne($box, 1, $items, PackingConfig::fast(), new SearchStats(), self::generous());
        foreach ($solution->state->placements as $placement) {
            self::assertSame(0, $placement->envelopeOrigin->z);
        }
    }

    public static function testTheLatticeIsAllOrNothingForAGroup(): void
    {
        // Half a group in a container is worse than none of it.
        $box = Container::create('c', Dimensions::mm(100, 100, 100));
        $items = self::instances('kit', 90, 90, 90, ['quantity' => 2, 'group' => 'kit']);
        $solution = (new GridSolver())->packOne($box, 1, $items, PackingConfig::fast(), new SearchStats(), self::generous());

        self::assertSame([], $solution->state->placements);
        self::assertCount(2, $solution->unpacked);
    }

    public static function testTheLatticeRespectsAPayloadCeiling(): void
    {
        $box = Container::create('c', Dimensions::mm(200, 200, 200), maxPayload: '2 kg');
        $items = self::instances('w', 100, 100, 100, ['quantity' => 8, 'weight' => '1 kg']);
        $solution = (new GridSolver())->packOne($box, 1, $items, PackingConfig::fast(), new SearchStats(), self::generous());
        self::assertCount(2, $solution->state->placements);
    }

    public static function testTheMaterializedLatticeCapsAUniformColumnByCumulativeTopLoad(): void
    {
        $box = Container::create('c', Dimensions::mm(100, 100, 1000));
        $items = self::instances('rated', 100, 100, 100, [
            'quantity' => 10, 'weight' => '1 kg', 'maxTopLoad' => '2 kg',
        ]);

        $solution = (new GridSolver())->packOne(
            $box, 1, $items, PackingConfig::fast(requirePlacementCoordinates: true),
            new SearchStats(), self::generous(),
        );

        self::assertCount(3, $solution->state->placements);
        self::assertCount(7, $solution->unpacked);
        self::assertSame(
            [0, Length::mm(100)->ticks, Length::mm(200)->ticks],
            array_map(static fn(Placement $placement): int => $placement->envelopeOrigin->z, $solution->state->placements),
        );
        self::assertNull(LoadCalculator::overloaded(LoadCalculator::units($solution->state->placements)));
    }

    public static function testTheCompactLatticeUsesTheSameCumulativeTopLoadCap(): void
    {
        $box = Container::create('c', Dimensions::mm(100, 100, 1000));
        $items = self::instances('rated', 100, 100, 100, [
            'quantity' => 10, 'weight' => '1 kg', 'maxTopLoad' => '2 kg',
        ]);

        $solution = (new GridSolver())->packOne(
            $box, 1, $items, PackingConfig::fast(requirePlacementCoordinates: false),
            new SearchStats(), self::generous(),
        );

        self::assertSame([], $solution->state->placements);
        self::assertNotNull($solution->state->latticeSummary);
        self::assertSame(3, $solution->state->latticeSummary->count);
        self::assertCount(7, $solution->unpacked);
        $expanded = $solution->state->latticeSummary->expand($items);
        self::assertNull(LoadCalculator::overloaded(LoadCalculator::units($expanded)));
    }

    public static function testTheLatticeNestsIdenticalItemsReducingTheStackedHeight(): void
    {
        // A 100mm item nesting 40mm into the one below only advances the stack by
        // 60mm per layer, fitting a third layer into a 220mm container that would
        // otherwise only ever hold two at the full 100mm step.
        $box = Container::create('c', Dimensions::mm(100, 100, 220));
        $items = self::instances('crate', 100, 100, 100, ['quantity' => 10, 'nestingHeight' => Length::mm(40)]);
        $solution = (new GridSolver())->packOne($box, 1, $items, PackingConfig::fast(), new SearchStats(), self::generous());
        self::assertCount(3, $solution->state->placements);
        $zs = array_values(array_unique(array_map(static fn($p) => $p->envelopeOrigin->z, $solution->state->placements)));
        sort($zs);
        self::assertSame([0, Length::mm(60)->ticks, Length::mm(120)->ticks], $zs);
        $boundary = new AxisAlignedBox(new Point(0, 0, 0), $box->innerDimensions);
        foreach ($solution->state->placements as $p) {
            self::assertTrue($boundary->contains($p->envelopeBox()));
        }
    }

    public static function testGeneralSolverUsesExactNestedVolumeAtTheReserveBoundary(): void
    {
        $box = Container::create('c', Dimensions::mm(100, 100, 200), voidFillReserveRatio: 0.25);
        $items = self::instances('crate', 100, 100, 100, ['quantity' => 2, 'nestingHeight' => Length::mm(50)]);

        // A reserve disables the closed-form lattice, so this reaches the general
        // solver's inside-envelope point and exact append-volume admission.
        $solution = (new GridSolver())->packOne(
            $box,
            1,
            $items,
            PackingConfig::fast(),
            new SearchStats(),
            self::generous(),
        );

        self::assertCount(2, $solution->state->placements);
        self::assertSame(
            [0, Length::mm(50)->ticks],
            array_map(static fn($p) => $p->envelopeOrigin->z, $solution->state->placements),
        );
        self::assertSame(
            BigInt::divide(
                BigInt::multiply($box->innerDimensions->volumeString(), '3'),
                '4',
            ),
            $solution->state->usedVolume,
        );
    }

    public static function testNestingHeightReducesToTheOrdinaryLatticeWhenUnset(): void
    {
        $box = Container::create('c', Dimensions::mm(100, 100, 220));
        $items = self::instances('crate', 100, 100, 100, ['quantity' => 10]);
        $solution = (new GridSolver())->packOne($box, 1, $items, PackingConfig::fast(), new SearchStats(), self::generous());
        self::assertCount(2, $solution->state->placements);
    }

    public static function testTheLatticeDefersToTheGeneralSolverWhenAStackDensityLimitIsSet(): void
    {
        // Its single-layer heuristic only ever reasons about one item resting on one
        // other, never the cumulative load a tall column presses through its own
        // footprint, so it must hand off rather than silently ignore the limit.
        $box = Container::create('c', Dimensions::mm(1000, 1000, 300), maxStackDensity: '500 kg');
        $items = self::instances('cube', 1000, 1000, 100, ['quantity' => 3, 'weight' => '400 kg']);
        $solution = (new GridSolver())->packOne($box, 1, $items, PackingConfig::balanced(), new SearchStats(), self::generous());
        self::assertCount(1, $solution->state->placements);
    }

    // ------------------------------------------------ quantity compression

    public static function testTheLatticeIgnoresRequirePlacementCoordinatesByDefault(): void
    {
        $box = Container::create('c', Dimensions::mm(1000, 1000, 1000));
        $items = self::instances('cube', 100, 100, 100, ['quantity' => 50]);
        $default = (new GridSolver())->packOne($box, 1, $items, PackingConfig::fast(), new SearchStats(), self::generous());
        $explicitTrue = (new GridSolver())->packOne($box, 1, $items, PackingConfig::fast(requirePlacementCoordinates: true), new SearchStats(), self::generous());
        self::assertNull($default->state->latticeSummary);
        self::assertNull($explicitTrue->state->latticeSummary);
        self::assertCount(50, $default->state->placements);
        self::assertCount(50, $explicitTrue->state->placements);
    }

    public static function testTheLatticeCompactFastPathBuildsNoPerItemPlacement(): void
    {
        // The whole point of this path: opting out of coordinates must not construct
        // one Placement per instance.
        $box = Container::create('c', Dimensions::mm(3000, 3000, 3000));
        $items = self::instances('cube', 100, 100, 100, ['quantity' => 10_000]);
        $solution = (new GridSolver())->packOne($box, 1, $items, PackingConfig::fast(requirePlacementCoordinates: false), new SearchStats(), self::generous());
        self::assertSame([], $solution->state->placements);
        self::assertNotNull($solution->state->latticeSummary);
        self::assertSame(10_000, $solution->state->latticeSummary->count);
        self::assertSame([], $solution->unpacked);
    }

    public static function testTheLatticeCompactFastPathReconstructsIdenticalPlacements(): void
    {
        $box = Container::create('c', Dimensions::mm(370, 370, 370));
        $items = self::instances('cube', 100, 100, 100, ['quantity' => 137]);
        $compact = (new GridSolver())->packOne($box, 1, $items, PackingConfig::fast(requirePlacementCoordinates: false), new SearchStats(), self::generous());
        $expanded = (new GridSolver())->packOne($box, 1, $items, PackingConfig::fast(), new SearchStats(), self::generous());
        self::assertSame(count($expanded->state->placements), $compact->state->latticeSummary->count);
        $rebuilt = $compact->state->latticeSummary->expand($items);
        self::assertEquals($expanded->state->placements, $rebuilt);
    }

    public static function testTheLatticeCompactFastPathNeverEngagesWithNestingHeight(): void
    {
        $box = Container::create('c', Dimensions::mm(100, 100, 220));
        $items = self::instances('crate', 100, 100, 100, ['quantity' => 10, 'nestingHeight' => Length::mm(40)]);
        $solution = (new GridSolver())->packOne($box, 1, $items, PackingConfig::fast(requirePlacementCoordinates: false), new SearchStats(), self::generous());
        self::assertNull($solution->state->latticeSummary);
        self::assertCount(3, $solution->state->placements);
    }

    /** @return list<array{0:int,1:int,2:int}> */
    private static function latticeSummaryCases(): array
    {
        return [[100, 100, 1], [200, 100, 7], [300, 200, 47], [170, 130, 900], [1000, 1000, 3000]];
    }

    public static function testLatticeSummaryAggregatesMatchBruteForceExpansion(): void
    {
        // Cross-checks the closed-form LatticeSummary aggregates -- weight, used
        // volume, top height, centre of mass -- against the existing per-placement
        // functions run over a full expansion, across shapes exercising a partial top
        // layer, a partial top row, and an exact fit.
        foreach (self::latticeSummaryCases() as [$length, $width, $quantity]) {
            $box = Container::create('c', Dimensions::mm($length, $width, 5000));
            $items = self::instances('cube', 100, 100, 50, ['quantity' => $quantity, 'weight' => '2 kg']);
            $compact = (new GridSolver())->packOne($box, 1, $items, PackingConfig::fast(requirePlacementCoordinates: false), new SearchStats(), self::generous());
            $summary = $compact->state->latticeSummary;
            self::assertNotNull($summary);
            $expanded = $summary->expand($items);

            $bruteWeight = 0;
            foreach ($expanded as $p) { $bruteWeight += $p->instance->weight()->ticks; }
            self::assertSame($bruteWeight, $summary->totalWeightTicks());
            self::assertSame(\Packvium\Domain\Nesting::usedVolume($expanded), $summary->usedVolumeString());
            $bruteMaxZ = 0;
            foreach ($expanded as $p) { $bruteMaxZ = max($bruteMaxZ, $p->envelopeBox()->z2()); }
            self::assertSame($bruteMaxZ, $summary->maxZTicks());
            $inner = $box->innerDimensions;
            self::assertSame(
                \Packvium\Domain\CentreOfMass::offsetPpm($inner, $expanded),
                $summary->centreOfMassOffsetPpm($inner->length->ticks, $inner->width->ticks),
            );
        }
    }

    // --------------------------------------------------------------- exact search

    public static function testIsBetterStatePrefersMoreItemsRegardlessOfVolume(): void
    {
        $box = Container::create('c', Dimensions::mm(200, 200, 200));
        [$big] = self::instances('big', 150, 150, 150);
        [$smallA, $smallB] = self::instances('small', 20, 20, 20, ['quantity' => 2]);
        $fewerButBigger = self::extend(new ContainerState($box, 1), $big);
        $moreButSmaller = self::extend(self::extend(new ContainerState($box, 2), $smallA), $smallB);
        self::assertTrue(ExactSmallSolver::isBetterState($moreButSmaller, $fewerButBigger));
        self::assertFalse(ExactSmallSolver::isBetterState($fewerButBigger, $moreButSmaller));
    }

    public static function testIsBetterStateBreaksAnItemCountTieByPlacedVolume(): void
    {
        // The bug this closes: a branch-and-bound search that only compared item
        // counts could let more search time replace a well-arranged tie with a
        // worse-arranged one, silently discarding the two objective keys (unused
        // volume, stack height) it never looked at.
        $box = Container::create('c', Dimensions::mm(200, 200, 200));
        [$small] = self::instances('small', 20, 20, 20);
        [$big] = self::instances('big', 80, 80, 80);
        $smallerState = self::extend(new ContainerState($box, 1), $small);
        $biggerState = self::extend(new ContainerState($box, 2), $big);
        self::assertTrue(ExactSmallSolver::isBetterState($biggerState, $smallerState));
        self::assertFalse(ExactSmallSolver::isBetterState($smallerState, $biggerState));
    }

    public static function testIsBetterStateDoesNotReplaceAnEqualState(): void
    {
        $box = Container::create('c', Dimensions::mm(200, 200, 200));
        [$item] = self::instances('a', 50, 50, 50);
        $state = self::extend(new ContainerState($box, 1), $item);
        self::assertFalse(ExactSmallSolver::isBetterState($state, $state));
    }

    public static function testExactEqualCountBoundKeepsALargerRemainingItemReachable(): void
    {
        $box = Container::create('c', Dimensions::mm(10, 10, 10));
        [$small] = self::instances('small', 4, 10, 10);
        [$large] = self::instances('large', 7, 10, 10);

        $solution = (new ExactSmallSolver())->packOne(
            $box, 1, [$small, $large], PackingConfig::exactSmall(), new SearchStats(), self::generous(),
        );

        self::assertSame(['large'], array_map(
            static fn(Placement $placement): string => $placement->instance->item->id,
            $solution->state->placements,
        ));
        self::assertSame(['small'], array_map(
            static fn(ItemInstance $item): string => $item->item->id,
            $solution->unpacked,
        ));
    }

    public static function testIsBetterStateComparesPlacedPhysicalVolumeExactlyBeyondFloatPrecision(): void
    {
        $twoToThe53 = 9_007_199_254_740_992;
        $smallDimensions = new Dimensions(new Length($twoToThe53), new Length(1), new Length(1));
        $largeDimensions = new Dimensions(new Length($twoToThe53 + 1), new Length(1), new Length(1));
        $box = Container::create('c', new Dimensions(new Length($twoToThe53 + 2), new Length(2), new Length(2)));
        $origin = new Point(0, 0, 0);
        [$smallItem] = Item::create('small', $smallDimensions)->instances();
        [$largeItem] = Item::create('large', $largeDimensions)->instances();
        $smallState = new ContainerState($box, 1);
        $largeState = new ContainerState($box, 2);
        $smallState->addDirect(new Placement($smallItem, $origin, Rotation::LWH, $smallDimensions, $origin, $smallDimensions));
        $largeState->addDirect(new Placement($largeItem, $origin, Rotation::LWH, $largeDimensions, $origin, $largeDimensions));

        self::assertSame($smallDimensions->volumeScore(), $largeDimensions->volumeScore());
        self::assertTrue(BigInt::compare($largeDimensions->volumeString(), $smallDimensions->volumeString()) > 0);
        self::assertTrue(ExactSmallSolver::isBetterState($largeState, $smallState));
        self::assertFalse(ExactSmallSolver::isBetterState($smallState, $largeState));
    }

    public static function testInactiveSupportDiagnosisDoesNotReplayAGridContainer(): void
    {
        $box = Container::create('c', Dimensions::mm(100, 100, 100), quantity: 1);
        $items = self::instances('cube', 100, 100, 100, ['quantity' => 2]);
        $config = PackingConfig::fast();
        $solution = (new GridSolver())->packOne($box, 1, $items, $config, new SearchStats(), self::generous());
        self::assertCount(1, $solution->state->placements);
        self::assertCount(1, $solution->unpacked);

        // A deliberately non-Placement sentinel makes any attempted state replay fail.
        // The inactive SupportConstraint must be classified before the packed scene is
        // touched, so this asserts operation behaviour without a wall-clock threshold.
        $packed = new PackedContainer($box, 1, [new \stdClass()]);
        $diagnose = new ReflectionMethod(SolverOrchestrator::class, 'supportIsTheBlocker');
        $diagnose->setAccessible(true);
        self::assertFalse($diagnose->invoke(
            new SolverOrchestrator(), $solution->unpacked[0], [$packed], $config,
        ));
    }

    public static function testTheExactSolverRefusesAnOrderBeyondItsLimit(): void
    {
        $box = Container::create('c', Dimensions::mm(1_000, 1_000, 1_000));
        $items = self::instances('a', 10, 10, 10, ['quantity' => 9]);
        self::assertThrows(InvalidArgumentException::class, static fn() => (new ExactSmallSolver())
            ->packOne($box, 1, $items, new PackingConfig(exactItemLimit: 8), new SearchStats(), self::generous()));
    }

    public static function testACompletedDiscreteExactSearchDoesNotClaimGlobalExhaustiveness(): void
    {
        // Candidate enumeration is not a certificate for the public objective vector.
        $box = Container::create('c', Dimensions::mm(200, 200, 200));
        $items = self::instances('cube', 100, 100, 100, ['quantity' => 4]);
        $solution = (new ExactSmallSolver())
            ->packOne($box, 1, $items, PackingConfig::exactSmall(), new SearchStats(), self::generous());
        self::assertFalse($solution->exhaustive);
    }

    public static function testAGreedyGroupBatchForfeitsTheExhaustivenessClaim(): void
    {
        // A multi-item batch is placed greedily rather than enumerated, so the tree is
        // no longer a complete search of the placement space.
        $box = Container::create('c', Dimensions::mm(200, 200, 200));
        $items = self::instances('kit', 100, 100, 100, ['quantity' => 2, 'group' => 'kit']);
        $solution = (new ExactSmallSolver())
            ->packOne($box, 1, $items, PackingConfig::exactSmall(), new SearchStats(), self::generous());
        self::assertFalse($solution->exhaustive);
    }
}
