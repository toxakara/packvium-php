<?php
declare(strict_types=1);
namespace Packvium\Algorithm;

use Packvium\Config\PackingConfig;
use Packvium\Constraint\PlacementConstraint;
use Packvium\Domain\{AxisAlignedBox,Container,Dimensions,ItemInstance,Placement,Point};
use Packvium\Extension\CandidateScorer;
use Packvium\Support\BigInt;
use Packvium\Unit\Length;

/**
 * Deterministic solid single-type block search for unrestricted carton loading.
 *
 * Candidate enumeration is O(B*S*T*R*X*Y) time and O(S+n) space. B is committed
 * blocks, S the capped maximal-space count, T declared item types, R unique
 * rotations, and X/Y the block grid extents. Both Deadline and
 * containerPlanNodeLimit bound the product explicitly.
 */
final class HomogeneousBlockSolver implements SingleContainerSolver
{
    /** @param list<PlacementConstraint> $constraints */
    public function __construct(private array $constraints=[],?CandidateScorer $scorer=null){}

    public function name():string{return 'homogeneous_blocks';}
    public function orderInsensitive():bool{return true;}

    public function packOne(Container $container,int $sequence,array $items,PackingConfig $config,SearchStats $stats,Deadline $deadline):SingleContainerSolution
    {
        if(!$this->supports($container,$items))
            return (new ExtremePointSolver($this->constraints))->packOne($container,$sequence,$items,$config,$stats,$deadline);
        $best=null;$reached=false;
        foreach(['count','volume'] as $mode){
            if($deadline->expired()){$reached=true;break;}
            $solution=$this->packMode($container,$sequence,$items,$config,$stats,$deadline,$mode);
            $reached=$reached||$solution->timeLimitReached;
            if($best===null||$this->solutionKey($solution)<$this->solutionKey($best))$best=$solution;
        }
        if($best===null)return new SingleContainerSolution(new ContainerState($container,$sequence),$items,false,$reached);
        return new SingleContainerSolution($best->state,$best->unpacked,false,$reached);
    }

    private function supports(Container $container,array $items):bool
    {
        if($this->constraints!==[]||$container->obstacles!==[]||$container->axles!==null||$container->tagLimits!==[]||$container->maxStackDensity!==null||$container->voidFillReserveRatio>0)return false;
        foreach($items as $instance){
            $item=$instance->item;
            if($item->group!==null||$item->tags!==[]||$item->incompatibleTags!==[]||$item->eligibleContainerTags!==[]||!$item->stackable||$item->mustBeOnFloor||$item->maxTopLoad!==null||$item->maxStackedItems!==null||$item->minimumSupportRatio!=0.0||!in_array($item->groundContactRule,[null,'free'],true)||$item->nestingHeight!==null||$item->stopIndex!==null)return false;
        }
        return true;
    }

    private function solutionKey(SingleContainerSolution $solution):array
    {
        $signature=[];
        foreach($solution->state->placements as $placement)$signature[]=$placement->instance->id().'@'.$placement->envelopeOrigin->x.','.$placement->envelopeOrigin->y.','.$placement->envelopeOrigin->z;
        return [count($solution->unpacked),...array_map(static fn(int $chunk):int=>-$chunk,BigInt::chunks($solution->state->usedVolume)),$solution->state->maxZ,implode('|',$signature)];
    }

    private function packMode(Container $container,int $sequence,array $items,PackingConfig $config,SearchStats $stats,Deadline $deadline,string $mode):SingleContainerSolution
    {
        $state=new ContainerState($container,$sequence);
        $spaces=[new Space(new Point(0,0,0),$container->innerDimensions)];
        $remaining=[];
        foreach($items as $instance)$remaining[$instance->item->id][]=$instance;
        ksort($remaining,SORT_STRING);
        $nodes=0;$reached=false;
        while($spaces!==[]&&array_sum(array_map('count',$remaining))>0){
            $best=null;
            foreach($spaces as $space){
                $spaceVolume=$space->dimensions->volumeString();
                foreach($remaining as $itemId=>$available){
                    if($available===[])continue;
                    $prototype=$available[0];$capacity=count($available);
                    if($container->maxItems!==null)$capacity=min($capacity,$container->maxItems-$state->placementCount());
                    if($container->maxPayload!==null&&$prototype->weight()->ticks>0)$capacity=min($capacity,intdiv($container->maxPayload->ticks-$state->payloadTicks,$prototype->weight()->ticks));
                    if($capacity<=0)continue;
                    foreach($prototype->dimensions()->uniqueRotations($prototype->item->allowedRotations) as [$rotation,$physical]){
                        $envelope=$config->clearance->ticks?$physical->expand($config->clearance):$physical;
                        $maximumX=intdiv($space->dimensions->length->ticks,$envelope->length->ticks);
                        $maximumY=intdiv($space->dimensions->width->ticks,$envelope->width->ticks);
                        $maximumZ=intdiv($space->dimensions->height->ticks,$envelope->height->ticks);
                        for($nx=1;$nx<=min($maximumX,$capacity);$nx++){
                            $maximumNy=min($maximumY,intdiv($capacity,$nx));
                            for($ny=1;$ny<=$maximumNy;$ny++){
                                if($nodes>=$config->containerPlanNodeLimit||$deadline->expired()){$reached=true;break 5;}
                                $nodes++;$stats->searchNodesExpanded++;
                                $nz=min($maximumZ,intdiv($capacity,$nx*$ny));
                                if($nz<=0)continue;
                                $count=$nx*$ny*$nz;
                                $usedVolume=BigInt::multiply($count,$physical->volumeString());
                                $spaceFill=(int)BigInt::divide(BigInt::multiply($usedVolume,1_000_000),$spaceVolume);
                                $volumeKey=array_map(static fn(int $chunk):int=>-$chunk,BigInt::chunks($usedVolume));
                                $lead=$mode==='count'?[-$count,-$spaceFill,...$volumeKey]:[...$volumeKey,-$spaceFill,-$count];
                                $score=[...$lead,$space->origin->z,$space->origin->y,$space->origin->x,$itemId,$rotation->value,$nx,$ny,$nz];
                                $candidate=compact('space','itemId','rotation','physical','envelope','nx','ny','nz','count','usedVolume','score');
                                if($best===null||$score<$best['score'])$best=$candidate;
                            }
                        }
                    }
                }
            }
            if($best===null||$reached)break;
            $chosen=array_slice($remaining[$best['itemId']],0,$best['count']);
            $remaining[$best['itemId']]=array_slice($remaining[$best['itemId']],$best['count']);
            $clearance=$config->clearance->ticks;$index=0;
            for($z=0;$z<$best['nz'];$z++)for($y=0;$y<$best['ny'];$y++)for($x=0;$x<$best['nx'];$x++){
                $point=new Point($best['space']->origin->x+$x*$best['envelope']->length->ticks,$best['space']->origin->y+$y*$best['envelope']->width->ticks,$best['space']->origin->z+$z*$best['envelope']->height->ticks);
                $position=new Point($point->x+$clearance,$point->y+$clearance,$point->z+$clearance);
                $state->addDirect(new Placement($chosen[$index++],$position,$best['rotation'],$best['physical'],$point,$best['envelope'],1.0));
                $stats->candidatesEvaluated++;$stats->placementsAttempted++;
            }
            $blockDimensions=new Dimensions(new Length($best['nx']*$best['envelope']->length->ticks),new Length($best['ny']*$best['envelope']->width->ticks),new Length($best['nz']*$best['envelope']->height->ticks));
            $spaces=MaximalSpaceSolver::subtractAll($spaces,[new AxisAlignedBox($best['space']->origin,$blockDimensions)],$stats);
        }
        $unpacked=[];foreach($remaining as $available)foreach($available as $instance)$unpacked[]=$instance;
        return new SingleContainerSolution($state,$unpacked,false,$reached||$deadline->expired());
    }
}
