<?php
declare(strict_types=1);
namespace Packvium\Algorithm\Internal;

use Packvium\Config\PackingConfig;
use Packvium\Domain\ItemInstance;
use Packvium\Objective\ObjectiveRegistry;
use Packvium\Algorithm\{ExtremePointSolver,RawSolution,SingleContainerSolver};

/**
 * `quality`'s staged starts, loaded only under a container-plan beam. A part of `SolverOrchestrator` kept in its own file so PHP compiles it only when a
 * request needs it: without opcache every request compiles what it loads (1.9.0 release
 * frontier).
 *
 * @internal
 */
final class QualityStages
{
    /**
     * A neighbourhood or refinement start bound to the best extreme-point answer so far, or
     * null when none qualifies and the start is skipped. A neighbourhood builds on a greedy
     * order only; the refinement on any extreme-point order, neighbourhoods included. Ranked
     * as the portfolio ranks results: score, then start name. O(r log r) for r results.
     *
     * @param list<RawSolution> $results
     * @param array<string,array{0:SingleContainerSolver,1:string,2:list<ItemInstance>}> $completed
     * @return array{0:SingleContainerSolver,1:string,2:list<ItemInstance>}|null
     */
    public static function stagedStart(string $orderName,array $results,array $completed,PackingConfig $config):?array
    {
        $neighborhood=str_starts_with($orderName,'neighborhood:');
        $eligible=array_values(array_filter(
            $results,
            static fn(RawSolution $result):bool=>$completed[$result->solverName][0] instanceof ExtremePointSolver
                &&(!$neighborhood||str_ends_with($result->solverName,':greedy')),
        ));
        if($eligible===[])return null;
        $scorer=ObjectiveRegistry::resolve($config->objective,$config);
        $best=$eligible[0];$bestScore=$scorer->score($best);
        foreach(array_slice($eligible,1) as $result){
            $score=$scorer->score($result);
            $order=$score->compare($bestScore);
            if($order<0||($order===0&&strcmp($result->solverName,$best->solverName)<0)){$best=$result;$bestScore=$score;}
        }
        [$solver,$baseName,$order]=$completed[$best->solverName];
        if(!$neighborhood)return [$solver,$baseName.':beam',$order];
        $type=substr($orderName,strlen('neighborhood:'));
        $late=array_merge(
            array_filter($order,static fn(ItemInstance $instance):bool=>$instance->item->id!==$type),
            array_filter($order,static fn(ItemInstance $instance):bool=>$instance->item->id===$type),
        );
        return [$solver,$baseName.':late_'.$type,$late];
    }

    public static function searchWidth(PackingConfig $config,int $candidatesPerItem,int $beamWidth,int $nodeLimit):PackingConfig
    {
        return new PackingConfig(
            profile:$config->profile,timeLimitMs:$config->timeLimitMs,topK:$config->topK,seed:$config->seed,maxContainers:$config->maxContainers,
            clearance:$config->clearance,minimumSupportRatio:$config->minimumSupportRatio,exactItemLimit:$config->exactItemLimit,multiStartOrders:$config->multiStartOrders,
            validateResult:$config->validateResult,maxCandidatesPerItem:$candidatesPerItem,maxCandidatePoints:$config->maxCandidatePoints,solvers:$config->solvers,objective:$config->objective,
            effortBudget:$config->effortBudget,dimensionalWeightDivisor:$config->dimensionalWeightDivisor,dimensionalWeightLengthUnit:$config->dimensionalWeightLengthUnit,
            dimensionalWeightWeightUnit:$config->dimensionalWeightWeightUnit,requirePlacementCoordinates:$config->requirePlacementCoordinates,parallelStarts:1,
            containerPlanBeamWidth:$beamWidth,containerPlanNodeLimit:$nodeLimit,accessDirections:$config->accessDirections,
        );
    }
}
