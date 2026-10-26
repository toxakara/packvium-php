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
     * @var list<PlacementConstraint>
     */
    private $constraints = [];
    /**
     * @var \Packvium\Extension\CandidateScorer|null
     */
    private $scorer;
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
    /** Stands in for the six-chunk volume key of a space whose origin no other space shares. */
    private const UNSHARED_ORIGIN_TAIL=[0,0,0,0,0,0];

    /** @param list<PlacementConstraint> $constraints */
    public function __construct(array $constraints=[], ?CandidateScorer $scorer=null)
    {
        $this->constraints = $constraints;
        $this->scorer = $scorer;
    }
    public function name():string{return 'maximal_spaces';}

    public function packOne(Container $container,int $sequence,array $items,PackingConfig $config,SearchStats $stats,Deadline $deadline):SingleContainerSolution
    {
        $constraints=ConstraintSet::defaults($config->minimumSupportRatio,$this->constraints,$config->accessDirections);
        $scorer=$this->scorer??new DefaultCandidateScorer();
        $state=new ContainerState($container,$sequence);
        $obstacleBoxes=array_merge([],...array_map(static function ($o) {
            return $o->boxes();
        },$container->obstacles));
        foreach($container->preloaded as $placement)$obstacleBoxes[]=$placement->envelopeBox();
        $spaces=self::subtractAll([new Space(new Point(0,0,0),$container->innerDimensions)],$obstacleBoxes,$stats);
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
                    $spaces=self::subtractAll($spaces,[new AxisAlignedBox($chosen->point,$chosen->envelopeDimensions)],$stats);
                }
            }catch(TimeLimitReached $exception){
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

    /**
     * The six maximal slabs of an extent that survive carving a box extent out of it, as
     * extents, empty ones dropped.
     *
     * @param array{int,int,int,int,int,int} $s @param array{int,int,int,int,int,int} $b
     * @return list<array{int,int,int,int,int,int}>
     */
    private static function slabs(array $s,array $b):array
    {
        [$x1,$y1,$z1,$x2,$y2,$z2]=$s;
        $out=[];
        foreach([
            [$x1,$y1,$z1,min($x2,$b[0]),$y2,$z2],
            [max($x1,$b[3]),$y1,$z1,$x2,$y2,$z2],
            [$x1,$y1,$z1,$x2,min($y2,$b[1]),$z2],
            [$x1,max($y1,$b[4]),$z1,$x2,$y2,$z2],
            [$x1,$y1,$z1,$x2,$y2,min($z2,$b[2])],
            [$x1,$y1,max($z1,$b[5]),$x2,$y2,$z2],
        ] as $slab)if($slab[3]>$slab[0]&&$slab[4]>$slab[1]&&$slab[5]>$slab[2])$out[]=$slab;
        return $out;
    }

    /**
     * Precondition: $spaces is containment-free -- every call site passes either a
     * single whole space or this method's own output. A survivor untouched by every
     * carve can therefore neither contain nor be contained by another survivor, and a
     * new slab cannot contain it either (the slab's own parent could not), so only
     * new slabs need the dominance check below: O(new * s) instead of O(s^2).
     *
     * Slabs stay integer extents until they survive: a `Space` is built only for a new
     * slab that is kept, and the order is one native multi-column sort.
     *
     * @param list<Space> $spaces @param list<AxisAlignedBox> $boxes @return list<Space>
     */
    public static function subtractAll(array $spaces,array $boxes,?SearchStats $stats=null):array
    {
        // [extent, the untouched Space or null for a new slab]
        $current=[];
        foreach($spaces as $space){$o=$space->origin;$d=$space->dimensions;$current[]=[[$o->x,$o->y,$o->z,$o->x+$d->length->ticks,$o->y+$d->width->ticks,$o->z+$d->height->ticks],$space];}
        foreach($boxes as $box){
            if($stats!==null)$stats->spacePartitions+=count($current);
            $b=$box->extent();
            $next=[];
            foreach($current as $entry){
                $e=$entry[0];
                if(!($e[0]<$b[3]&&$e[3]>$b[0]&&$e[1]<$b[4]&&$e[4]>$b[1]&&$e[2]<$b[5]&&$e[5]>$b[2])){$next[]=$entry;continue;}
                foreach(self::slabs($e,$b) as $slab)$next[]=[$slab,null];
            }
            $current=$next;
        }
        // A repeated extent keeps its first position and its last entry.
        $unique=[];
        foreach($current as $entry)$unique[implode(':',$entry[0])]=$entry;
        $unique=array_values($unique);
        return self::survivors(self::sortedByOrigin($unique));
    }

    /**
     * Ordered by origin z, y, x, then -- only between spaces sharing an origin -- largest
     * volume first, then first seen. The volume key is decimal-string arithmetic, so every
     * other space gets a same-length zero tail that no comparison reaches.
     *
     * @param list<array{0:array{int,int,int,int,int,int},1:?Space}> $entries
     * @return list<array{0:array{int,int,int,int,int,int},1:?Space}>
     */
    private static function sortedByOrigin(array $entries):array
    {
        if(count($entries)<2)return $entries;
        $origins=[];
        foreach($entries as [$e])$origins["{$e[0]}:{$e[1]}:{$e[2]}"]=($origins["{$e[0]}:{$e[1]}:{$e[2]}"]??0)+1;
        $columns=array_fill(0,10,[]);
        foreach($entries as $index=>[$e]){
            $tail=$origins["{$e[0]}:{$e[1]}:{$e[2]}"]>1
                ?(new Dimensions(new Length($e[3]-$e[0]),new Length($e[4]-$e[1]),new Length($e[5]-$e[2])))->descendingVolumeKey()
                :self::UNSHARED_ORIGIN_TAIL;
            $columns[0][]=$e[2];$columns[1][]=$e[1];$columns[2][]=$e[0];
            foreach($tail as $chunk=>$value)$columns[3+$chunk][]=$value;
            $columns[9][]=$index;
        }
        array_multisort($columns[0],$columns[1],$columns[2],$columns[3],$columns[4],$columns[5],$columns[6],$columns[7],$columns[8],$columns[9]);
        return array_map(static function (int $index) use ($entries): array {
            return $entries[$index];
        },$columns[9]);
    }

    /**
     * The sorted entries that no other contains, as spaces, capped at MAX_MAXIMAL_SPACES.
     *
     * @param list<array{0:array{int,int,int,int,int,int},1:?Space}> $kept
     * @return list<Space>
     */
    private static function survivors(array $kept):array
    {
        // Without this filter every placement multiplies the space list and the solver
        // degenerates into an exponential scan of overlapping duplicates.
        $extents=array_column($kept,0);
        $result=[];
        foreach($kept as $index=>[$e,$space]){
            if($space!==null){$result[]=$space;continue;}
            if(self::isContained($extents,$index))continue;
            $result[]=new Space(new Point($e[0],$e[1],$e[2]),new Dimensions(new Length($e[3]-$e[0]),new Length($e[4]-$e[1]),new Length($e[5]-$e[2])));
        }
        if(count($result)>self::MAX_MAXIMAL_SPACES){
            $byVolume=StableSorter::sortBy($result,static function (Space $space): array {
                return $space->dimensions->descendingVolumeKey();
            });
            $result=StableSorter::sortBy(
                array_slice($byVolume,0,self::MAX_MAXIMAL_SPACES),
                static function (Space $space): array {
                    return [$space->origin->z,$space->origin->y,$space->origin->x];
                },
            );
        }
        return $result;
    }

    /**
     * Whether another extent contains `$extents[$index]`, for extents ordered by
     * `(z1, y1, x1, -volume)`.
     *
     * A containing extent starts at or before `$extents[$index]` on every axis and has strictly
     * larger volume when origins match, so it always appears at a position `< $index` with
     * `$extent[2] <= $z1` already guaranteed.
     *
     * @param list<array{int,int,int,int,int,int}> $extents
     */
    private static function isContained(array $extents,int $index):bool
    {
        [$x1,$y1,,$x2,$y2,$z2]=$extents[$index];
        for($other=0;$other<$index;++$other){
            $extent=$extents[$other];
            if($extent[3]>=$x2&&$extent[4]>=$y2&&$extent[5]>=$z2&&$extent[0]<=$x1&&$extent[1]<=$y1)return true;
        }
        return false;
    }
}
