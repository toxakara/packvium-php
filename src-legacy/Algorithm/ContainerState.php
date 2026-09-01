<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Constraint\VolumeReserve;
use Packvium\Domain\{AxisAlignedBox,Container,ItemInstance,LatticeSummary,Nesting,Placement,Point,ShapeType};
use Packvium\Support\BigInt;
/**
 * Placed boxes plus the candidate points they expose.
 *
 * Points are corner points of every solid in the container together with their
 * projections onto the surfaces below, behind and to the left of them. Points are only
 * ever discarded when they fall outside the container or inside a solid — a free point
 * is never pruned for being "dominated", because a lower-left point can be blocked while
 * the point it would dominate is perfectly usable.
 */
final class ContainerState
{
    /**
     * @readonly
     * @var \Packvium\Domain\Container
     */
    public $container;
    /**
     * @readonly
     * @var int
     */
    public $sequence;
    /** @var list<Placement> */ public $placements=[];
    /** @var array<string,Point> */ public $points=[];
    /** @var list<Point> Candidate points retained in deterministic (z,y,x) order. */
    public $orderedPoints=[];
    /** @var list<AxisAlignedBox> */ public $occupied=[];
    /**
     * Plain integer extents of everything solid. The candidate scan tests these millions
     * of times, and recomputing box properties there dominated the search.
     * @var list<array{0:int,1:int,2:int,3:int,4:int,5:int}>
     */
    public $bounds=[];
    /**
     * Parallel to `$bounds`: the rotated hull of that solid, or null where it is an ordinary
     * box. Obstacles are always boxes, so a cuboid-only request never allocates past this
     * list of nulls.
     * @var list<?\Packvium\Domain\HullShape>
     */
    public $hullShapes=[];
    /**
     * @var int
     */
    public $payloadTicks=0;
    /** Exact physical volume, nesting overlap removed. Decimal string avoids overflow.
     * @var string */
    public $usedVolume='0';
    /**
     * @var bool
     */
    public $stackSensitive=false;
    /**
     * @var bool
     */
    public $routeSensitive=false;
    /**
     * @var bool
     */
    public $compressionSensitive=false;
    /**
     * @var int
     */
    public $maxZ=0;
    /**
     * @var \Packvium\Algorithm\SpatialIndex
     */
    public $index;
    /**
     * Set instead of appending to `$placements` when GridSolver's quantity-
     * compression fast path applies -- see `LatticeSummary`.
     * @var \Packvium\Domain\LatticeSummary|null
     */
    public $latticeSummary;
    /** @var list<ItemInstance> */ public $latticeItems=[];
    /**
     * @var string|null
     */
    private $usableVolumeTicks;

    public function __construct(Container $container,int $sequence)
    {
        $this->container = $container;
        $this->sequence = $sequence;
        $d=$container->innerDimensions;
        $this->index=new SpatialIndex($d->length->ticks,$d->width->ticks,$d->height->ticks);
        foreach($container->obstacles as $o)foreach($o->boxes() as $box){
            $this->occupied[]=$box;
            $bound=$box->extent();
            $this->index->add(count($this->bounds),$bound);
            $this->bounds[]=$bound;
            // An obstacle is always a box, so its slot stays null and the parallel arrays
            // start life the same length.
            $this->hullShapes[]=null;
        }
        $this->absorb([new Point(0,0,0)]);
        foreach($this->occupied as $box)$this->absorb($this->exposedPoints($box));
    }

    /** Clone rather than reconstruct: the constructor's point seeding would be discarded. */
    public function copy():self{return clone $this;}

    /**
     * PHP arrays are value types, so `clone` already deep-copies `$bounds`/`$occupied`/
     * `$points` correctly; `$index` is an object, which `clone` only shallow-copies by
     * reference, so without this a forked search branch's insert would corrupt every
     * other branch's index.
     */
    public function __clone(){$this->index=$this->index->copy();}

    /**
     * Void-fill-reserve headroom, exact for this container. Depends only on
     * `$container`, which is readonly, so the BigInt division is paid once per
     * state (inherited as-is by clones) instead of on every candidate check.
     */
    public function usableVolume():string{return $this->usableVolumeTicks = $this->usableVolumeTicks ?? VolumeReserve::usable($this->container);}

    public function add(Placement $placement):void
    {
        $box=$placement->envelopeBox();
        $compressionSensitive=$this->compressionSensitive
            ||$placement->instance->item->shapeType===ShapeType::COMPRESSIBLE;
        if($compressionSensitive)
            $this->usedVolume=Nesting::usedVolume(TopLoadAssigner::assign(array_merge($this->placements, [$placement])));
        else
            $this->usedVolume=BigInt::add($this->usedVolume,Nesting::usedVolumeDelta($this->placements,$placement));
        $this->placements[]=$placement;
        $this->payloadTicks+=$placement->instance->weight()->ticks;
        $item=$placement->instance->item;
        $this->stackSensitive=$this->stackSensitive||$item->isStackSensitive();
        $this->routeSensitive=$this->routeSensitive||$item->stopIndex!==null;
        $this->compressionSensitive=$compressionSensitive;
        if($box->z2()>$this->maxZ)$this->maxZ=$box->z2();
        $this->occupied[]=$box;
        $bound=$box->extent();
        $this->index->add(count($this->bounds),$bound);
        $this->bounds[]=$bound;
        $shape=$placement->hullShape();
        $this->hullShapes[]=$shape;
        // Retiring a point because it falls inside a solid's box assumes the box *is* the
        // solid. For a hull it is not: a placement origin is a corner of a bounding box, and a
        // hull leaves most of that box -- including, for a wedge, the origin itself --
        // available to the next item. Pruning them first would mean the engine could describe
        // an interlocking pack it could never propose.
        $retired=[];
        if($shape===null)
            foreach($this->points as $key=>$point)if($box->containsPoint($point)){$retired[$key]=true;unset($this->points[$key]);}
        if($retired!==[])$this->orderedPoints=array_values(array_filter(
            $this->orderedPoints,
            static function (Point $point) use ($retired): bool {
                return !isset($retired["{$point->x}:{$point->y}:{$point->z}"]);
            },
        ));
        $this->absorb($this->exposedPoints($box));
    }

    /**
     * Record a placement for solvers which never query extreme points.
     *
     * GridSolver constructs a non-overlapping lattice directly, so maintaining the
     * general solver's point and occupied-box indexes here would be quadratic waste.
     */
    public function addDirect(Placement $placement):void
    {
        $box=$placement->envelopeBox();
        // GridSolver admits only rigid cuboids and never reaches the non-local compression
        // path. Keeping a second unreachable implementation here would be speculative and
        // could drift from `add`, so the direct lattice path remains the proven O(1) delta.
        $this->usedVolume=BigInt::add($this->usedVolume,Nesting::usedVolumeDelta($this->placements,$placement));
        $this->placements[]=$placement;
        $this->payloadTicks+=$placement->instance->weight()->ticks;
        $item=$placement->instance->item;
        $this->stackSensitive=$this->stackSensitive||$item->isStackSensitive();
        $this->routeSensitive=$this->routeSensitive||$item->stopIndex!==null;
        if($box->z2()>$this->maxZ)$this->maxZ=$box->z2();
    }

    public function placementCount():int
    {
        return $this->latticeSummary!==null?$this->latticeSummary->count:count($this->placements);
    }

    /**
     * Record an entire GridSolver regular-lattice run in O(1) beyond the rotation
     * search already paid for by $summary -- no per-item Placement is built.
     * $items is the placed-instance slice, kept only so this container's specific
     * instances remain accounted for (expandPlacements(), unplaced-item
     * bookkeeping); it is a reference slice of what the caller already holds, not a
     * new allocation per item.
     *
     * @param list<ItemInstance> $items
     */
    public function addLattice(LatticeSummary $summary,array $items):void
    {
        $this->latticeSummary=$summary;
        $this->latticeItems=$items;
        $this->payloadTicks+=$summary->totalWeightTicks();
        $this->usedVolume=BigInt::add($this->usedVolume,$summary->usedVolumeString());
        foreach($items as $instance){
            $item=$instance->item;
            $this->stackSensitive=$this->stackSensitive||$item->isStackSensitive();
            $this->routeSensitive=$this->routeSensitive||$item->stopIndex!==null;
        }
        if($summary->maxZTicks()>$this->maxZ)$this->maxZ=$summary->maxZTicks();
    }

    /** @param list<Point> $points */
    private function absorb(array $points):void
    {
        $d=$this->container->innerDimensions;
        $length=$d->length->ticks;$width=$d->width->ticks;$height=$d->height->ticks;
        foreach($points as $point){
            $x=$point->x;$y=$point->y;$z=$point->z;
            if($x>=$length||$y>=$width||$z>=$height)continue;
            $key="{$x}:{$y}:{$z}";
            if(isset($this->points[$key]))continue;
            $inside=false;
            foreach($this->bounds as [$bx1,$by1,$bz1,$bx2,$by2,$bz2])
                if($bx1<=$x&&$x<$bx2&&$by1<=$y&&$y<$by2&&$bz1<=$z&&$z<$bz2){$inside=true;break;}
            if(!$inside){
                $this->points[$key]=$point;
                $pointKey=[$z,$y,$x];$low=0;$high=count($this->orderedPoints);
                while($low<$high){
                    $middle=intdiv($low+$high,2);$current=$this->orderedPoints[$middle];
                    if([$current->z,$current->y,$current->x]<=$pointKey)$low=$middle+1;else $high=$middle;
                }
                array_splice($this->orderedPoints,$low,0,[$point]);
            }
        }
    }

    /** @return list<Point> */
    private function exposedPoints(AxisAlignedBox $box):array
    {
        $o=$box->origin;$x2=$box->x2();$y2=$box->y2();$z2=$box->z2();
        return [
            new Point($x2,$o->y,$o->z),new Point($o->x,$y2,$o->z),new Point($o->x,$o->y,$z2),
            new Point($x2,$y2,$o->z),new Point($x2,$o->y,$z2),new Point($o->x,$y2,$z2),
            new Point($x2,$o->y,$this->surfaceZ($x2,$o->y,$o->z)),
            new Point($x2,$this->surfaceY($x2,$o->z,$o->y),$o->z),
            new Point($o->x,$y2,$this->surfaceZ($o->x,$y2,$o->z)),
            new Point($this->surfaceX($y2,$o->z,$o->x),$y2,$o->z),
            new Point($o->x,$this->surfaceY($o->x,$z2,$o->y),$z2),
            new Point($this->surfaceX($o->y,$z2,$o->x),$o->y,$z2),
        ];
    }

    private function surfaceZ(int $x,int $y,int $ceiling):int
    {$best=0;foreach($this->bounds as $b)if($b[5]<=$ceiling&&$b[0]<=$x&&$x<$b[3]&&$b[1]<=$y&&$y<$b[4]&&$b[5]>$best)$best=$b[5];return $best;}

    private function surfaceY(int $x,int $z,int $ceiling):int
    {$best=0;foreach($this->bounds as $b)if($b[4]<=$ceiling&&$b[0]<=$x&&$x<$b[3]&&$b[2]<=$z&&$z<$b[5]&&$b[4]>$best)$best=$b[4];return $best;}

    private function surfaceX(int $y,int $z,int $ceiling):int
    {$best=0;foreach($this->bounds as $b)if($b[3]<=$ceiling&&$b[1]<=$y&&$y<$b[4]&&$b[2]<=$z&&$z<$b[5]&&$b[3]>$best)$best=$b[3];return $best;}

}
