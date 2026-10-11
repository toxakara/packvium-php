<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Config\PackingConfig;
use Packvium\Constraint\PlacementConstraint;
use Packvium\Domain\{Container,ItemInstance,Nesting,Placement};
use Packvium\Extension\CandidateScorer;
use Packvium\Support\{BigInt,StableSorter};
final class BeamPacker
{
    /**
     * Greedy placement widened into a beam by maxCandidatesPerItem.
     *
     * A width of one is plain best-fit greedy; wider settings keep that many partial
     * packings alive so an early locally-best corner cannot dictate the whole layout.
     *
     * @param list<ItemInstance> $items @param list<PlacementConstraint> $constraints
     */
    public static function pack(Container $container,int $sequence,array $items,PackingConfig $config,array $constraints,SearchStats $stats,Deadline $deadline,CandidateScorer $scorer):SingleContainerSolution
    {
        $branchWidth=max(1,$config->maxCandidatesPerItem);
        $width=max(1,$config->containerPlanBeamWidth);
        if($width===1)return self::packLegacy($container,$sequence,$items,$config,$constraints,$stats,$deadline,$scorer,$branchWidth);
        $byVolume=self::ranksByVolume($config);
        $nodeLimit=$width>1?$config->containerPlanNodeLimit:null;
        $nodes=0;
        $batches=GroupBatcher::batches($items);
        $initial=new ContainerState($container,$sequence);
        $beam=[[$initial,[]]];
        $greedy=new ContainerState($container,$sequence);$greedyUnplaced=[];
        foreach($batches as $greedyPosition=>$batch){
            try{$children=self::placeBatch($greedy,$batch,$config,$constraints,$stats,$deadline,$scorer,1);}
            catch(TimeLimitReached){$greedyUnplaced=array_merge($greedyUnplaced,GroupBatcher::flatten(array_slice($batches,$greedyPosition)));break;}
            if($children!==[])$greedy=$children[0];else $greedyUnplaced=array_merge($greedyUnplaced,$batch);
        }
        $incumbent=[$greedy,$greedyUnplaced];
        // nodeKey builds an O(m) placement signature and BigInt chunks; computing it once
        // per node instead of once per usort comparison (and once per incumbent test)
        // changes no ordering: usort is stable and the keys are identical values.
        $incumbentKey=self::nodeKey($incumbent,$byVolume);
        foreach($batches as $position=>$batch){
            $expansions=[];$exhausted=false;
            foreach($beam as [$state,$unplaced]){
                if($nodeLimit!==null&&$nodes>=$nodeLimit){$exhausted=true;break;}
                $nodes++;
                $stats->searchNodesExpanded++;
                try{$children=self::placeBatch($state,$batch,$config,$constraints,$stats,$deadline,$scorer,$branchWidth);}
                catch(TimeLimitReached){$exhausted=true;$children=[];}
                if($children!==[]){foreach($children as $child)$expansions[]=[$child,$unplaced];$expansions[]=[$state,array_merge($unplaced,$batch)];}
                else $expansions[]=[$state,array_merge($unplaced,$batch)];
            }
            $future=GroupBatcher::flatten(array_slice($batches,$position+1));
            $futureCount=count($future);
            foreach($expansions as [$state,$unplaced]){
                $unplacedCount=count($unplaced)+$futureCount;
                $descendingUsed=self::descending($state->usedVolume);
                // A worse leading component loses whatever follows; skip building a signature that cannot win.
                if($byVolume?$descendingUsed>array_slice($incumbentKey,0,count($descendingUsed)):$unplacedCount>$incumbentKey[0])continue;
                $candidateKey=$byVolume
                    ?array_merge($descendingUsed,$descendingUsed,[$unplacedCount,$state->maxZ,$state->placementSignature()])
                    :array_merge([$unplacedCount,$unplacedCount,-count($state->placements),$state->maxZ],$descendingUsed,[$state->placementSignature()]);
                if($candidateKey<$incumbentKey){$incumbent=[$state,array_merge($unplaced,$future)];$incumbentKey=$candidateKey;}
            }
            if($expansions===[])break;
            $futureInfo=self::precomputeFuture($future,$container->maxPayload?->ticks);
            $beam=array_slice(StableSorter::sortBy($expansions,static fn(array $node):array=>self::nodeKeyPrecomputed($node,$futureInfo,$byVolume)),0,$width);
            if($exhausted){
                [$state,$unplaced]=$incumbent;
                return new SingleContainerSolution($state,$unplaced,false,$deadline->expired());
            }
        }
        $ordered=StableSorter::sortBy($beam,static fn(array $node):array=>self::nodeKey($node,$byVolume));
        $completed=$ordered[0]??null;
        [$state,$unplaced]=$completed!==null&&self::nodeKey($completed,$byVolume)<$incumbentKey?$completed:$incumbent;
        return new SingleContainerSolution($state,$unplaced);
    }

    /**
     * Historical fast/balanced candidate beam. The quality-only anytime seed in
     * pack() performs a second rollout to retain a complete incumbent; at plan width
     * one that only doubles work and trace events, so the low-latency path stays here.
     *
     * @param list<ItemInstance> $items @param list<PlacementConstraint> $constraints
     */
    private static function packLegacy(Container $container,int $sequence,array $items,PackingConfig $config,array $constraints,SearchStats $stats,Deadline $deadline,CandidateScorer $scorer,int $width):SingleContainerSolution
    {
        $batches=GroupBatcher::batches($items);
        $byVolume=self::ranksByVolume($config);
        $beam=[[new ContainerState($container,$sequence),[]]];
        foreach($batches as $position=>$batch){
            $expansions=[];$exhausted=false;
            foreach($beam as [$state,$unplaced]){
                $stats->searchNodesExpanded++;
                try{$children=self::placeBatch($state,$batch,$config,$constraints,$stats,$deadline,$scorer,$width);}
                catch(TimeLimitReached){$exhausted=true;$children=[];}
                if($children!==[]){foreach($children as $child)$expansions[]=[$child,$unplaced];}
                else $expansions[]=[$state,array_merge($unplaced,$batch)];
            }
            $beam=array_slice(StableSorter::sortBy($expansions,static fn(array $node):array=>self::nodeKey($node,$byVolume)),0,$width);
            if($exhausted){
                [$state,$unplaced]=$beam[0];
                $pending=GroupBatcher::flatten(array_slice($batches,$position+1));
                return new SingleContainerSolution($state,array_merge($unplaced,$pending),false,true);
            }
        }
        [$state,$unplaced]=$beam[0];
        return new SingleContainerSolution($state,$unplaced);
    }

    /**
     * States reachable by placing every member of $batch, best first. Empty if it cannot fit.
     *
     * @param list<ItemInstance> $batch @param list<PlacementConstraint> $constraints @return list<ContainerState>
     */
    public static function placeBatch(ContainerState $state,array $batch,PackingConfig $config,array $constraints,SearchStats $stats,Deadline $deadline,CandidateScorer $scorer,?int $width):array
    {
        if(count($batch)===1){
            $out=[];
            foreach(CandidateFinder::find($state,$batch[0],$config,$constraints,$stats,$deadline,$scorer,$width) as $candidate)
                $out[]=self::extended($state,$batch[0],$candidate);
            return $out;
        }
        $working=$state;
        foreach($batch as $item){
            $candidates=CandidateFinder::find($working,$item,$config,$constraints,$stats,$deadline,$scorer,1);
            if($candidates===[])return [];
            $working=self::extended($working,$item,$candidates[0]);
        }
        return [$working];
    }

    public static function extended(ContainerState $state,ItemInstance $item,Candidate $candidate):ContainerState
    {
        $child=$state->copy();
        $child->add(new Placement($item,$candidate->position,$candidate->rotation,$candidate->dimensions,$candidate->point,$candidate->envelopeDimensions,SupportCalculator::ratio($state,$item,$candidate)));
        return $child;
    }

    /** @param list<string> $cumulativeVolumes */
    private static function countWithVolumeCapacity(array $cumulativeVolumes,string $capacity):int
    {
        return self::countWithin($cumulativeVolumes,static fn(string $used):bool=>BigInt::compare($used,$capacity)<=0);
    }

    /** @param list<?int> $cumulativeWeights */
    private static function countWithWeightCapacity(array $cumulativeWeights,int $capacity):int
    {
        return self::countWithin($cumulativeWeights,static fn(?int $used):bool=>$used!==null&&$used<=$capacity);
    }

    /**
     * How many of the cheapest costs fit, read off their prefix sums. Costs are
     * non-negative, so the sums never decrease and the count is one binary search:
     * O(log f) per node instead of the O(f) walk.
     *
     * @template T
     * @param list<T> $cumulative
     * @param callable(T):bool $fits
     */
    private static function countWithin(array $cumulative,callable $fits):int
    {
        $low=0;$high=count($cumulative);
        while($low<$high){
            $middle=intdiv($low+$high,2);
            if($fits($cumulative[$middle]))$low=$middle+1;else $high=$middle;
        }
        return $low;
    }

    /**
     * @param list<ItemInstance> $future
     * @return array{count:int,volumes:?list<string>,weights:?list<?int>}
     */
    private static function precomputeFuture(array $future,?int $maxPayloadTicks):array
    {
        $count=count($future);
        if($count===0)return ['count'=>0,'volumes'=>null,'weights'=>null];
        $hasNesting=false;
        foreach($future as $item){
            if($item->item->nestingHeight!==null){$hasNesting=true;break;}
        }
        $volumes=null;
        if(!$hasNesting){
            // Occupied, not declared: the free space these are compared with is occupied
            // volume, and two wedges each declaring the whole crate both fit in it.
            $volumes=array_map(static fn(ItemInstance $item):string=>Nesting::itemOccupiedVolume($item),$future);
            usort($volumes,static fn(string $a,string $b):int=>BigInt::compare($a,$b));
            $used='0';
            foreach($volumes as $index=>$volume)$volumes[$index]=$used=BigInt::add($used,$volume);
        }
        $weights=null;
        if($maxPayloadTicks!==null){
            $weights=array_map(static fn(ItemInstance $item):int=>$item->weight()->ticks,$future);
            sort($weights,SORT_NUMERIC);
            // Null means a prefix exceeds the payload. Compare before adding so neither
            // the sum nor payload + 1 can overflow a native int, even at PHP_INT_MAX.
            $used=0;
            foreach($weights as $index=>$weight){
                if($used===null||$weight>$maxPayloadTicks-$used){$used=null;}
                else $used+=$weight;
                $weights[$index]=$used;
            }
        }
        return ['count'=>$count,'volumes'=>$volumes,'weights'=>$weights];
    }

    private static function unpackedLowerBoundPrecomputed(ContainerState $state,int $unplacedCount,array $futureInfo):int
    {
        $futureCount=$futureInfo['count'];
        if($futureCount===0)return $unplacedCount;
        $possible=$futureCount;
        if($futureInfo['volumes']!==null){
            $capacity=BigInt::subtract($state->container->innerDimensions->volumeString(),$state->usedVolume);
            $possible=min($possible,self::countWithVolumeCapacity($futureInfo['volumes'],$capacity));
        }
        if($futureInfo['weights']!==null&&$state->container->maxPayload!==null){
            $capacity=max(0,$state->container->maxPayload->ticks-$state->payloadTicks);
            $possible=min($possible,self::countWithWeightCapacity($futureInfo['weights'],$capacity));
        }
        return $unplacedCount+$futureCount-$possible;
    }

    private static function ranksByVolume(PackingConfig $config):bool
    {
        return $config->objective==='maximum_volume';
    }

    /** @return list<int> Chunks negated, so ascending array order means the larger value first. */
    private static function descending(string $value):array
    {
        return array_map(static fn(int $c):int=>-$c,BigInt::chunks($value));
    }

    /**
     * An upper bound on the volume this node can still end with: what is placed, plus the
     * future items' total volume capped by the free space. For nesting, nominal volumes
     * are not additive, so the free space alone is the bound.
     */
    private static function reachableVolume(ContainerState $state,array $futureInfo):string
    {
        if($futureInfo['count']===0)return $state->usedVolume;
        $free=BigInt::subtract($state->container->innerDimensions->volumeString(),$state->usedVolume);
        $volumes=$futureInfo['volumes'];
        $future=$volumes===null?$free:$volumes[count($volumes)-1];
        return BigInt::add($state->usedVolume,BigInt::compare($future,$free)<0?$future:$free);
    }

    private static function signature(ContainerState $state):string
    {
        return $state->placementSignature();
    }

    private static function nodeKeyPrecomputed(array $node,array $futureInfo,bool $byVolume=false):array
    {
        [$state,$unplaced]=$node;
        $descendingUsed=self::descending($state->usedVolume);
        if($byVolume)return array_merge(self::descending(self::reachableVolume($state,$futureInfo)),$descendingUsed,[count($unplaced),$state->maxZ,self::signature($state)]);
        return array_merge([self::unpackedLowerBoundPrecomputed($state,count($unplaced),$futureInfo),count($unplaced),-count($state->placements),$state->maxZ],$descendingUsed,[self::signature($state)]);
    }

    /** @param array{0:ContainerState,1:list<ItemInstance>} $node */
    private static function nodeKey(array $node,bool $byVolume=false):array
    {
        return self::nodeKeyPrecomputed($node,['count'=>0,'volumes'=>null,'weights'=>null],$byVolume);
    }
}
