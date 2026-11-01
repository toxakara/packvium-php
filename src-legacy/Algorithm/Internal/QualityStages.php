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
        $neighborhood=strncmp($orderName, 'neighborhood:', strlen('neighborhood:')) === 0;
        $eligible=array_values(array_filter(
            $results,
            static function (RawSolution $result) use ($completed, $neighborhood): bool {
                return $completed[$result->solverName][0] instanceof ExtremePointSolver
                    &&(!$neighborhood||substr_compare($result->solverName, ':greedy', -strlen(':greedy')) === 0);
            },
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
        $type=(string) substr($orderName,strlen('neighborhood:'));
        $late=array_merge(
            array_filter($order,static function (ItemInstance $instance) use ($type): bool {
                return $instance->item->id!==$type;
            }),
            array_filter($order,static function (ItemInstance $instance) use ($type): bool {
                return $instance->item->id===$type;
            }),
        );
        return [$solver,$baseName.':late_'.$type,$late];
    }

    public static function searchWidth(PackingConfig $config,int $candidatesPerItem,int $beamWidth,int $nodeLimit):PackingConfig
    {
        return new PackingConfig(
            $config->profile,$config->timeLimitMs,$config->topK,$config->seed,$config->maxContainers,
            $config->clearance,$config->minimumSupportRatio,$config->exactItemLimit,$config->multiStartOrders,
            $config->validateResult,$candidatesPerItem,$config->maxCandidatePoints,$config->solvers,$config->objective,
            $config->effortBudget,$config->dimensionalWeightDivisor,$config->dimensionalWeightLengthUnit,
            $config->dimensionalWeightWeightUnit,$config->requirePlacementCoordinates,1,
            $beamWidth,$nodeLimit,$config->accessDirections,
        );
    }
}
