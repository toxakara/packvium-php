<?php
declare(strict_types=1);
namespace Packvium\Algorithm\Internal;

use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container,ItemInstance,Nesting,PackedContainer,UnpackedItem};
use Packvium\Support\{BigInt,StableSorter};
use Packvium\Objective\ObjectiveRegistry;
use Packvium\Algorithm\{Deadline,RawSolution,SearchStats,SingleContainerSolver,TimeLimitReached};

/**
 * The container-plan beam, loaded only when `containerPlanBeamWidth` is above 1. A part of `SolverOrchestrator` kept in its own file so PHP compiles it only when a
 * request needs it: without opcache every request compiles what it loads (1.9.0 release
 * frontier). `$call` reaches the orchestrator's own private methods.
 *
 * @internal
 */
final class ContainerPlanBeam
{
    /**
     * @param array{packed:list<PackedContainer>,remaining:list<ItemInstance>,inventory:array<string,?int>,sequences:array<string,int>,exhaustive:bool,dominant:bool} $initial
     * @return array{0:list<PackedContainer>,1:list<UnpackedItem>,2:bool,3:bool,4:bool}
     */
    public static function acrossContainerPlans(\Closure $call,SingleContainerSolver $solver,array $items,array $containers,PackingConfig $config,SearchStats $stats,Deadline $deadline,array $initial,bool $reached):array
    {
        // Numbered per container type, as the greedy path and every other engine number
        // them: a fixed placement names `<type>#<n>`, so the count must be the type's own.
        $beam=$reached?[]:[$initial];$incumbent=$initial;$nodes=0;
        $incumbentScore=self::planScore($incumbent,$incumbent['remaining'],$config);
        $maximum=$config->maxContainers??array_sum(array_map(static function (Container $container) use ($items): int {
            return $container->quantity??count($items);
        },$containers));
        $ordered=StableSorter::sortBy($containers,static function (Container $container): array {
            return array_merge([$container->costMinor], $container->innerDimensions->volumeKey(), [$container->id]);
        });
        // A beam wider than its node budget used to stop after trying the first
        // container in static order, so the order chose it, not the objective. The budget is at
        // least one trial per container type for every plan the beam keeps.
        $nodeLimit=max($config->containerPlanNodeLimit,$config->containerPlanBeamWidth*count($containers));
        while($beam!==[]&&$nodes<$nodeLimit){
            $expansions=[];
            foreach($beam as $plan){
                if($plan['remaining']===[]||count($plan['packed'])>=$maximum){
                    $planScore=self::planScore($plan,$plan['remaining'],$config);
                    if($planScore<$incumbentScore){$incumbent=$plan;$incumbentScore=$planScore;}
                    continue;
                }
                foreach($ordered as $container){
                    if($nodes>=$nodeLimit)break;
                    if($deadline->expired()){$reached=true;break;}
                    if($plan['inventory'][$container->id]!==null&&$plan['inventory'][$container->id]<=0)continue;
                    // Each rung is one single-container solve, so the node limit keeps bounding
                    // the work of a made-to-measure chain.
                    try{[$one,$trials]=$call('trial',$solver,$container,$plan['sequences'][$container->id]+1,$plan['remaining'],$config,$stats,$deadline);}catch(TimeLimitReached $exception){$nodes++;$reached=true;break;}
                    $nodes+=$trials;
                    $reached=$reached||$one->timeLimitReached;if($one->state->placementCount()===0)continue;
                    [$committed,$ids]=$call('commitState',$one->state);$child=$plan;$child['packed'][]=$committed;$child['remaining']=array_values(array_filter($plan['remaining'],static function (ItemInstance $item) use ($ids): bool {
                        return !isset($ids[$item->id()]);
                    }));$child['sequences'][$container->id]++;
                    if($child['inventory'][$container->id]!==null)$child['inventory'][$container->id]--;
                    $child['exhaustive']=$plan['exhaustive']&&$one->exhaustive;$child['dominant']=$plan['dominant']&&$one->dominantLattice;$expansions[]=$child;
                    $childScore=self::planScore($child,$child['remaining'],$config);
                    if($childScore<$incumbentScore){$incumbent=$child;$incumbentScore=$childScore;}
                    if($reached)break;
                }
                if($reached)break;
            }
            if($reached||$expansions===[])break;
            $dominant=[];
            foreach($expansions as $plan){
                $signature=implode('|',array_map(static function (ItemInstance $item): string {
                    return $item->id();
                },$plan['remaining'])).'::'.json_encode($plan['inventory'],JSON_THROW_ON_ERROR);
                $partialScore=self::planScore($plan,[],$config);
                $previous=$dominant[$signature]??null;
                if($previous===null||$partialScore<$previous[0])$dominant[$signature]=[$partialScore,$plan];
            }
            // planBound re-derives the additive volume/payload relaxation with BigInt
            // string math; one evaluation per plan instead of one per stable-usort
            // comparison leaves the ordering untouched.
            $survivors=array_map(static function (array $pair): array {
                return $pair[1];
            },array_values($dominant));
            $beam=count($survivors)>1
                ?array_slice(StableSorter::sortBy($survivors,static function (array $plan) use ($containers, $config): array {
                    return self::planBound($plan,$containers,$config);
                }),0,$config->containerPlanBeamWidth)
                :$survivors;
        }
        $unpacked=[];$opened=$call('openedByType',$incumbent['packed']);foreach($incumbent['remaining'] as $item){[$reason,$details]=$call('unpackedReason',$item,$containers,$reached);if($reason==='no_feasible_placement'&&$call('supportIsTheBlocker',$item,$incumbent['packed'],$config))$reason='insufficient_support';if($reason==='no_feasible_placement')$reason=$call('limitReason',$item,$containers,$opened,$config)??$reason;$unpacked[]=new UnpackedItem($item,$reason,$details);}
        $proven=$incumbent['exhaustive']&&!$reached&&$unpacked===[]&&count($incumbent['packed'])===1;
        return [$incumbent['packed'],$unpacked,$proven,$reached,$incumbent['dominant']];
    }
    public static function planScore(array $plan,array $remaining,PackingConfig $config):array
    {
        $unpacked=array_map(static function (ItemInstance $item): UnpackedItem {
            return new UnpackedItem($item,'search_exhausted');
        },$remaining);
        $raw=new RawSolution('container_plan',$plan['packed'],$unpacked,new SearchStats());
        return ObjectiveRegistry::resolve($config->objective,$config)->score($raw)->components;
    }

    public static function planBound(array $plan,array $containers,PackingConfig $config):array
    {
        $score=self::planScore($plan,[],$config);$index=$config->objective==='default'?1:2;
        $score[$index]+=self::additionalContainerLowerBound($plan,$containers);
        $score[]=implode('|',array_map(static function (ItemInstance $item): string {
            return $item->id();
        },$plan['remaining']));
        return $score;
    }

    private static function additionalContainerLowerBound(array $plan,array $containers):int
    {
        $available=array_values(array_filter($containers,static function (Container $container) use ($plan): bool {
            return $plan['inventory'][$container->id]===null||$plan['inventory'][$container->id]>0;
        }));
        if($plan['remaining']===[]||$available===[])return 0;
        $lower=0;
        if(!array_filter($plan['remaining'],static function (ItemInstance $item): bool {
            return $item->item->nestingHeight!==null;
        })){
            $capacity='0';foreach($available as $container)if(BigInt::compare($container->innerDimensions->volumeString(),$capacity)>0)$capacity=$container->innerDimensions->volumeString();
            if($capacity!=='0'){
                $required='0';foreach($plan['remaining'] as $item)$required=BigInt::add($required,Nesting::itemOccupiedVolume($item));
                $lower=max($lower,(int)BigInt::divide(BigInt::add($required,BigInt::subtract($capacity,'1')),$capacity));
            }
        }
        if(array_filter($available,static function (Container $container): bool {
            return $container->maxPayload===null;
        })===[]){
            $capacity=max(array_map(static function (Container $container): int {
                return $container->maxPayload->ticks;
            },$available));
            if($capacity>0){$required=array_sum(array_map(static function (ItemInstance $item): int {
                return $item->weight()->ticks;
            },$plan['remaining']));$lower=max($lower,intdiv($required+$capacity-1,$capacity));}
        }
        return $lower;
    }
}
