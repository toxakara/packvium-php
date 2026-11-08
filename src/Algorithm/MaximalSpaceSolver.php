<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Config\PackingConfig;
use Packvium\Constraint\{ConstraintSet,PlacementConstraint};
use Packvium\Domain\{AxisAlignedBox,Container,ItemInstance,Point};
use Packvium\Extension\{CandidateScorer,DefaultCandidateScorer};
/**
 * Maximal free spaces plus the common feasibility engine.
 *
 * Candidates are evaluated *at* each space origin. Asking the shared finder for its
 * globally best point and then testing whether it happens to equal a space origin would
 * reject almost every space, which is why the point set is passed explicitly.
 */
final class MaximalSpaceSolver implements SingleContainerSolver
{

    /** @param list<PlacementConstraint> $constraints */
    public function __construct(private array $constraints=[],private ?CandidateScorer $scorer=null){}
    public function name():string{return 'maximal_spaces';}

    public function packOne(Container $container,int $sequence,array $items,PackingConfig $config,SearchStats $stats,Deadline $deadline):SingleContainerSolution
    {
        $constraints=ConstraintSet::defaults($config->minimumSupportRatio,$this->constraints,$config->accessDirections);
        $scorer=$this->scorer??new DefaultCandidateScorer();
        $state=new ContainerState($container,$sequence);
        $obstacleBoxes=array_merge([],...array_map(static fn($o)=>$o->boxes(),$container->obstacles));
        foreach($container->preloaded as $placement)$obstacleBoxes[]=$placement->envelopeBox();
        $spaces=SpaceSubtraction::subtractAll([new Space(new Point(0,0,0),$container->innerDimensions)],$obstacleBoxes,$stats);
        $unplaced=[];
        $batches=GroupBatcher::batches($items);
        foreach($batches as $position=>$batch){
            $snapshot=count($batch)>1?$state->copy():$state;$saved=$spaces;$placed=true;
            try{
                foreach($batch as $item){
                    // An item past a full container reaches no check of its own, so a spent
                    // budget is checked before its node is counted.
                    if($deadline->effortStops())throw new TimeLimitReached('effort budget spent');
                    $stats->searchNodesExpanded++;
                    $chosen=self::choose($state,$spaces,$item,$config,$constraints,$stats,$deadline,$scorer);
                    if($chosen===null){$placed=false;break;}
                    $state=BeamPacker::extended($state,$item,$chosen);
                    $spaces=SpaceSubtraction::subtractAll($spaces,[new AxisAlignedBox($chosen->point,$chosen->envelopeDimensions)],$stats);
                }
            }catch(TimeLimitReached){
                $state=$snapshot;
                $pending=GroupBatcher::flatten(array_slice($batches,$position));
                return new SingleContainerSolution($state,array_merge($unplaced,$pending),false,true);
            }
            if(!$placed){$state=$snapshot;$spaces=$saved;foreach($batch as $item)$unplaced[]=$item;}
        }
        return new SingleContainerSolution($state,$unplaced);
    }

    /** @param list<Space> $spaces @param list<PlacementConstraint> $constraints */
    private static function choose(ContainerState $state,array $spaces,ItemInstance $item,PackingConfig $config,array $constraints,SearchStats $stats,Deadline $deadline,CandidateScorer $scorer):?Candidate
    {
        return CandidateFinder::chooseInSpaces($state,$spaces,$item,$config,$constraints,$stats,$deadline,$scorer);
    }
}
