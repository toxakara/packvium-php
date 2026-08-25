<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Config\{PackingConfig,SolverProfile};
use Packvium\Constraint\{ConstraintSet,ProvenRejection,SupportConstraint};
use Packvium\Domain\{Container,ItemInstance,PackedContainer,Rotation,UnpackedItem};
use Packvium\Extension\{CandidateScorer,ContainerSelector,DefaultCandidateScorer,DefaultContainerSelector,ItemOrderStrategy};
use Packvium\Result\StartRecord;
use Packvium\Support\{BigInt,StableSorter};
use Packvium\Objective\{LandedCostSolutionScorer,ObjectiveRegistry};
final class SolverOrchestrator
{
    public function __construct(private array $customConstraints=[],private array $customOrders=[],private array $customSolvers=[],private ?CandidateScorer $candidateScorer=null,private ?ContainerSelector $containerSelector=null){}

    /** @return list<RawSolution> */
    public function solve(array $items,array $containers,PackingConfig $config,Deadline $deadline):array
    {
        return $this->solvePortfolio($items,$containers,$config,$deadline)->solutions;
    }

    public function solvePortfolio(array $items,array $containers,PackingConfig $config,Deadline $deadline):PortfolioRun
    {
        $starts=$this->starts($items,$config);
        $effortBudget=$config->effortBudget;
        if($effortBudget!==null&&$effortBudget->maxRestarts!==null)$starts=array_slice($starts,0,$effortBudget->maxRestarts);
        if($this->eligibleForConcurrentExecution($deadline,$config)&&count($starts)>1){
            return $this->solveConcurrent($items,$containers,$config,$deadline,$starts,$effortBudget);
        }
        return $this->solveSequential($items,$containers,$config,$deadline,$starts,$effortBudget);
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

    private function solveSequential(array $items,array $containers,PackingConfig $config,Deadline $deadline,array $starts,?EffortBudget $effortBudget):PortfolioRun
    {
        $results=[];
        $records=array_map(
            static fn(array $start):StartRecord=>new StartRecord($start[0]->name().':'.$start[1],false,false,false),
            $starts,
        );
        foreach($starts as $position=>[$solver,$orderName,$order]){
            if($deadline->expired())break;
            $stats=new SearchStats();
            $budget=$deadline->slice(count($starts)-$position);
            if($effortBudget!==null)$budget=$budget->withEffort($effortBudget,$stats);
            $startConfig=str_ends_with($orderName,':greedy')?self::greedyConfig($config):$config;
            [$packed,$unpacked,$exhaustive,$reached,$dominantLattice]=$this->acrossContainers($solver,$order,$containers,$startConfig,$stats,$budget);
            $effortReached=$budget->effortExceeded();
            $wallReached=$reached&&$budget->remainingNs()<=0;
            if($effortReached){
                $unpacked=array_map(
                    static fn(UnpackedItem $item):UnpackedItem=>$item->reason==='time_limit'
                        ? new UnpackedItem($item->instance,'effort_limit',$item->details)
                        : $item,
                    $unpacked,
                );
            }
            $id=$solver->name().':'.$orderName;
            $results[]=new RawSolution($id,$packed,$unpacked,$stats,$wallReached,$effortReached,$exhaustive);
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
        return $this->finishPortfolio($items,$containers,$deadline,$results,$records);
    }

    /**
     * Runs every start after the first one concurrently, each in its own forked
     * process.
     *
     * The first start always runs in-process, exactly as `solveSequential` would
     * run it: `starts()` always places the order-insensitive lattice first when it
     * is eligible at all, so the grid short-circuit below can only ever
     * fire on this one start, and running it in-process first keeps that
     * short-circuit -- and every sequential-mode test that depends on it --
     * byte-for-byte unchanged.
     *
     * Every remaining start is forked, bound to one shared absolute deadline
     * (`Deadline::until`) computed once, right here, from however much of the
     * portfolio's own deadline is left; `hrtime(true)` is machine-wide rather than
     * per-process, so every child can independently compare its own fresh reading
     * against that same instant. Forks are batched in groups of `parallelStarts` so
     * this never spawns more concurrent processes than the caller asked for.
     * Results are placed into `$results`/`$records` by each start's fixed position,
     * never by completion order, so the final list handed to `Packer::pack`'s
     * (order-independent, full-key) ranking is identical no matter how the OS
     * scheduled the forked processes.
     *
     * A child communicates its outcome back to the parent over an anonymous
     * `stream_socket_pair` (no disk I/O, unlike a temp file) as one `serialize()`d
     * payload, written after the child's own solve finishes and read by the parent
     * via a blocking `stream_get_contents` that returns once the child closes its
     * end. If `pcntl_fork()` itself fails for one start (a real but rare resource
     * exhaustion case), that one start falls back to running synchronously in the
     * parent instead of losing it -- correctness never depends on the fork
     * succeeding, only on whether that start gets the speed-up.
     */
    private function solveConcurrent(array $items,array $containers,PackingConfig $config,Deadline $deadline,array $starts,?EffortBudget $effortBudget):PortfolioRun
    {
        $records=array_map(
            static fn(array $start):StartRecord=>new StartRecord($start[0]->name().':'.$start[1],false,false,false),
            $starts,
        );
        $results=array_fill(0,count($starts),null);

        [$solver0,$orderName0,$order0]=$starts[0];
        $stats0=new SearchStats();
        $budget0=$deadline->slice(count($starts));
        if($effortBudget!==null)$budget0=$budget0->withEffort($effortBudget,$stats0);
        [$packed0,$unpacked0,$exhaustive0,$reached0,$dominantLattice0]=$this->acrossContainers($solver0,$order0,$containers,$config,$stats0,$budget0);
        $effortReached0=$budget0->effortExceeded();
        $wallReached0=$reached0&&$budget0->remainingNs()<=0;
        if($effortReached0){
            $unpacked0=array_map(
                static fn(UnpackedItem $item):UnpackedItem=>$item->reason==='time_limit'
                    ? new UnpackedItem($item->instance,'effort_limit',$item->details)
                    : $item,
                $unpacked0,
            );
        }
        $id0=$solver0->name().':'.$orderName0;
        $results[0]=new RawSolution($id0,$packed0,$unpacked0,$stats0,$wallReached0,$effortReached0,$exhaustive0);
        $records[0]=new StartRecord($id0,true,!$reached0,$reached0);

        // Same correction as the sequential path: only a genuine lattice
        // construction is provably no worse than what remains, never a GridSolver
        // delegate result -- see `solveSequential`'s identical check for the full
        // rationale.
        $shortCircuit=$dominantLattice0&&$unpacked0===[]&&$this->candidateScorer===null;
        $remaining=array_slice($starts,1,null,true);

        if($remaining!==[]&&!$shortCircuit&&!$deadline->expired()){
            $absoluteDeadlineNs=hrtime(true)+$deadline->remainingNs();
            $workerCount=max(1,min($config->parallelStarts,count($remaining)));
            foreach(array_chunk($remaining,$workerCount,true) as $batch){
                $sockets=[];$pids=[];
                foreach($batch as $position=>[$solver,$orderName,$order]){
                    $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
                    $pid=pcntl_fork();
                    if($pid===-1){
                        fclose($pair[0]);fclose($pair[1]);
                        [$results[$position],$records[$position]]=$this->runOneStartInProcess(
                            $solver,$orderName,$order,$containers,$config,$effortBudget,$absoluteDeadlineNs,
                        );
                        continue;
                    }
                    if($pid===0){
                        fclose($pair[0]);
                        $stats=new SearchStats();
                        $budget=Deadline::until($absoluteDeadlineNs);
                        if($effortBudget!==null)$budget=$budget->withEffort($effortBudget,$stats);
                        [$packed,$unpacked,$exhaustive,$reached]=$this->acrossContainers($solver,$order,$containers,$config,$stats,$budget);
                        // Trusted internal IPC: this payload only ever comes from this
                        // process's own fork running the exact same code, never from
                        // external/attacker-controlled input, so the default (allow
                        // every class) `unserialize()` on the parent's side is safe.
                        fwrite($pair[1],serialize([$packed,$unpacked,$exhaustive,$reached,$stats]));
                        fclose($pair[1]);
                        exit(0);
                    }
                    fclose($pair[1]);
                    $sockets[$position]=$pair[0];
                    $pids[$position]=$pid;
                }
                foreach($sockets as $position=>$socket){
                    [$solver,$orderName]=$batch[$position];
                    $data=stream_get_contents($socket);
                    fclose($socket);
                    pcntl_waitpid($pids[$position],$status);
                    [$packed,$unpacked,$exhaustive,$reached,$stats]=unserialize($data);
                    $effortReached=$effortBudget!==null&&$effortBudget->exceeded($stats);
                    $wallReached=$reached&&($absoluteDeadlineNs-hrtime(true))<=0;
                    if($effortReached){
                        $unpacked=array_map(
                            static fn(UnpackedItem $item):UnpackedItem=>$item->reason==='time_limit'
                                ? new UnpackedItem($item->instance,'effort_limit',$item->details)
                                : $item,
                            $unpacked,
                        );
                    }
                    $id=$solver->name().':'.$orderName;
                    $results[$position]=new RawSolution($id,$packed,$unpacked,$stats,$wallReached,$effortReached,$exhaustive);
                    $records[$position]=new StartRecord($id,true,!$reached,$reached);
                }
            }
        }

        $results=array_values(array_filter($results,static fn($result):bool=>$result!==null));
        return $this->finishPortfolio($items,$containers,$deadline,$results,$records);
    }

    /** Fallback body for one start when `pcntl_fork()` itself fails -- runs synchronously, in the parent. */
    private function runOneStartInProcess(SingleContainerSolver $solver,string $orderName,array $order,array $containers,PackingConfig $config,?EffortBudget $effortBudget,int $absoluteDeadlineNs):array
    {
        $stats=new SearchStats();
        $budget=Deadline::until($absoluteDeadlineNs);
        if($effortBudget!==null)$budget=$budget->withEffort($effortBudget,$stats);
        [$packed,$unpacked,$exhaustive,$reached,]=$this->acrossContainers($solver,$order,$containers,$config,$stats,$budget);
        $effortReached=$budget->effortExceeded();
        $wallReached=$reached&&$budget->remainingNs()<=0;
        if($effortReached){
            $unpacked=array_map(
                static fn(UnpackedItem $item):UnpackedItem=>$item->reason==='time_limit'
                    ? new UnpackedItem($item->instance,'effort_limit',$item->details)
                    : $item,
                $unpacked,
            );
        }
        $id=$solver->name().':'.$orderName;
        return [
            new RawSolution($id,$packed,$unpacked,$stats,$wallReached,$effortReached,$exhaustive),
            new StartRecord($id,true,!$reached,$reached),
        ];
    }

    private function finishPortfolio(array $items,array $containers,Deadline $deadline,array $results,array $records):PortfolioRun
    {
        $globalDeadline=$deadline->expired();
        $records=array_map(
            static fn(StartRecord $record):StartRecord=>$record->withGlobalDeadline($globalDeadline),
            $records,
        );
        if($results===[]){
            $fallbackId='portfolio:fallback';
            $results[] = new RawSolution($fallbackId,[],array_map(
                fn($i)=>new UnpackedItem($i,...$this->unpackedReason($i,$containers,true)),
                $items,
            ),new SearchStats(),true);
            $records[]=new StartRecord($fallbackId,true,true,false,false,$globalDeadline);
        }
        return new PortfolioRun($results,$records);
    }

    /** @param list<Container> $containers */
    /** @return array{0:string,1:list<string>} */
    private function unpackedReason(ItemInstance $item,array $containers,bool $reached):array
    {
        $dimension=false;$weight=false;
        foreach($containers as $container){
            foreach($item->dimensions()->uniqueRotations($item->item->allowedRotations) as [,$dimensions])
                if($dimensions->fitsInside($container->innerDimensions)){$dimension=true;break 2;}
        }
        // Same geometric check with every physical orientation allowed, not only the
        // item's own restricted set -- distinguishes "genuinely too big in any
        // rotation" from "this exact rotation restriction, and only it, rules every
        // container out". Both are pure geometry, so both are provable
        // without a complete search.
        $fitsAnyRotation=false;
        foreach($containers as $container){
            foreach($item->dimensions()->uniqueRotations(Rotation::all()) as [,$dimensions])
                if($dimensions->fitsInside($container->innerDimensions)){$fitsAnyRotation=true;break 2;}
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
        $constraints=ConstraintSet::defaults($config->minimumSupportRatio,$this->customConstraints);
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

    /** @return list<array{0:SingleContainerSolver,1:string,2:list<ItemInstance>}> */
    private function starts(array $items,PackingConfig $config):array
    {
        $orders=$this->orders($items,$config);
        $starts=[];
        foreach($this->solvers($items,$config) as $solver){
            // A lattice ignores item order, so repeating it per ordering only burns budget.
            $chosen=(method_exists($solver,'orderInsensitive')&&$solver->orderInsensitive())?array_slice($orders,0,1):$orders;
            foreach($chosen as [$name,$order])$starts[]=[$solver,$name,$order];
        }
        if($config->containerPlanBeamWidth<=1||$config->profile!==SolverProfile::Quality)return $starts;
        $greedy=array_map(static fn(array $start):array=>$start[0]->name()==='homogeneous_blocks'?$start:[$start[0],$start[1].':greedy',$start[2]],$starts);
        $refinements=[];foreach($starts as [$solver,$name,$order])if($solver->name()==='extreme_points'&&count($refinements)<2)$refinements[]=[$solver,$name.':beam',$order];
        return [...$greedy,...$refinements];
    }

    private static function greedyConfig(PackingConfig $config):PackingConfig
    {
        return new PackingConfig(
            profile:$config->profile,timeLimitMs:$config->timeLimitMs,topK:$config->topK,seed:$config->seed,maxContainers:$config->maxContainers,
            clearance:$config->clearance,minimumSupportRatio:$config->minimumSupportRatio,exactItemLimit:$config->exactItemLimit,multiStartOrders:$config->multiStartOrders,
            validateResult:$config->validateResult,maxCandidatesPerItem:1,maxCandidatePoints:$config->maxCandidatePoints,solvers:$config->solvers,objective:$config->objective,
            effortBudget:$config->effortBudget,dimensionalWeightDivisor:$config->dimensionalWeightDivisor,dimensionalWeightLengthUnit:$config->dimensionalWeightLengthUnit,
            dimensionalWeightWeightUnit:$config->dimensionalWeightWeightUnit,requirePlacementCoordinates:$config->requirePlacementCoordinates,parallelStarts:1,
            containerPlanBeamWidth:1,containerPlanNodeLimit:1,
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
        $strategies=[];
        if($config->profile===SolverProfile::Quality)$strategies=[
            ['small_edge',static fn(ItemInstance $i):array=>[...ItemOrdering::lead($i,$config),$i->dimensions()->maxEdge(),...$i->dimensions()->volumeKey(),$i->id()]],
            ['small_volume',static fn(ItemInstance $i):array=>[...ItemOrdering::lead($i,$config),...$i->dimensions()->volumeKey(),$i->dimensions()->maxEdge(),$i->id()]],
        ];
        $strategies=[...$strategies,...[
            ['volume',static fn(ItemInstance $i):array=>[...ItemOrdering::lead($i,$config),...$i->dimensions()->descendingVolumeKey(),-$i->dimensions()->maxEdge(),-$i->weight()->ticks,$i->id()]],
            ['base_area',static fn(ItemInstance $i):array=>[...ItemOrdering::lead($i,$config),-$i->dimensions()->baseAreaTicks(),...$i->dimensions()->descendingVolumeKey(),$i->id()]],
            ['weight',static fn(ItemInstance $i):array=>[...ItemOrdering::lead($i,$config),-$i->weight()->ticks,...$i->dimensions()->descendingVolumeKey(),$i->id()]],
            ['constrained',static fn(ItemInstance $i):array=>[...ItemOrdering::lead($i,$config),count($i->item->allowedRotations),-$i->item->minimumSupportRatio,...$i->dimensions()->descendingVolumeKey(),$i->id()]],
        ]];
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
    private function acrossContainers(SingleContainerSolver $solver,array $items,array $containers,PackingConfig $config,SearchStats $stats,Deadline $deadline):array
    {
        if($config->containerPlanBeamWidth>1&&($this->containerSelector===null||$this->containerSelector instanceof DefaultContainerSelector)){
            return $this->acrossContainerPlans($solver,$items,$containers,$config,$stats,$deadline);
        }
        $remaining=$items;$packed=[];$reached=false;$exhaustive=true;$dominantLattice=true;
        $inventory=[];$seq=[];
        foreach($containers as $c){$inventory[$c->id]=$c->quantity;$seq[$c->id]=0;}
        $maximum=$config->maxContainers??array_sum(array_map(static fn($c)=>$c->quantity??count($items),$containers));
        $ordered=StableSorter::sortBy($containers,static fn(Container $c):array=>[$c->costMinor,...$c->innerDimensions->volumeKey(),$c->id]);
        $selector=$this->containerSelector??new DefaultContainerSelector();
        while($remaining!==[]&&count($packed)<$maximum){
            if($deadline->expired()){$reached=true;break;}
            $candidates=[];
            foreach($ordered as $container){
                if($inventory[$container->id]!==null&&$inventory[$container->id]<=0)continue;
                try{$one=$solver->packOne($container,$seq[$container->id]+1,$remaining,$config,$stats,$deadline);}
                catch(TimeLimitReached){$reached=true;break;}
                if($one->timeLimitReached)$reached=true;
                if($one->state->placementCount()===0){if($reached)break;continue;}
                if(($config->objective==='shipping_cost'||$config->objective==='lowest_landed_cost')&&$config->dimensionalWeightDivisor!==null){
                    $dimensions=$container->outerDimensions??$container->innerDimensions;
                    $dimensional=$dimensions->dimensionalWeight(
                        $config->dimensionalWeightDivisor,
                        $config->dimensionalWeightLengthUnit,
                        $config->dimensionalWeightWeightUnit,
                    )->ticks;
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
                            intdiv(count($remaining)+max($placed,1)-1,max($placed,1)),
                            -$placed,
                            $container->id,
                        ];
                    }
                }else{
                    $candidateScore=$selector->score($container,$one);
                }
                $candidates[]=[$candidateScore,$one];
                if($reached)break;
            }
            if($candidates===[])break;
            $candidates=StableSorter::sortBy($candidates,static fn(array $candidate):array=>$candidate[0]);
            $best=$candidates[0][1];$c=$best->state->container;$seq[$c->id]++;
            $exhaustive=$exhaustive&&$best->exhaustive;
            $dominantLattice=$dominantLattice&&$best->dominantLattice;
            if($inventory[$c->id]!==null)$inventory[$c->id]--;
            if($best->state->latticeSummary!==null){
                // Compact fast path: no per-item Placement to attach a top load to --
                // the summary carries every instance's coordinates implicitly instead.
                $packed[]=new PackedContainer($c,$best->state->sequence,[],$best->state->latticeSummary,$best->state->latticeItems);
                $ids=[];foreach($best->state->latticeItems as $i)$ids[$i->id()]=true;
            }else{
                $packed[]=new PackedContainer($c,$best->state->sequence,TopLoadAssigner::assign($best->state->placements));
                $ids=[];foreach($best->state->placements as $p)$ids[$p->instance->id()]=true;
            }
            $remaining=array_values(array_filter($remaining,static fn($i)=>!isset($ids[$i->id()])));
            if($reached)break;
        }
        $unpacked=[];
        foreach($remaining as $i){
            [$reason,$details]=$this->unpackedReason($i,$containers,$reached);
            if($reason==='no_feasible_placement'&&$this->supportIsTheBlocker($i,$packed,$config))$reason='insufficient_support';
            $unpacked[]=new UnpackedItem($i,$reason,$details);
        }
        // Optimality may only be claimed when an exhaustive search filled a single
        // container — the cheapest one, since containers are tried in cost order.
        $proven=$exhaustive&&!$reached&&$unpacked===[]&&count($packed)===1;
        return [$packed,$unpacked,$proven,$reached,$dominantLattice];
    }

    /** @return array{0:PackedContainer,1:array<string,bool>} */
    private static function commitState(ContainerState $state):array
    {
        $container=$state->container;
        if($state->latticeSummary!==null){
            $packed=new PackedContainer($container,$state->sequence,[],$state->latticeSummary,$state->latticeItems);
            $ids=[];foreach($state->latticeItems as $item)$ids[$item->id()]=true;
        }else{
            $packed=new PackedContainer($container,$state->sequence,TopLoadAssigner::assign($state->placements));
            $ids=[];foreach($state->placements as $placement)$ids[$placement->instance->id()]=true;
        }
        return [$packed,$ids];
    }

    private static function planScore(array $plan,array $remaining,PackingConfig $config):array
    {
        $unpacked=array_map(static fn(ItemInstance $item):UnpackedItem=>new UnpackedItem($item,'search_exhausted'),$remaining);
        $raw=new RawSolution('container_plan',$plan['packed'],$unpacked,new SearchStats());
        return ObjectiveRegistry::resolve($config->objective,$config)->score($raw)->components;
    }

    private static function additionalContainerLowerBound(array $plan,array $containers):int
    {
        $available=array_values(array_filter($containers,static fn(Container $container):bool=>$plan['inventory'][$container->id]===null||$plan['inventory'][$container->id]>0));
        if($plan['remaining']===[]||$available===[])return 0;
        $lower=0;
        if(!array_filter($plan['remaining'],static fn(ItemInstance $item):bool=>$item->item->nestingHeight!==null)){
            $capacity='0';foreach($available as $container)if(BigInt::compare($container->innerDimensions->volumeString(),$capacity)>0)$capacity=$container->innerDimensions->volumeString();
            if($capacity!=='0'){
                $required='0';foreach($plan['remaining'] as $item)$required=BigInt::add($required,$item->dimensions()->volumeString());
                $lower=max($lower,(int)BigInt::divide(BigInt::add($required,BigInt::subtract($capacity,'1')),$capacity));
            }
        }
        if(array_filter($available,static fn(Container $container):bool=>$container->maxPayload===null)===[]){
            $capacity=max(array_map(static fn(Container $container):int=>$container->maxPayload->ticks,$available));
            if($capacity>0){$required=array_sum(array_map(static fn(ItemInstance $item):int=>$item->weight()->ticks,$plan['remaining']));$lower=max($lower,intdiv($required+$capacity-1,$capacity));}
        }
        return $lower;
    }

    private static function planBound(array $plan,array $containers,PackingConfig $config):array
    {
        $score=self::planScore($plan,[],$config);$index=$config->objective==='default'?1:2;
        $score[$index]+=self::additionalContainerLowerBound($plan,$containers);
        $score[]=implode('|',array_map(static fn(ItemInstance $item):string=>$item->id(),$plan['remaining']));
        return $score;
    }

    /** @return array{0:list<PackedContainer>,1:list<UnpackedItem>,2:bool,3:bool,4:bool} */
    private function acrossContainerPlans(SingleContainerSolver $solver,array $items,array $containers,PackingConfig $config,SearchStats $stats,Deadline $deadline):array
    {
        $inventory=[];foreach($containers as $container)$inventory[$container->id]=$container->quantity;
        $initial=['packed'=>[],'remaining'=>$items,'inventory'=>$inventory,'sequence'=>0,'exhaustive'=>true,'dominant'=>true];
        $beam=[$initial];$incumbent=$initial;$nodes=0;$reached=false;
        $maximum=$config->maxContainers??array_sum(array_map(static fn(Container $container):int=>$container->quantity??count($items),$containers));
        $ordered=StableSorter::sortBy($containers,static fn(Container $container):array=>[$container->costMinor,...$container->innerDimensions->volumeKey(),$container->id]);
        while($beam!==[]&&$nodes<$config->containerPlanNodeLimit){
            $expansions=[];
            foreach($beam as $plan){
                if($plan['remaining']===[]||count($plan['packed'])>=$maximum){if(self::planScore($plan,$plan['remaining'],$config)<self::planScore($incumbent,$incumbent['remaining'],$config))$incumbent=$plan;continue;}
                foreach($ordered as $container){
                    if($nodes>=$config->containerPlanNodeLimit)break;
                    if($deadline->expired()){$reached=true;break;}
                    if($plan['inventory'][$container->id]!==null&&$plan['inventory'][$container->id]<=0)continue;
                    $nodes++;
                    try{$one=$solver->packOne($container,$plan['sequence']+1,$plan['remaining'],$config,$stats,$deadline);}catch(TimeLimitReached){$reached=true;break;}
                    $reached=$reached||$one->timeLimitReached;if($one->state->placementCount()===0)continue;
                    [$committed,$ids]=self::commitState($one->state);$child=$plan;$child['packed'][]=$committed;$child['remaining']=array_values(array_filter($plan['remaining'],static fn(ItemInstance $item):bool=>!isset($ids[$item->id()])));$child['sequence']++;
                    if($child['inventory'][$container->id]!==null)$child['inventory'][$container->id]--;
                    $child['exhaustive']=$plan['exhaustive']&&$one->exhaustive;$child['dominant']=$plan['dominant']&&$one->dominantLattice;$expansions[]=$child;
                    if(self::planScore($child,$child['remaining'],$config)<self::planScore($incumbent,$incumbent['remaining'],$config))$incumbent=$child;
                    if($reached)break;
                }
                if($reached)break;
            }
            if($reached||$expansions===[])break;
            $dominant=[];
            foreach($expansions as $plan){$signature=implode('|',array_map(static fn(ItemInstance $item):string=>$item->id(),$plan['remaining'])).'::'.json_encode($plan['inventory'],JSON_THROW_ON_ERROR);$previous=$dominant[$signature]??null;if($previous===null||self::planScore($plan,[],$config)<self::planScore($previous,[],$config))$dominant[$signature]=$plan;}
            // planBound re-derives the additive volume/payload relaxation with BigInt
            // string math; one evaluation per plan instead of one per stable-usort
            // comparison leaves the ordering untouched.
            $beam=array_slice(
                StableSorter::sortBy(array_values($dominant),static fn(array $plan):array=>self::planBound($plan,$containers,$config)),
                0,$config->containerPlanBeamWidth,
            );
        }
        $unpacked=[];foreach($incumbent['remaining'] as $item){[$reason,$details]=$this->unpackedReason($item,$containers,$reached);if($reason==='no_feasible_placement'&&$this->supportIsTheBlocker($item,$incumbent['packed'],$config))$reason='insufficient_support';$unpacked[]=new UnpackedItem($item,$reason,$details);}
        $proven=$incumbent['exhaustive']&&!$reached&&$unpacked===[]&&count($incumbent['packed'])===1;
        return [$incumbent['packed'],$unpacked,$proven,$reached,$incumbent['dominant']];
    }
}
