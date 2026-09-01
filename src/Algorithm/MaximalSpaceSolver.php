<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Config\PackingConfig;
use Packvium\Constraint\{ConstraintSet,PlacementConstraint};
use Packvium\Domain\{AxisAlignedBox,Container,Dimensions,ItemInstance,Point};
use Packvium\Extension\{CandidateScorer,DefaultCandidateScorer};
use Packvium\Support\StableSorter;
use Packvium\Unit\Length;
/**
 * Maximal free spaces plus the common feasibility engine.
 *
 * Candidates are evaluated *at* each space origin. Asking the shared finder for its
 * globally best point and then testing whether it happens to equal a space origin would
 * reject almost every space, which is why the point set is passed explicitly.
 */
final class MaximalSpaceSolver implements SingleContainerSolver
{
    /**
     * Adversarial item mixes can carve far more surviving maximal spaces than any real
     * request needs -- growth is roughly linear in placement count once size variety
     * defeats the dominance filter below, and every extra space multiplies the
     * per-item choose() scan, the carve and subtractAll's O(new * s) dominance check.
     * Past this many surviving spaces, the smallest (least likely to fit a future
     * item) are dropped first; well under any request this library's tests or
     * fixtures exercise.
     */
    public const MAX_MAXIMAL_SPACES = 256;

    /** @param list<PlacementConstraint> $constraints */
    public function __construct(private array $constraints=[],private ?CandidateScorer $scorer=null){}
    public function name():string{return 'maximal_spaces';}

    public function packOne(Container $container,int $sequence,array $items,PackingConfig $config,SearchStats $stats,Deadline $deadline):SingleContainerSolution
    {
        $constraints=ConstraintSet::defaults($config->minimumSupportRatio,$this->constraints,$config->accessDirections);
        $scorer=$this->scorer??new DefaultCandidateScorer();
        $state=new ContainerState($container,$sequence);
        $obstacleBoxes=array_merge([],...array_map(static fn($o)=>$o->boxes(),$container->obstacles));
        $spaces=self::subtractAll([new Space(new Point(0,0,0),$container->innerDimensions)],$obstacleBoxes,$stats);
        $unplaced=[];
        $batches=GroupBatcher::batches($items);
        foreach($batches as $position=>$batch){
            $snapshot=$state->copy();$saved=$spaces;$placed=true;
            try{
                foreach($batch as $item){
                    $stats->searchNodesExpanded++;
                    $chosen=self::choose($state,$spaces,$item,$config,$constraints,$stats,$deadline,$scorer);
                    if($chosen===null){$placed=false;break;}
                    $state=BeamPacker::extended($state,$item,$chosen);
                    $spaces=self::subtractAll($spaces,[new AxisAlignedBox($chosen->point,$chosen->envelopeDimensions)],$stats);
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
        foreach($spaces as $space){
            $deadline->check();
            foreach(CandidateFinder::find($state,$item,$config,$constraints,$stats,$deadline,$scorer,null,[$space->origin]) as $candidate)
                if($candidate->envelopeDimensions->fitsInside($space->dimensions))return $candidate;
        }
        return null;
    }

    /** The six maximal slabs of $space that survive carving $box out of it. @return list<Space> */
    private static function subtract(Space $space,AxisAlignedBox $box):array
    {
        $source=new AxisAlignedBox($space->origin,$space->dimensions);
        if(!$source->intersects($box))return [$space];
        $out=[];
        $push=static function(int $x1,int $y1,int $z1,int $x2,int $y2,int $z2)use(&$out):void{
            if($x2>$x1&&$y2>$y1&&$z2>$z1)
                $out[]=new Space(new Point($x1,$y1,$z1),new Dimensions(new Length($x2-$x1),new Length($y2-$y1),new Length($z2-$z1)));
        };
        $push($source->origin->x,$source->origin->y,$source->origin->z,min($source->x2(),$box->origin->x),$source->y2(),$source->z2());
        $push(max($source->origin->x,$box->x2()),$source->origin->y,$source->origin->z,$source->x2(),$source->y2(),$source->z2());
        $push($source->origin->x,$source->origin->y,$source->origin->z,$source->x2(),min($source->y2(),$box->origin->y),$source->z2());
        $push($source->origin->x,max($source->origin->y,$box->y2()),$source->origin->z,$source->x2(),$source->y2(),$source->z2());
        $push($source->origin->x,$source->origin->y,$source->origin->z,$source->x2(),$source->y2(),min($source->z2(),$box->origin->z));
        $push($source->origin->x,$source->origin->y,max($source->origin->z,$box->z2()),$source->x2(),$source->y2(),$source->z2());
        return $out;
    }

    /**
     * Precondition: $spaces is containment-free -- every call site passes either a
     * single whole space or this method's own output. A survivor untouched by every
     * carve can therefore neither contain nor be contained by another survivor, and a
     * new slab cannot contain it either (the slab's own parent could not), so only
     * new slabs need the dominance check below: O(new * s) instead of O(s^2).
     *
     * @param list<Space> $spaces @param list<AxisAlignedBox> $boxes @return list<Space>
     */
    public static function subtractAll(array $spaces,array $boxes,?SearchStats $stats=null):array
    {
        $current=array_map(static fn(Space $space):array=>[$space,false],$spaces);
        foreach($boxes as $box){
            if($stats!==null)$stats->spacePartitions+=count($current);
            $next=[];
            foreach($current as [$space,$isNew]){
                if(!(new AxisAlignedBox($space->origin,$space->dimensions))->intersects($box)){$next[]=[$space,$isNew];continue;}
                foreach(self::subtract($space,$box) as $part)$next[]=[$part,true];
            }
            $current=$next;
        }
        $unique=[];
        foreach($current as $pair){
            [$space,]=$pair;
            $d=$space->dimensions;
            $unique["{$space->origin->x}:{$space->origin->y}:{$space->origin->z}:{$d->length->ticks}:{$d->width->ticks}:{$d->height->ticks}"]=$pair;
        }
        // descendingVolumeKey parses BigInt chunks; one key per space instead of one
        // per stable-usort comparison, with the ordering unchanged.
        $kept=StableSorter::sortBy(array_values($unique),static fn(array $pair):array=>array_merge([$pair[0]->origin->z,$pair[0]->origin->y,$pair[0]->origin->x],$pair[0]->dimensions->descendingVolumeKey()));
        // Without this filter every placement multiplies the space list and the solver
        // degenerates into an exponential scan of overlapping duplicates.
        $extents=array_map(static fn(array $pair):array=>[
            $pair[0]->origin->x,$pair[0]->origin->y,$pair[0]->origin->z,
            $pair[0]->origin->x+$pair[0]->dimensions->length->ticks,
            $pair[0]->origin->y+$pair[0]->dimensions->width->ticks,
            $pair[0]->origin->z+$pair[0]->dimensions->height->ticks,
        ],$kept);
        $result=[];
        foreach($kept as $index=>[$space,$isNew]){
            if($isNew){
                [$x1,$y1,$z1,$x2,$y2,$z2]=$extents[$index];
                $dominated=false;
                foreach($extents as $other=>$extent){
                    if($other===$index)continue;
                    if($extent[0]<=$x1&&$extent[1]<=$y1&&$extent[2]<=$z1&&$x2<=$extent[3]&&$y2<=$extent[4]&&$z2<=$extent[5]){$dominated=true;break;}
                }
                if($dominated)continue;
            }
            $result[]=$space;
        }
        if(count($result)>self::MAX_MAXIMAL_SPACES){
            $byVolume=StableSorter::sortBy($result,static fn(Space $space):array=>$space->dimensions->descendingVolumeKey());
            $result=StableSorter::sortBy(
                array_slice($byVolume,0,self::MAX_MAXIMAL_SPACES),
                static fn(Space $space):array=>[$space->origin->z,$space->origin->y,$space->origin->x],
            );
        }
        return $result;
    }
}
