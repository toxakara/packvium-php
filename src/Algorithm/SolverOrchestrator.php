<?php
declare(strict_types=1);
namespace Packvium\Algorithm;

use Packvium\Config\{PackingConfig,SolverProfile};
use Packvium\Constraint\{AxleLoad,ConstraintSet,ProvenRejection,SupportConstraint};
use Packvium\Domain\{Container,Dimensions,FlatBag,ItemInstance,MadeToMeasure,Nesting,PackedContainer,Rotation,UnpackedItem};
use Packvium\Extension\{CandidateScorer,ContainerSelector,DefaultCandidateScorer,DefaultContainerSelector,ItemOrderStrategy};
use Packvium\Result\StartRecord;
use Packvium\Support\{BigInt,ExactGeometry,ExactInteger,StableSorter};
use Packvium\Objective\{LandedCostSolutionScorer,ObjectiveRegistry};
use Packvium\Algorithm\Internal\{ConcurrentStarts,ContainerPlanBeam,MadeToMeasureTrial,PalletTrial,QualityStages};
final class SolverOrchestrator
{
    /**
     * Beam width and candidates per item for the refinement of a winning late-type order
     * (`...:late_<type>:beam`), as Python's `LATE_TYPE_REFINEMENT_WIDTH`.
     */
    private const LATE_TYPE_REFINEMENT_WIDTH=4;
    public function __construct(private array $customConstraints=[],private array $customOrders=[],private array $customSolvers=[],private ?CandidateScorer $candidateScorer=null,private ?ContainerSelector $containerSelector=null){}

    /** @return list<RawSolution> */
    public function solve(array $items,array $containers,PackingConfig $config,Deadline $deadline):array
    {
        return $this->solvePortfolio($items,$containers,$config,$deadline)->solutions;
    }

    /**
     * Search for placements of `$items`.
     *
     * `$preloaded` are the containers a request's fixed placements name, each holding only its
     * fixed items, in the order they open (`FixedLoad`). Every start fills them first and keeps
     * them, whatever else it does.
     *
     * @param list<PackedContainer> $preloaded
     */
    public function solvePortfolio(array $items,array $containers,PackingConfig $config,Deadline $deadline,array $preloaded=[]):PortfolioRun
    {
        $starts=$this->starts($items,$config,$containers);
        $effortBudget=$config->effortBudget;
        if($effortBudget!==null&&$effortBudget->maxRestarts!==null)$starts=array_slice($starts,0,$effortBudget->maxRestarts);
        $run=$this->eligibleForConcurrentExecution($deadline,$config)&&count($starts)>1
            ? ConcurrentStarts::solveConcurrent($this->bridge(),$this->candidateScorer,$items,$containers,$config,$deadline,$starts,$effortBudget,$preloaded)
            : $this->solveSequential($items,$containers,$config,$deadline,$starts,$effortBudget,$preloaded);
        return self::withoutABlockStartThatOnlyTies($run,$starts,$config);
    }

    /**
     * Drop the added block start's answer unless it strictly beats the profile's own.
     *
     * The start is there to improve an answer, not to replace an equal one: on a tie
     * the ranking falls to the solver name, which would hand the result to a lattice
     * that knows nothing of the orderings the profile's own starts honour.
     *
     * @param list<array{0:SingleContainerSolver,1:string,2:list<ItemInstance>}> $starts
     */
    private static function withoutABlockStartThatOnlyTies(PortfolioRun $run,array $starts,PackingConfig $config):PortfolioRun
    {
        if($starts===[])return $run;
        [$solver,$orderName]=$starts[array_key_last($starts)];
        if(!$solver instanceof HomogeneousBlockSolver||$solver->nodeLimit===null)return $run;
        $scorer=ObjectiveRegistry::resolve($config->objective,$config);
        $added=$solver->name().':'.$orderName;
        $bestOwn=null;
        foreach($run->solutions as $solution){
            if($solution->solverName===$added)continue;
            $score=$scorer->score($solution);
            if($bestOwn===null||$score->compare($bestOwn)<0)$bestOwn=$score;
        }
        if($bestOwn===null)return $run;
        $kept=array_values(array_filter(
            $run->solutions,
            static fn(RawSolution $solution):bool=>$solution->solverName!==$added||$scorer->score($solution)->compare($bestOwn)<0,
        ));
        return new PortfolioRun($kept,$run->starts);
    }

    /**
     * Gate for the fork-based concurrent path.
     *
     * Concurrency is opt-in (`parallelStarts > 1`, default `1`). Unlike the Python
     * port's `ProcessPoolExecutor`, `pcntl_fork()` duplicates the whole process --
     * every custom constraint/order/solver/scorer/selector the caller supplied is
     * already present in the forked child's memory, with no pickling boundary to
     * cross, so this gate does not need Python's extra restriction against custom
     * extensions. What it still must check: a real, machine-wide clock (an injected
     * test/simulation clock closure diverges independently between parent and
     * child the instant they fork, see `Deadline::usesRealClock`), and that
     * `pcntl_fork`/`pcntl_waitpid` actually exist -- routinely unavailable under
     * php-fpm, mod_php, and on Windows even when the extension is compiled in.
     * Ineligible calls fall back to the exact original sequential path -- silently,
     * since correctness never depends on this gate, only whether a call gets the
     * speed-up.
     */
    private function eligibleForConcurrentExecution(Deadline $deadline,PackingConfig $config):bool
    {
        return $config->parallelStarts>1
            && $config->containerPlanBeamWidth===1
            && $deadline->usesRealClock()
            && function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('stream_socket_pair');
    }

    private function solveSequential(array $items,array $containers,PackingConfig $config,Deadline $deadline,array $starts,?EffortBudget $effortBudget,array $preloaded=[]):PortfolioRun
    {
        $results=[];
        $records=array_map(
            static fn(array $start):StartRecord=>new StartRecord($start[0]->name().':'.$start[1],false,false,false),
            $starts,
        );
        $completed=[];
        $weights=self::sliceWeights($starts);
        foreach($starts as $position=>[$solver,$orderName,$order]){
            if($deadline->expired())break;
            if(str_starts_with($orderName,'neighborhood:')||str_ends_with($orderName,':beam')){
                $staged=QualityStages::stagedStart($orderName,$results,$completed,$config);
                if($staged===null)continue;
                [$solver,$orderName,$order]=$staged;
            }
            $stats=new SearchStats();
            $budget=self::startBudget($deadline,$effortBudget,$stats,\array_sum(\array_slice($weights,$position)),$weights[$position]);
            $startConfig=self::startConfig($orderName,$config);
            [$packed,$unpacked,$exhaustive,$reached,$dominantLattice]=$this->acrossContainers($solver,$order,$containers,$startConfig,$stats,$budget,$preloaded);
            [$wallReached,$effortReached]=self::limitsReached($reached,$budget->effortStopped(),$budget->remainingNs());
            $unpacked=self::effortLimited($unpacked,$effortReached);
            $id=$solver->name().':'.$orderName;
            $results[]=new RawSolution($id,$packed,$unpacked,$stats,$wallReached,$effortReached,$exhaustive);
            $completed[$id]=[$solver,$orderName,$order];
            $records[$position]=new StartRecord($id,true,!$reached,$reached);
            // A single item type's densest possible packing is the regular lattice grid
            // just tried: no *default-scored* solver in this portfolio can place it in
            // fewer containers or less unused volume/height once it has placed
            // everything, so remaining starts -- typically a slow per-candidate search
            // that treats every instance separately -- can only spend what is left of
            // the deadline on an answer that is provably no better. A custom
            // CandidateScorer is excluded from this: it is an explicit request for a
            // specific placement behaviour the numeric objective does not capture, and
            // grid's arithmetic cannot honour it at all, so skipping the scorer's own
            // solver here would silently ignore what the caller asked for (the same
            // "custom scorer always wins" rule docs/OBJECTIVE.md states for the
            // objective itself).
            //
            // That claim only holds when the lattice arithmetic was actually used:
            // `GridSolver::packOne` silently delegates to `ExtremePointSolver` per
            // container when the lattice cannot express that container's rules (or,
            // for a mixed-type request, when a caller names "grid" explicitly via
            // `config->solvers` and bypasses the orchestrator's own homogeneity check).
            // A delegated result is an ordinary beam-search answer, not a proven
            // optimum -- short-circuiting on it discarded a genuinely better start
            // whenever the delegate happened to finish (which, being time-bound, was
            // itself budget-dependent), producing a *worse* chosen packing from a
            // *larger* time budget. `$dominantLattice` is true only when
            // every container this start packed actually went through the lattice.
            if($dominantLattice&&$unpacked===[]&&$this->candidateScorer===null)break;
        }
        return $this->finishPortfolio($items,$containers,$deadline,$results,$records,$preloaded);
    }

    /**
     * The deadline one start runs under.
     *
     * Without an effort budget each start gets an even share of what is left, so a slow start
     * cannot starve the rest. Under one the clock is only the request's fuse: every start gets
     * all that is left, and the clock stops the search only as a whole, so the portfolio-wide
     * deadline and `time_limit_reached` say it did. A slice would let a start the clock cut
     * lose quietly, making a budgeted answer depend on the machine's speed.
     */
    private static function startBudget(Deadline $deadline,?EffortBudget $effortBudget,SearchStats $stats,int $startsLeft,int $weight=1):Deadline
    {
        if($effortBudget===null)return $deadline->share($weight,$startsLeft);
        return $deadline->withEffort($effortBudget,$stats);
    }

    /**
     * Which limit cut a start, as `[wall clock, effort budget]`: at most one, and only for a
     * start that was cut. The effort budget is named only when a poll found it spent and the
     * search stopped there, not when a finished start used exactly its budget.
     *
     * @return array{bool,bool}
     */
    private static function limitsReached(bool $reached,bool $effortStopped,int $remainingNs):array
    {
        $effortReached=$reached&&$effortStopped;
        return [$reached&&!$effortReached&&$remainingNs<=0,$effortReached];
    }

    /**
     * @param list<UnpackedItem> $unpacked
     * @return list<UnpackedItem>
     */
    private static function effortLimited(array $unpacked,bool $effortReached):array
    {
        if(!$effortReached)return $unpacked;
        return array_map(
            static fn(UnpackedItem $item):UnpackedItem=>$item->reason==='time_limit'
                ? new UnpackedItem($item->instance,'effort_limit',$item->details)
                : $item,
            $unpacked,
        );
    }

    private function finishPortfolio(array $items,array $containers,Deadline $deadline,array $results,array $records,array $preloaded=[]):PortfolioRun
    {
        $globalDeadline=$deadline->expired();
        $records=array_map(
            static fn(StartRecord $record):StartRecord=>$record->withGlobalDeadline($globalDeadline),
            $records,
        );
        if($results===[]){
            $fallbackId='portfolio:fallback';
            $results[] = new RawSolution($fallbackId,$preloaded,array_map(
                fn($i)=>new UnpackedItem($i,...$this->unpackedReason($i,$containers,true)),
                $items,
            ),new SearchStats(),true);
            $records[]=new StartRecord($fallbackId,true,true,false,false,$globalDeadline);
        }
        return new PortfolioRun($results,$records);
    }

    /**
     * Whether one box of these dimensions fits this container empty: the frame, and for a flat
     * bag the girth rule too, so a bag too thin for an item proves it unplaceable.
     */
    private static function fitsAlone(Container $container,Dimensions $dimensions):bool
    {
        return $container->isFlatBag()?FlatBag::fitsAlone($container->innerDimensions,$dimensions,$container->sideGussetTicks()):$dimensions->fitsInside($container->innerDimensions);
    }

    /** @param list<Container> $containers */
    /** @return array{0:string,1:list<string>} */
    private function unpackedReason(ItemInstance $item,array $containers,bool $reached):array
    {
        $dimension=false;$weight=false;
        foreach($containers as $container){
            foreach($item->dimensions()->uniqueRotations($item->item->allowedRotations) as [,$dimensions])
                if(self::fitsAlone($container,$dimensions)){$dimension=true;break 2;}
        }
        // Same geometric check with every physical orientation allowed, not only the
        // item's own restricted set -- distinguishes "genuinely too big in any
        // rotation" from "this exact rotation restriction, and only it, rules every
        // container out". Both are pure geometry, so both are provable
        // without a complete search.
        $fitsAnyRotation=false;
        foreach($containers as $container){
            foreach($item->dimensions()->uniqueRotations(Rotation::all()) as [,$dimensions])
                if(self::fitsAlone($container,$dimensions)){$fitsAnyRotation=true;break 2;}
        }
        foreach($containers as $container)
            if($container->maxPayload===null||$item->weight()->ticks<=$container->maxPayload->ticks){$weight=true;break;}
        $eligible=$item->item->eligibleContainerTags===[];
        if(!$eligible)
            foreach($containers as $container)
                if(array_intersect($item->item->eligibleContainerTags,$container->tags)!==[]){$eligible=true;break;}
        // A registered constraint that can prove this item fits nowhere gets to say so,
        // and to name the rule responsible. Ranked with the other structural proofs and
        // after them: a request that is geometrically impossible is impossible whatever
        // a policy says, and reporting the policy first would send a caller to fix the
        // wrong thing.
        $proven=null;
        foreach($this->customConstraints as $constraint){
            if(!$constraint instanceof ProvenRejection)continue;
            $proven=$constraint->provesUnplaceable($item,$containers);
            if($proven!==null)break;
        }
        // Structural bounds remain proofs even if the search deadline was reached.
        if(!$fitsAnyRotation)return ['no_compatible_container_dimensions',[]];
        if(!$dimension)return ['rotation_restricted',[]];
        if(!$weight)return ['payload_exceeded',[]];
        if(!$eligible)return ['no_eligible_container',[]];
        if($proven!==null)return [$proven[0],[$proven[1]]];
        if($reached)return ['time_limit',[]];
        if($item->item->group!==null)return ['group_cannot_fit_together',[]];
        return ['no_feasible_placement',[]];
    }

    /**
     * The container limit that left `$item` out, for the `no_feasible_placement`
     * fallback, or null when no limit explains it.
     *
     * Only containers that admit the item on every structural count -- a fitting
     * rotation, its weight, its eligibility tags -- are asked. When an axle refuses the
     * item alone in every one of them, the axle is the reason. Otherwise, when every one
     * that would take it alone is out of stock, or `maxContainers` is reached, the reason
     * is the inventory: `max_items`, cumulative `max_payload`, `tag_limits`, the
     * stop-accessibility rule and an axle's share of a full load can only refuse an item
     * a fresh container would take, so they surface here too. `$opened` is
     * `openedByType()` of the final layout. O(C*R).
     *
     * @param list<Container> $containers @param array<string,int> $opened
     */
    private static function limitReason(ItemInstance $item,array $containers,array $opened,PackingConfig $config):?string
    {
        $envelopes=[];
        foreach($item->dimensions()->uniqueRotations($item->item->allowedRotations) as [,$dimensions])$envelopes[]=$dimensions->expand($config->clearance);
        $weight=$item->weight()->ticks;$eligibleTags=$item->item->eligibleContainerTags;
        $admitting=[];$compatible=false;
        foreach($containers as $container){
            $fitting=array_values(array_filter($envelopes,static fn(Dimensions $envelope):bool=>self::fitsAlone($container,$envelope)));
            if($fitting===[])continue;
            if($container->maxPayload!==null&&$weight>$container->maxPayload->ticks)continue;
            if($eligibleTags!==[]&&array_intersect($eligibleTags,$container->tags)===[])continue;
            $compatible=true;
            if(self::axlesAdmitAlone($container,$fitting,$weight))$admitting[]=$container;
        }
        if(!$compatible)return null;
        if($admitting===[])return 'axle_overloaded';
        if($config->maxContainers!==null&&array_sum($opened)>=$config->maxContainers)return 'container_inventory_exhausted';
        foreach($admitting as $container)
            if($container->quantity===null||($opened[$container->id]??0)<$container->quantity)return null;
        return 'container_inventory_exhausted';
    }

    /**
     * How many containers of each template a layout opened. O(P), once per layout.
     *
     * @param list<PackedContainer> $packed @return array<string,int>
     */
    private static function openedByType(array $packed):array
    {
        $opened=[];
        foreach($packed as $packedContainer)$opened[$packedContainer->container->id]=($opened[$packedContainer->container->id]??0)+1;
        return $opened;
    }

    /** @param list<Dimensions> $envelopes envelopes that already fit `$container` */
    private static function axlesAdmitAlone(Container $container,array $envelopes,int $weight):bool
    {
        if($container->axles===null)return true;
        foreach($envelopes as $envelope)
            if(AxleLoad::admitsAlone($container->axles,$container->tareWeight->ticks,$container->innerDimensions->length->ticks,$weight,$envelope->length->ticks))return true;
        return false;
    }

    /**
     * Post-hoc diagnosis for the `no_feasible_placement` fallback: true
     * when `$item` could be placed in at least one already-packed container's
     * *final* layout once `SupportConstraint` alone is set aside, but cannot with it
     * in place.
     *
     * Deliberately not folded into `unpackedReason`'s structural checks: those are
     * proofs independent of search completeness (pure geometry across every
     * container), while this replays the actual layout the search produced and asks
     * a real question of it via the same `CandidateFinder::find` every solver uses —
     * an *observed* fact about that one layout, not a claim that no layout anywhere
     * would have worked. A different start order could, in principle, have produced
     * a different final layout where this doesn't hold; see
     * `ReasonProof::forReason`'s `observed` level.
     *
     * @param list<PackedContainer> $packed
     */
    private function supportIsTheBlocker(ItemInstance $item,array $packed,PackingConfig $config):bool
    {
        $constraints=ConstraintSet::defaults($config->minimumSupportRatio,$this->customConstraints,$config->accessDirections);
        $hasSupport=false;
        foreach($constraints as $constraint)
            if($constraint instanceof SupportConstraint&&$constraint->canReject($item)){$hasSupport=true;break;}
        if(!$hasSupport)return false;
        $withoutSupport=array_values(array_filter($constraints,static fn($c)=>!($c instanceof SupportConstraint)));
        $scorer=$this->candidateScorer??new DefaultCandidateScorer();
        // A small, fixed local budget -- independent of the caller's own deadline,
        // which may already be exhausted -- so this diagnostic can never itself hang
        // the solve; it is a best-effort explanation, not a required step.
        $budget=Deadline::ofMilliseconds(2_000);
        foreach($packed as $packedContainer){
            if($budget->expired())return false;
            $state=new ContainerState($packedContainer->container,$packedContainer->sequence);
            foreach($packedContainer->placements as $placement)$state->add($placement);
            if(CandidateFinder::find($state,$item,$config,$withoutSupport,new SearchStats(),$budget,$scorer,1)===[])continue;
            if(CandidateFinder::find($state,$item,$config,$constraints,new SearchStats(),$budget,$scorer,1)===[])return true;
        }
        return false;
    }

    /**
     * The block start `fast` and `balanced` add to their own, or `null`.
     *
     * Single-type blocks fill a carton load in milliseconds where a per-item search
     * spends its whole budget, so the low-latency profiles run them as one more start
     * and let the score decide. Only where the lattice can express the whole request:
     * a start that fell back to the per-item solver would repeat one already in the
     * portfolio. One item type is `GridSolver`'s case, and it is exact there.
     *
     * @param list<ItemInstance> $items
     * @param list<Container> $containers
     */
    private function blockStart(array $items,PackingConfig $config,array $containers):?HomogeneousBlockSolver
    {
        $fast=$config->profile===SolverProfile::Fast;
        if((!$fast&&$config->profile!==SolverProfile::Balanced)||$config->solvers!==[])return null;
        if($this->customSolvers!==[]||$this->customConstraints!==[])return null;
        $types=[];foreach($items as $instance)$types[$instance->item->id]=true;
        if(count($types)<2)return null;
        // A lattice takes whichever type fills best; it cannot honour a caller's say over
        // which item goes first, so it stays out when the items do not all lead alike.
        $lead=ItemOrdering::lead($items[0],$config);
        foreach($items as $instance)if(ItemOrdering::lead($instance,$config)!=$lead)return null;
        // One enumeration, the one the objective ranks by: `fast` is latency-bound.
        $modes=!$fast?HomogeneousBlockSolver::MODES:($config->objective==='maximum_volume'?['volume']:['count']);
        $solver=new HomogeneousBlockSolver([],null,$modes,HomogeneousBlockSolver::START_NODE_LIMIT);
        if($containers===[])return null;
        foreach($containers as $container)if(!$solver->supports($container,$items,$config))return null;
        return $solver;
    }

    /**
     * @param list<Container> $containers
     * @return list<array{0:SingleContainerSolver,1:string,2:list<ItemInstance>}>
     */
    private function starts(array $items,PackingConfig $config,array $containers=[]):array
    {
        $orders=$this->orders($items,$config);
        $starts=[];
        foreach($this->solvers($items,$config) as $solver){
            // A lattice ignores item order, so repeating it per ordering only burns budget.
            $chosen=(method_exists($solver,'orderInsensitive')&&$solver->orderInsensitive())?array_slice($orders,0,1):$orders;
            foreach($chosen as [$name,$order])$starts[]=[$solver,$name,$order];
        }
        // Last, so a restart budget drops it first and the profile's own starts keep theirs.
        if($orders!==[]&&($blocks=$this->blockStart($items,$config,$containers))!==null)$starts[]=[$blocks,...$orders[0]];
        if($config->containerPlanBeamWidth<=1||$config->profile!==SolverProfile::Quality)return $starts;
        $greedy=array_map(static fn(array $start):array=>$start[0]->name()==='homogeneous_blocks'?$start:[$start[0],$start[1].':greedy',$start[2]],$starts);
        $extreme=null;foreach($starts as $start)if($start[0]->name()==='extreme_points'){$extreme=$start;break;}
        if($extreme===null)return $greedy;
        // The same stages as Python -- every greedy order, then each item type moved
        // last in the best greedy order, then one wide refinement of the best order found.
        // The placeholder orders are replaced when the stage runs (`stagedStart`).
        $types=array_values(array_unique(array_map(static fn(ItemInstance $instance):string=>$instance->item->id,$items)));
        usort($types,'strcmp');
        $neighborhoods=array_map(static fn(string $type):array=>[$extreme[0],'neighborhood:'.$type,$extreme[2]],$types);
        return array_merge($greedy,$neighborhoods,[[$extreme[0],'best:beam',$extreme[2]]]);
    }

    /**
     * Each start's weight in the time slicing, as Python's `_slice_weights`: the late-type
     * neighbourhoods are greedy re-runs of one order, so together they weigh one start; with T
     * of them each weighs 1 and every other start T. Without them every start weighs 1.
     *
     * @param list<array{0:SingleContainerSolver,1:string,2:list<ItemInstance>}> $starts
     * @return list<int>
     */
    private static function sliceWeights(array $starts):array
    {
        $neighbourhoods=0;
        foreach($starts as [,$name])if(str_starts_with($name,'neighborhood:'))$neighbourhoods++;
        if($neighbourhoods===0)return \array_fill(0,\count($starts),1);
        return \array_map(static fn(array $start):int=>str_starts_with($start[1],'neighborhood:')?1:$neighbourhoods,$starts);
    }

    /**
     * Whether a start keeps one candidate per item: the greedy orders and their late-type
     * neighbourhoods. A `:beam` refinement of a winning neighbourhood still carries `:late_`
     * in its name, yet it is the one start meant to search wide.
     */
    private static function runsGreedy(string $orderName):bool
    {
        if(str_ends_with($orderName,':beam'))return false;
        return str_ends_with($orderName,':greedy')||str_contains($orderName,':late_');
    }

    /** The configuration one portfolio start searches with, as Python's `_start_config`. */
    private static function startConfig(string $orderName,PackingConfig $config):PackingConfig
    {
        if(self::runsGreedy($orderName))return QualityStages::searchWidth($config,1,1,1);
        if(str_ends_with($orderName,':beam')&&str_contains($orderName,':late_')){
            return QualityStages::searchWidth(
                $config,
                min($config->maxCandidatesPerItem,self::LATE_TYPE_REFINEMENT_WIDTH),
                min($config->containerPlanBeamWidth,self::LATE_TYPE_REFINEMENT_WIDTH),
                $config->containerPlanNodeLimit,
            );
        }
        return $config;
    }

    /** The same configuration with placement coordinates required: no compact lattice form (D12). */
    private static function withCoordinates(PackingConfig $config):PackingConfig
    {
        return new PackingConfig(
            profile:$config->profile,timeLimitMs:$config->timeLimitMs,topK:$config->topK,seed:$config->seed,maxContainers:$config->maxContainers,
            clearance:$config->clearance,minimumSupportRatio:$config->minimumSupportRatio,exactItemLimit:$config->exactItemLimit,multiStartOrders:$config->multiStartOrders,
            validateResult:$config->validateResult,maxCandidatesPerItem:$config->maxCandidatesPerItem,maxCandidatePoints:$config->maxCandidatePoints,solvers:$config->solvers,objective:$config->objective,
            effortBudget:$config->effortBudget,dimensionalWeightDivisor:$config->dimensionalWeightDivisor,dimensionalWeightLengthUnit:$config->dimensionalWeightLengthUnit,
            dimensionalWeightWeightUnit:$config->dimensionalWeightWeightUnit,requirePlacementCoordinates:true,parallelStarts:$config->parallelStarts,
            containerPlanBeamWidth:$config->containerPlanBeamWidth,containerPlanNodeLimit:$config->containerPlanNodeLimit,accessDirections:$config->accessDirections,
        );
    }

    /** @var array<string,callable> */
    private const SOLVER_FACTORIES=[
        'grid'=>null, // constructed without constraints/scorer; handled explicitly below
        'extreme_points'=>ExtremePointSolver::class,
        'homogeneous_blocks'=>HomogeneousBlockSolver::class,
        'layer'=>LayerSolver::class,
        'maximal_spaces'=>MaximalSpaceSolver::class,
        'exact_small'=>ExactSmallSolver::class,
    ];

    private function solvers(array $items,PackingConfig $config):array
    {
        $sc=$this->candidateScorer??new DefaultCandidateScorer();
        if($config->solvers!==[]){
            $unknown=array_values(array_diff($config->solvers,array_keys(self::SOLVER_FACTORIES)));
            if($unknown!==[])
                throw new UnknownSolverException('unknown solver name(s) '.implode(', ',$unknown).'; expected one of '.implode(', ',array_keys(self::SOLVER_FACTORIES)));
            $base=array_map(
                function(string $name) use ($sc):object{
                    if($name==='grid')return new GridSolver();
                    $class=self::SOLVER_FACTORIES[$name];
                    return new $class($this->customConstraints,$sc);
                },
                $config->solvers,
            );
            return [...$this->customSolvers,...$base];
        }
        $grid=new GridSolver();
        if($grid->supports($items)&&$this->customConstraints===[])
            $base=$config->profile===SolverProfile::Fast?[$grid]:[$grid,new ExtremePointSolver([],$sc)];
        elseif($config->profile===SolverProfile::Fast)
            $base=[new LayerSolver($this->customConstraints,$sc)];
        elseif($config->profile===SolverProfile::ExactSmall&&count($items)<=$config->exactItemLimit)
            $base=[new ExactSmallSolver($this->customConstraints,$sc),new ExtremePointSolver($this->customConstraints,$sc)];
        elseif($config->profile===SolverProfile::Quality){
            $base=[new HomogeneousBlockSolver($this->customConstraints,$sc),new ExtremePointSolver($this->customConstraints,$sc),new MaximalSpaceSolver($this->customConstraints,$sc),new LayerSolver($this->customConstraints,$sc)];
            if(count($items)<=$config->exactItemLimit)array_unshift($base,new ExactSmallSolver($this->customConstraints,$sc));
        }else $base=[new ExtremePointSolver($this->customConstraints,$sc),new LayerSolver($this->customConstraints,$sc)];
        return [...$this->customSolvers,...$base];
    }

    private function orders(array $items,PackingConfig $config):array
    {
        // Every strategy is keyed behind `ItemOrdering::lead`, which carries priority
        // and, under `maximum_value`, the item's own value.
        $smallFirst=$config->profile!==SolverProfile::Quality?[]:[
            ['small_edge',static fn(ItemInstance $i):array=>[...ItemOrdering::lead($i,$config),$i->dimensions()->maxEdge(),...$i->dimensions()->volumeKey(),$i->id()]],
            ['small_volume',static fn(ItemInstance $i):array=>[...ItemOrdering::lead($i,$config),...$i->dimensions()->volumeKey(),$i->dimensions()->maxEdge(),$i->id()]],
        ];
        $largeFirst=[
            ['volume',static fn(ItemInstance $i):array=>[...ItemOrdering::lead($i,$config),...$i->dimensions()->descendingVolumeKey(),-$i->dimensions()->maxEdge(),-$i->weight()->ticks,$i->id()]],
            ['base_area',static fn(ItemInstance $i):array=>[...ItemOrdering::lead($i,$config),...ExactInteger::descendingKey(ExactGeometry::baseArea($i->dimensions())),...$i->dimensions()->descendingVolumeKey(),$i->id()]],
            ['weight',static fn(ItemInstance $i):array=>[...ItemOrdering::lead($i,$config),-$i->weight()->ticks,...$i->dimensions()->descendingVolumeKey(),$i->id()]],
            ['constrained',static fn(ItemInstance $i):array=>[...ItemOrdering::lead($i,$config),count($i->item->allowedRotations),-$i->item->minimumSupportRatio,...$i->dimensions()->descendingVolumeKey(),$i->id()]],
        ];
        // Small items first places the most pieces; large items first fills the most space.
        // The objective decides which family gets the early, fully funded starts.
        $strategies=$config->objective==='maximum_volume'?[...$largeFirst,...$smallFirst]:[...$smallFirst,...$largeFirst];
        $limit=$config->profile===SolverProfile::Fast?1:$config->multiStartOrders;
        $out=[];$seen=[];
        foreach($strategies as [$name,$key]){
            $order=StableSorter::sortBy($items,$key);
            $sig=implode('|',array_map(static fn($i)=>$i->id(),$order));
            if(!isset($seen[$sig])){$out[]=[$name,$order];$seen[$sig]=true;}
            if(count($out)>=$limit)return $out;
        }
        foreach($this->customOrders as $strategy){
            $order=$strategy->order($items,$config->seed);
            $sig=implode('|',array_map(static fn($i)=>$i->id(),$order));
            if(!isset($seen[$sig])){$out[]=[$strategy->name(),$order];$seen[$sig]=true;}
            if(count($out)>=$limit)return $out;
        }
        $rng=new DeterministicRandom($config->seed);$attempts=0;
        while(count($out)<$limit&&$attempts<max(32,$limit*16)){
            $attempts++;
            $order=$rng->shuffled($items);
            $sig=implode('|',array_map(static fn($i)=>$i->id(),$order));
            if(!isset($seen[$sig])){$out[]=['seeded_'.count($out),$order];$seen[$sig]=true;}
        }
        return $out;
    }

    /** @return array{0:list<PackedContainer>,1:list<UnpackedItem>,2:bool,3:bool,4:bool} */
    /**
     * The greedy round's key for one trial, read from the container the trial built: a
     * made-to-measure trial carries its fabricated box, so the key prices the box that ships,
     * never the machine maximum the loop declared. The trial chain ranks an
     * incomplete round's rungs by this same key.
     *
     * @param list<ItemInstance> $roundItems
     * @return list<mixed>
     */
    private static function roundScore(SingleContainerSolution $one,array $roundItems,PackingConfig $config,ContainerSelector $selector):array
    {
        $container=$one->state->container;
        if(($config->objective==='shipping_cost'||$config->objective==='lowest_landed_cost')&&$config->dimensionalWeightDivisor!==null){
            $dimensional=$one->state->billedDimensionalWeight($config);
            $gross=$container->tareWeight->ticks+$one->state->payloadTicks;
            $billed=max($gross,$dimensional);
            $placed=$one->state->placementCount();
            if($config->objective==='shipping_cost'){
                $candidateScore=[-$placed,$billed,$container->id];
            }else{
                // Landed cost ranks the round by the money the finished score will
                // charge, not by the grams it is derived from. This loop commits the
                // trial verbatim, so its billed weight is already final and the tariff
                // can be read now; a bracket step or a minimum charge makes the cheaper
                // shipment the heavier one, and a trial the tariff cannot price is
                // unshippable rather than merely dear, so it sorts behind every
                // priceable alternative. Keys after the charge mirror Rust's
                // and Python's, so the three engines pick the same container.
                $charge=$container->rateTable?->chargeMinorOrNull(LandedCostSolutionScorer::grams($billed));
                $candidateScore=[
                    $charge??LandedCostSolutionScorer::UNPRICEABLE_MINOR,
                    intdiv(count($roundItems)+max($placed,1)-1,max($placed,1)),
                    -$placed,
                    $container->id,
                ];
            }
        }elseif($config->objective==='maximum_volume'&&$selector instanceof DefaultContainerSelector){
            // The round keeps the trial that loaded the most volume, not the one
            // that seated the most pieces. A caller's own selector is left alone.
            $used=$one->state->usedVolume;
            $leftover=BigInt::chunks(BigInt::subtract($container->innerDimensions->volumeString(),$used));
            $candidateScore=[...array_map(static fn(int $c):int=>-$c,BigInt::chunks($used)),$container->costMinor,...$leftover,$container->id];
        }else{
            $candidateScore=$selector->score($container,$one);
        }
        return $candidateScore;
    }

    /**
     * One container's trial for a round, and how many single-container solves it spent. A
     * declared container is one solve; a made-to-measure one runs the rung chain and returns
     * its trial already fabricated; a pallet's frame without fixed items packs its
     * deck first.
     *
     * @param list<ItemInstance> $items
     * @return array{0:SingleContainerSolution,1:int}
     */
    private function trial(SingleContainerSolver $solver,Container $container,int $sequence,array $items,PackingConfig $config,SearchStats $stats,Deadline $deadline):array
    {
        if($container->madeToMeasure!==null)
            return MadeToMeasureTrial::madeToMeasureTrial($this->bridge(),$this->containerSelector,$solver,$container,$sequence,$items,$config,$stats,$deadline);
        if($container->deck!==null&&$container->preloaded===[])
            return PalletTrial::palletTrial($this->bridge(),$solver,$container,$sequence,$items,$config,$stats,$deadline);
        return [$solver->packOne($container,$sequence,$items,$config,$stats,$deadline),1];
    }

    /**
     * A rung trial as the box cut for it: the achieved extent of its placements, envelope far faces
     * with clearance, rounded up onto the machine grid (D14); or, given `$rung`, the rung itself,
     * which is already on the grid. O(p).
     *
     * @param array{0:int,1:int,2:int}|null $rung
     */
    private static function fabricated(Container $declared,SingleContainerSolution $one,?array $rung=null):SingleContainerSolution
    {
        $box=$declared->fabricate($rung??FlatBag::extentOf($one->state->placements));
        return new SingleContainerSolution($one->state->withContainer($box),$one->unpacked,false,$one->timeLimitReached,false);
    }

    /** @param list<PackedContainer> $preloaded */
    private function acrossContainers(SingleContainerSolver $solver,array $items,array $containers,PackingConfig $config,SearchStats $stats,Deadline $deadline,array $preloaded=[]):array
    {
        $beam=$config->containerPlanBeamWidth>1&&($this->containerSelector===null||$this->containerSelector instanceof DefaultContainerSelector);
        [$opened,$reached]=$this->openPreloaded($solver,$preloaded,$items,$containers,$config,$stats,$deadline);
        if($beam){
            return ContainerPlanBeam::acrossContainerPlans($this->bridge(),$solver,$items,$containers,$config,$stats,$deadline,$opened,$reached);
        }
        $remaining=$opened['remaining'];$packed=$opened['packed'];$exhaustive=$opened['exhaustive'];$dominantLattice=$opened['dominant'];
        $inventory=$opened['inventory'];$seq=$opened['sequences'];
        $maximum=$config->maxContainers??array_sum(array_map(static fn($c)=>$c->quantity??count($items),$containers));
        $ordered=StableSorter::sortBy($containers,static fn(Container $c):array=>[$c->costMinor,...$c->innerDimensions->volumeKey(),$c->id]);
        $selector=$this->containerSelector??new DefaultContainerSelector();
        while($remaining!==[]&&count($packed)<$maximum&&!$reached){
            if($deadline->expired()){$reached=true;break;}
            $best=null;$bestScore=null;
            foreach($ordered as $container){
                if($inventory[$container->id]!==null&&$inventory[$container->id]<=0)continue;
                try{[$one]=$this->trial($solver,$container,$seq[$container->id]+1,$remaining,$config,$stats,$deadline);}
                catch(TimeLimitReached){$reached=true;break;}
                if($one->timeLimitReached)$reached=true;
                if($one->state->placementCount()===0){if($reached)break;continue;}
                $candidateScore=self::roundScore($one,$remaining,$config,$selector);
                if($bestScore===null||$candidateScore<$bestScore){$bestScore=$candidateScore;$best=$one;}
                if($reached)break;
            }
            if($best===null)break;
            $c=$best->state->container;$seq[$c->id]++;
            $exhaustive=$exhaustive&&$best->exhaustive;
            $dominantLattice=$dominantLattice&&$best->dominantLattice;
            if($inventory[$c->id]!==null)$inventory[$c->id]--;
            [$committed,$ids]=self::commitState($best->state);
            $packed[]=$committed;
            $remaining=array_values(array_filter($remaining,static fn($i)=>!isset($ids[$i->id()])));
            if($reached)break;
        }
        $unpacked=[];$opened=self::openedByType($packed);
        foreach($remaining as $i){
            [$reason,$details]=$this->unpackedReason($i,$containers,$reached);
            if($reason==='no_feasible_placement'&&$this->supportIsTheBlocker($i,$packed,$config))$reason='insufficient_support';
            if($reason==='no_feasible_placement')$reason=self::limitReason($i,$containers,$opened,$config)??$reason;
            $unpacked[]=new UnpackedItem($i,$reason,$details);
        }
        // `RawSolution::$exhaustive` is a hint for extension scorers, not a proof: the result's
        // `optimal` status comes only from attaining the lower bound (`Packer::statedOptimality`).
        $proven=$exhaustive&&!$reached&&$unpacked===[]&&count($packed)===1;
        return [$packed,$unpacked,$proven,$reached,$dominantLattice];
    }

    /**
     * Fill every container holding fixed items, first, and keep it whatever it gets.
     *
     * Returns the plan every start continues from. A container that search cannot fill --
     * the deadline has passed, or the solver raised it -- is kept with its fixed items
     * alone: dropping it would drop items the request says are already loaded.
     *
     * @param list<PackedContainer> $preloaded @param list<ItemInstance> $items @param list<Container> $containers
     * @return array{0:array{packed:list<PackedContainer>,remaining:list<ItemInstance>,inventory:array<string,?int>,sequences:array<string,int>,exhaustive:bool,dominant:bool},1:bool}
     */
    private function openPreloaded(SingleContainerSolver $solver,array $preloaded,array $items,array $containers,PackingConfig $config,SearchStats $stats,Deadline $deadline):array
    {
        $plan=['packed'=>[],'remaining'=>$items,'inventory'=>[],'sequences'=>[],'exhaustive'=>true,'dominant'=>true];
        foreach($containers as $container){$plan['inventory'][$container->id]=$container->quantity;$plan['sequences'][$container->id]=0;}
        $reached=false;
        foreach($preloaded as $fixed){
            $seeded=$fixed->container->withPreloaded($fixed->placements);
            $one=null;
            if(!$reached&&!$deadline->expired()){
                try{$one=$solver->packOne($seeded,$fixed->sequence,$plan['remaining'],$config,$stats,$deadline);}
                catch(TimeLimitReached){}
            }
            if($one===null||$one->timeLimitReached)$reached=true;
            [$committed,$ids]=self::commitState($one?->state??new ContainerState($seeded,$fixed->sequence));
            $plan['packed'][]=$committed;
            $plan['remaining']=array_values(array_filter($plan['remaining'],static fn(ItemInstance $item):bool=>!isset($ids[$item->id()])));
            if($plan['inventory'][$seeded->id]!==null)$plan['inventory'][$seeded->id]--;
            $plan['sequences'][$seeded->id]=$fixed->sequence;
            $plan['exhaustive']=$plan['exhaustive']&&$one!==null&&$one->exhaustive;
            $plan['dominant']=$plan['dominant']&&$one!==null&&$one->dominantLattice;
        }
        return [$plan,$reached];
    }

    /** @return array{0:PackedContainer,1:array<string,bool>} */
    private static function commitState(ContainerState $state):array
    {
        // A result carries the request's container, not the seeded instance: anything that
        // rebuilds a state from a packed container re-adds every placement itself, and a
        // seeded container would add the fixed ones twice.
        $container=$state->container->preloaded===[]?$state->container:$state->container->withPreloaded([]);
        if($state->latticeSummary!==null){
            $packed=new PackedContainer($container,$state->sequence,[],$state->latticeSummary,$state->latticeItems);
            $ids=[];foreach($state->latticeItems as $item)$ids[$item->id()]=true;
        }else{
            $packed=new PackedContainer($container,$state->sequence,TopLoadAssigner::assign($state->placements));
            $ids=[];foreach($state->placements as $placement)$ids[$placement->instance->id()]=true;
        }
        return [$packed,$ids];
    }

    /** The orchestrator's own private methods, for the parts kept in `Internal` (see there). */
    private function bridge():\Closure
    {
        return fn(string $method,...$arguments)=>$this->{$method}(...$arguments);
    }
}
