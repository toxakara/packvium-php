<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Config\PackingConfig;
use Packvium\Constraint\PlacementConstraint;
use Packvium\Domain\{Container,ItemInstance,Placement};
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
        $nodeLimit=$width>1?$config->containerPlanNodeLimit:null;
        $nodes=0;
        $batches=GroupBatcher::batches($items);
        $initial=new ContainerState($container,$sequence);
        $beam=[[$initial,[]]];
        $greedy=new ContainerState($container,$sequence);$greedyUnplaced=[];
        foreach($batches as $greedyPosition=>$batch){
            try{$children=self::placeBatch($greedy,$batch,$config,$constraints,$stats,$deadline,$scorer,1);}
            catch(TimeLimitReached $exception){$greedyUnplaced=array_merge($greedyUnplaced,GroupBatcher::flatten(array_slice($batches,$greedyPosition)));break;}
            if($children!==[])$greedy=$children[0];else $greedyUnplaced=array_merge($greedyUnplaced,$batch);
        }
        $incumbent=[$greedy,$greedyUnplaced];
        // nodeKey builds an O(m) placement signature, BigInt chunks and the knapsack
        // lower bound; computing it once per node instead of once per usort comparison
        // (and once per incumbent test) changes no ordering: usort is stable and the
        // keys are identical values.
        $incumbentKey=self::nodeKey($incumbent);
        foreach($batches as $position=>$batch){
            $expansions=[];$exhausted=false;
            foreach($beam as [$state,$unplaced]){
                if($nodeLimit!==null&&$nodes>=$nodeLimit){$exhausted=true;break;}
                $nodes++;
                $stats->searchNodesExpanded++;
                try{$children=self::placeBatch($state,$batch,$config,$constraints,$stats,$deadline,$scorer,$branchWidth);}
                catch(TimeLimitReached $exception){$exhausted=true;$children=[];}
                if($children!==[]){foreach($children as $child)$expansions[]=[$child,$unplaced];$expansions[]=[$state,array_merge($unplaced,$batch)];}
                else $expansions[]=[$state,array_merge($unplaced,$batch)];
            }
            $future=GroupBatcher::flatten(array_slice($batches,$position+1));
            foreach($expansions as [$state,$unplaced]){
                $candidate=[$state,array_merge($unplaced,$future)];
                $candidateKey=self::nodeKey($candidate);
                if($candidateKey<$incumbentKey){$incumbent=$candidate;$incumbentKey=$candidateKey;}
            }
            if($expansions===[])break;
            $beam=array_slice(StableSorter::sortBy($expansions,static function (array $node) use ($future): array {
                return self::nodeKey($node,$future);
            }),0,$width);
            if($exhausted){
                [$state,$unplaced]=$incumbent;
                return new SingleContainerSolution($state,$unplaced,false,$deadline->expired());
            }
        }
        $ordered=StableSorter::sortBy($beam,static function (array $node): array {
            return self::nodeKey($node);
        });
        $completed=$ordered[0]??null;
        [$state,$unplaced]=$completed!==null&&self::nodeKey($completed)<$incumbentKey?$completed:$incumbent;
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
        $beam=[[new ContainerState($container,$sequence),[]]];
        foreach($batches as $position=>$batch){
            $expansions=[];$exhausted=false;
            foreach($beam as [$state,$unplaced]){
                $stats->searchNodesExpanded++;
                try{$children=self::placeBatch($state,$batch,$config,$constraints,$stats,$deadline,$scorer,$width);}
                catch(TimeLimitReached $exception){$exhausted=true;$children=[];}
                if($children!==[]){foreach($children as $child)$expansions[]=[$child,$unplaced];}
                else $expansions[]=[$state,array_merge($unplaced,$batch)];
            }
            $beam=array_slice(StableSorter::sortBy($expansions,static function (array $node): array {
                return self::nodeKey($node);
            }),0,$width);
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

    /** @param array{0:ContainerState,1:list<ItemInstance>} $node */
    private static function maximumCountByVolume(array $future,string $capacity):int
    {
        $costs=array_map(static function (ItemInstance $item): string {
            return $item->dimensions()->volumeString();
        },$future);
        usort($costs,static function (string $a, string $b): int {
            return BigInt::compare($a,$b);
        });
        $used='0';$count=0;
        foreach($costs as $cost){$next=BigInt::add($used,$cost);if(BigInt::compare($next,$capacity)>0)break;$used=$next;$count++;}
        return $count;
    }

    /** @param list<ItemInstance> $future */
    private static function unpackedLowerBound(ContainerState $state,array $unplaced,array $future):int
    {
        if($future===[])return count($unplaced);
        $possible=count($future);
        if(!array_filter($future,static function (ItemInstance $item): bool {
            return $item->item->nestingHeight!==null;
        })){
            $capacity=BigInt::subtract($state->container->innerDimensions->volumeString(),$state->usedVolume);
            $possible=min($possible,self::maximumCountByVolume($future,$capacity));
        }
        if($state->container->maxPayload!==null){
            $capacity=max(0,$state->container->maxPayload->ticks-$state->payloadTicks);
            $weights=array_map(static function (ItemInstance $item): int {
                return $item->weight()->ticks;
            },$future);sort($weights,SORT_NUMERIC);
            $used=0;$count=0;foreach($weights as $weight){if($used+$weight>$capacity)break;$used+=$weight;$count++;}
            $possible=min($possible,$count);
        }
        return count($unplaced)+count($future)-$possible;
    }

    private static function nodeKey(array $node,array $future=[]):array
    {
        [$state,$unplaced]=$node;
        $used=$state->usedVolume;$signature=[];
        foreach($state->placements as $p){
            $signature[]=$p->instance->id().'@'.$p->envelopeOrigin->x.','.$p->envelopeOrigin->y.','.$p->envelopeOrigin->z;
        }
        $descendingUsed=array_map(static function (int $c): int {
            return -$c;
        },\Packvium\Support\BigInt::chunks($used));
        return array_merge([self::unpackedLowerBound($state,$unplaced,$future),count($unplaced),-count($state->placements),$state->maxZ],$descendingUsed,[implode('|',$signature)]);
    }
}
