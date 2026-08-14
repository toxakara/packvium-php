<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use InvalidArgumentException;
use Packvium\Config\PackingConfig;
use Packvium\Constraint\{ConstraintSet,PlacementConstraint};
use Packvium\Domain\Container;
use Packvium\Extension\{CandidateScorer,DefaultCandidateScorer};
use Packvium\Support\BigInt;
/**
 * Depth-first branch and bound over whole group batches.
 *
 * The search is exact only for the discrete candidate model and item-count objective;
 * it deliberately does not publish a global optimality claim.
 */
final class ExactSmallSolver implements SingleContainerSolver
{
    /** @param list<PlacementConstraint> $constraints */
    public function __construct(private array $constraints=[],private ?CandidateScorer $scorer=null){}
    public function name():string{return 'exact_small';}

    private static function placedVolume(ContainerState $state):string
    {
        // Sum each item's own physical volume, not ContainerState::$usedVolume: the
        // latter deliberately removes nesting overlap and is a different quantity.
        $volume='0';
        foreach($state->placements as $p)$volume=BigInt::add($volume,$p->dimensions->volumeString());
        return $volume;
    }

    private static function isBetterValues(int $candidateCount,string $candidateVolume,int $incumbentCount,string $incumbentVolume):bool
    {
        if($candidateCount!==$incumbentCount)return $candidateCount>$incumbentCount;
        return BigInt::compare($candidateVolume,$incumbentVolume)>0;
    }

    /**
     * True if `$candidate` should replace `$incumbent` as a best-so-far.
     *
     * More items placed always wins; among equal counts, higher placed volume wins.
     * Without the second key, the search never looked past item count at all, so a
     * longer-running search reaching an equal-count-but-worse-arranged state later in
     * traversal order would silently replace a better-arranged one found earlier --
     * how more search time made the chosen packing *worse* under the outer objective
     * despite placing no fewer items. The two extra keys the outer scorer
     * cares about (unused volume, stack height) were never considered here at all.
     */
    public static function isBetterState(ContainerState $candidate,ContainerState $incumbent):bool
    {
        return self::isBetterValues(
            count($candidate->placements),self::placedVolume($candidate),
            count($incumbent->placements),self::placedVolume($incumbent),
        );
    }

    public function packOne(Container $container,int $sequence,array $items,PackingConfig $config,SearchStats $stats,Deadline $deadline):SingleContainerSolution
    {
        if(count($items)>$config->exactItemLimit)throw new InvalidArgumentException('Exact-small item limit exceeded');
        $constraints=ConstraintSet::defaults($config->minimumSupportRatio,$this->constraints);
        $scorer=$this->scorer??new DefaultCandidateScorer();
        $batches=GroupBatcher::batches($items);
        $batchVolumes=[];
        foreach($batches as $batch){
            $volume='0';
            foreach($batch as $item)$volume=BigInt::add($volume,$item->dimensions()->volumeString());
            $batchVolumes[]=$volume;
        }
        $suffixVolumes=array_fill(0,count($batches)+1,'0');
        for($index=count($batches)-1;$index>=0;$index--)
            $suffixVolumes[$index]=BigInt::add($batchVolumes[$index],$suffixVolumes[$index+1]);
        $best=new ContainerState($container,$sequence);
        $bestVolume='0';
        $total=count($items);

        $dfs=function(int $index,ContainerState $state,int $reachable,string $stateVolume)use(&$dfs,&$best,&$bestVolume,$batches,$batchVolumes,$suffixVolumes,$config,$constraints,$stats,$deadline,$scorer,$total):void{
            if($deadline->expired())return;
            $stats->searchNodesExpanded++;
            $stateCount=count($state->placements);
            $bestCount=count($best->placements);
            if(self::isBetterValues($stateCount,$stateVolume,$bestCount,$bestVolume)){
                $best=$state;
                $bestVolume=$stateVolume;
            }
            if($index>=count($batches))return;
            $potentialCount=$stateCount+$reachable;
            $bestCount=count($best->placements);
            if($potentialCount<$bestCount)return;
            if($potentialCount===$bestCount&&BigInt::compare(BigInt::add($stateVolume,$suffixVolumes[$index]),$bestVolume)<=0)return;
            $batch=$batches[$index];
            $remaining=$reachable-count($batch);
            foreach(BeamPacker::placeBatch($state,$batch,$config,$constraints,$stats,$deadline,$scorer,null) as $child){
                $dfs($index+1,$child,$remaining,BigInt::add($stateVolume,$batchVolumes[$index]));
                if(count($best->placements)===$total||$deadline->expired())return;
            }
            $dfs($index+1,$state,$remaining,$stateVolume);
        };

        try{$dfs(0,new ContainerState($container,$sequence),$total,'0');}
        catch(TimeLimitReached){}

        $packed=[];
        foreach($best->placements as $p)$packed[$p->instance->id()]=true;
        // This search proves only the maximum item count reachable through the
        // generated discrete points. It stops at the first full packing and does not
        // minimize the rest of the public objective vector, so it cannot claim global
        // optimality.
        return new SingleContainerSolution($best,array_values(array_filter($items,static fn($i)=>!isset($packed[$i->id()]))),false,$deadline->expired());
    }
}
