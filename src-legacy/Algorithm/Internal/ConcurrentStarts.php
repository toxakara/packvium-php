<?php
declare(strict_types=1);
namespace Packvium\Algorithm\Internal;

use Packvium\Config\PackingConfig;
use Packvium\Extension\CandidateScorer;
use Packvium\Result\StartRecord;
use Packvium\Algorithm\{Deadline,EffortBudget,PortfolioRun,RawSolution,SearchStats,SingleContainerSolver};

/**
 * The fork-based concurrent portfolio, loaded only when `parallelStarts` is above 1. A part of `SolverOrchestrator` kept in its own file so PHP compiles it only when a
 * request needs it: without opcache every request compiles what it loads (1.9.0 release
 * frontier). `$call` reaches the orchestrator's own private methods.
 *
 * @internal
 */
final class ConcurrentStarts
{
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
    public static function solveConcurrent(\Closure $call,?CandidateScorer $candidateScorer,array $items,array $containers,PackingConfig $config,Deadline $deadline,array $starts,?EffortBudget $effortBudget,array $preloaded=[]):PortfolioRun
    {
        $records=array_map(
            static function (array $start): StartRecord {
                return new StartRecord($start[0]->name().':'.$start[1],false,false,false);
            },
            $starts,
        );
        $results=array_fill(0,count($starts),null);

        [$solver0,$orderName0,$order0]=$starts[0];
        $stats0=new SearchStats();
        $budget0=$call('startBudget',$deadline,$effortBudget,$stats0,count($starts));
        [$packed0,$unpacked0,$exhaustive0,$reached0,$dominantLattice0]=$call('acrossContainers',$solver0,$order0,$containers,$config,$stats0,$budget0,$preloaded);
        [$wallReached0,$effortReached0]=$call('limitsReached',$reached0,$budget0->effortStopped(),$budget0->remainingNs());
        $unpacked0=$call('effortLimited',$unpacked0,$effortReached0);
        $id0=$solver0->name().':'.$orderName0;
        $results[0]=new RawSolution($id0,$packed0,$unpacked0,$stats0,$wallReached0,$effortReached0,$exhaustive0);
        $records[0]=new StartRecord($id0,true,!$reached0,$reached0);

        // Same correction as the sequential path: only a genuine lattice
        // construction is provably no worse than what remains, never a GridSolver
        // delegate result -- see `solveSequential`'s identical check for the full
        // rationale.
        $shortCircuit=$dominantLattice0&&$unpacked0===[]&&$candidateScorer===null;
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
                        [$results[$position],$records[$position]]=self::runOneStartInProcess($call,
                            $solver,$orderName,$order,$containers,$config,$effortBudget,$absoluteDeadlineNs,$preloaded,
                        );
                        continue;
                    }
                    if($pid===0){
                        fclose($pair[0]);
                        $stats=new SearchStats();
                        $budget=Deadline::until($absoluteDeadlineNs);
                        if($effortBudget!==null)$budget=$budget->withEffort($effortBudget,$stats);
                        [$packed,$unpacked,$exhaustive,$reached]=$call('acrossContainers',$solver,$order,$containers,$config,$stats,$budget,$preloaded);
                        // Trusted internal IPC: this payload only ever comes from this
                        // process's own fork running the exact same code, never from
                        // external/attacker-controlled input, so the default (allow
                        // every class) `unserialize()` on the parent's side is safe.
                        fwrite($pair[1],serialize([$packed,$unpacked,$exhaustive,$reached,$stats,$budget->effortStopped()]));
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
                    [$packed,$unpacked,$exhaustive,$reached,$stats,$effortStopped]=unserialize($data);
                    [$wallReached,$effortReached]=$call('limitsReached',$reached,$effortStopped,$absoluteDeadlineNs-hrtime(true));
                    $unpacked=$call('effortLimited',$unpacked,$effortReached);
                    $id=$solver->name().':'.$orderName;
                    $results[$position]=new RawSolution($id,$packed,$unpacked,$stats,$wallReached,$effortReached,$exhaustive);
                    $records[$position]=new StartRecord($id,true,!$reached,$reached);
                }
            }
        }

        $results=array_values(array_filter($results,static function ($result): bool {
            return $result!==null;
        }));
        return $call('finishPortfolio',$items,$containers,$deadline,$results,$records,$preloaded);
    }

    /** Fallback body for one start when `pcntl_fork()` itself fails -- runs synchronously, in the parent. */
    public static function runOneStartInProcess(\Closure $call,SingleContainerSolver $solver,string $orderName,array $order,array $containers,PackingConfig $config,?EffortBudget $effortBudget,int $absoluteDeadlineNs,array $preloaded):array
    {
        $stats=new SearchStats();
        $budget=Deadline::until($absoluteDeadlineNs);
        if($effortBudget!==null)$budget=$budget->withEffort($effortBudget,$stats);
        [$packed,$unpacked,$exhaustive,$reached,]=$call('acrossContainers',$solver,$order,$containers,$config,$stats,$budget,$preloaded);
        [$wallReached,$effortReached]=$call('limitsReached',$reached,$budget->effortStopped(),$budget->remainingNs());
        $unpacked=$call('effortLimited',$unpacked,$effortReached);
        $id=$solver->name().':'.$orderName;
        return [
            new RawSolution($id,$packed,$unpacked,$stats,$wallReached,$effortReached,$exhaustive),
            new StartRecord($id,true,!$reached,$reached),
        ];
    }
}
