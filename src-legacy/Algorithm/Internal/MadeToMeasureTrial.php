<?php
declare(strict_types=1);
namespace Packvium\Algorithm\Internal;

use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container,ItemInstance,MadeToMeasure};
use Packvium\Extension\{ContainerSelector,DefaultContainerSelector};
use Packvium\Algorithm\{ContainerState,Deadline,SearchStats,SingleContainerSolution,SingleContainerSolver,TimeLimitReached};
use Packvium\Constraint\VolumeReserve;
use Packvium\Support\BigInt;

/**
 * Trials of made-to-measure boxes, loaded only for a made-to-measure container. A part of `SolverOrchestrator` kept in its own file so PHP compiles it only when a
 * request needs it: without opcache every request compiles what it loads (1.9.0 release
 * frontier). `$call` reaches the orchestrator's own private methods.
 *
 * @internal
 */
final class MadeToMeasureTrial
{
    /**
     * Try each solving envelope in ascending order and keep the first that places every item;
     * failing that, the trial this round's own key prefers, the smaller rung on a tie.
     *
     * At most four solves, two trial states alive. A compact lattice cannot describe a box cut
     * to the achieved extent, so the rung solves always report coordinates (D12), and a rung
     * choice is a heuristic, so the trial is never exhaustive (D13).
     *
     * @param list<ItemInstance> $items
     * @return array{0:SingleContainerSolution,1:int}
     */
    public static function madeToMeasureTrial(\Closure $call,?ContainerSelector $containerSelector,SingleContainerSolver $solver,Container $container,int $sequence,array $items,PackingConfig $config,SearchStats $stats,Deadline $deadline):array
    {
        $machine=$container->madeToMeasure;
        $maximum=[$container->innerDimensions->length->ticks,$container->innerDimensions->width->ticks,$container->innerDimensions->height->ticks];
        $rungs=$machine->solvingEnvelopes($maximum,MadeToMeasure::instanceEdges($items,$config->clearance->ticks));
        $rungConfig=$config->requirePlacementCoordinates?$config:$call('withCoordinates',$config);
        $selector=$containerSelector??new DefaultContainerSelector();
        $best=null;$bestKey=null;$last=null;$stopped=false;$trials=0;
        foreach($rungs as $rung){
            try{$one=$solver->packOne($container->withEnvelope($rung),$sequence,$items,$rungConfig,$stats,$deadline);}
            catch(TimeLimitReached $limit){if($best===null)throw $limit;$stopped=true;break;}
            $trials++;
            $stopped=$one->timeLimitReached;
            $last=$one;
            if($one->state->placementCount()>0){
                $trial=$call('fabricated',$container,$one);
                if(!self::keepsReserve($trial->state))$trial=$call('fabricated',$container,$one,$rung);
                if($one->state->placementCount()===count($items))return [$trial,$trials];
                $key=$call('roundScore',$trial,$items,$config,$selector);
                if($best===null||($key<=>$bestKey)<0){$best=$trial;$bestKey=$key;}
            }
            if($stopped||$deadline->expired()){$stopped=true;break;}
        }
        $chosen=$best??$last;
        return [new SingleContainerSolution($chosen->state,$chosen->unpacked,$chosen->exhaustive,$chosen->timeLimitReached||$stopped,$chosen->dominantLattice),$trials];
    }

    /**
     * Whether a fabricated box keeps its void-fill reserve. The reserve is a share of the
     * box that ships, so a box cut tight to its load can break a reserve the rung kept: the trial
     * then cuts the rung itself, on the grid by construction and solved under its reserve.
     */
    private static function keepsReserve(ContainerState $state):bool
    {
        $container=$state->container;
        return $container->voidFillReserveRatio===0.0||BigInt::compare($state->usedVolume,VolumeReserve::usable($container))<=0;
    }
}
