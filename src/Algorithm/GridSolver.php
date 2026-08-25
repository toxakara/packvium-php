<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container,LatticeSummary,Placement,Point};
/**
 * Regular lattice for a single item type.
 *
 * The lattice cannot express per-item physical rules, so every rule a regular stack could
 * break is folded into the layer count and capacity before any placement is made.
 * Anything the lattice cannot honour falls back to the general solver.
 */
final class GridSolver implements SingleContainerSolver
{
    public function name():string{return 'grid';}
    public function orderInsensitive():bool{return true;}

    /**
     * Everything about an item that determines its behaviour in a regular lattice,
     * keyed by achievable physical footprint rather than declared id:
     * two declared types that are 90-degree rotations of each other, with full
     * rotation freedom, are the same physical item for lattice purposes -- a real
     * catalog pattern (the same carton listed under two SKUs).
     */
    private static function latticeProfile(\Packvium\Domain\Item $item):string
    {
        $footprints=[];
        foreach($item->dimensions->uniqueRotations($item->allowedRotations) as [, $physical])
            $footprints[]="{$physical->length->ticks}x{$physical->width->ticks}x{$physical->height->ticks}";
        sort($footprints);
        $tags=$item->tags;sort($tags);
        $incompatibleTags=$item->incompatibleTags;sort($incompatibleTags);
        $eligibleContainerTags=$item->eligibleContainerTags;sort($eligibleContainerTags);
        return json_encode([
            $footprints,$item->weight->ticks,$item->stackable,$item->mustBeOnFloor,
            $item->maxTopLoad?->ticks,$tags,$incompatibleTags,$eligibleContainerTags,
            $item->groundContactRule,
            // Nesting overlap is valid only within one declared item type. Preserve
            // non-nesting catalog aliases, but never borrow a prototype's overlap
            // allowance for another SKU.
            $item->nestingHeight===null?null:$item->id,
            $item->nestingHeight?->ticks,$item->group,
            // Two items are only interchangeable in one lattice if they agree about how
            // tall a column may be and when they come off the truck. Fusing a limit of 1
            // with an unlimited item, or stop 0 with stop 1, produces a tiling neither
            // one allows.
            $item->maxStackedItems,$item->stopIndex,
        ]);
    }

    /** @param list<\Packvium\Domain\ItemInstance> $items */
    public function supports(array $items):bool
    {
        return $items!==[]&&count(array_unique(array_map(static fn($i)=>self::latticeProfile($i->item),$items)))===1;
    }

    public function packOne(Container $container,int $sequence,array $items,PackingConfig $config,SearchStats $stats,Deadline $deadline):SingleContainerSolution
    {
        $prototype=$items===[]?null:$items[0]->item;
        if($container->obstacles!==[]||$prototype===null||!$this->supports($items)||array_intersect($prototype->tags,$prototype->incompatibleTags))
            // A mixed-type list would otherwise place every item using the first
            // item's dimensions -- silently wrong geometry, not merely suboptimal.
            return (new ExtremePointSolver())->packOne($container,$sequence,$items,$config,$stats,$deadline);
        if($prototype->eligibleContainerTags!==[]&&array_intersect($prototype->eligibleContainerTags,$container->tags)===[])
            return (new ExtremePointSolver())->packOne($container,$sequence,$items,$config,$stats,$deadline);
        if($container->voidFillReserveRatio>0)
            return (new ExtremePointSolver())->packOne($container,$sequence,$items,$config,$stats,$deadline);
        if($container->maxStackDensity!==null)
            // The single-layer heuristic below only ever reasons about one item
            // resting on one other, never the cumulative load a tall column presses
            // through its own footprint -- defer to the general solver, which checks
            // the whole stack per candidate via stackDensityExceeded.
            return (new ExtremePointSolver())->packOne($container,$sequence,$items,$config,$stats,$deadline);
        if($container->axles!==null)
            // Lattice capacity does not carry the changing longitudinal moment.
            // Delegate to the candidate engine, which enforces exact gross reactions
            // before accepting every placement.
            return (new ExtremePointSolver())->packOne($container,$sequence,$items,$config,$stats,$deadline);
        $state=new ContainerState($container,$sequence);
        // Every non-floor lattice cell has one full-area direct supporter, including
        // an exact nesting predecessor. That satisfies ratio=1, covered and single;
        // multiple is satisfiable only on the floor, so cap instead of delegating.
        $singleLayer=$prototype->mustBeOnFloor||!$prototype->stackable||$prototype->groundContactRule==='multiple';
        // How much less than the full height each layer above the first consumes:
        // zero (the ordinary lattice) unless the item declares it sinks into an
        // identical one beneath it. Reduces to the original nz/z formulas
        // exactly when nestingHeight is unset.
        $nestingTicks=$prototype->nestingHeight?->ticks??0;
        $best=null;
        foreach($prototype->dimensions->uniqueRotations($prototype->allowedRotations) as [$rotation,$physical]){
            $envelope=$config->clearance->ticks?$physical->expand($config->clearance):$physical;
            $nx=intdiv($container->innerDimensions->length->ticks,$envelope->length->ticks);
            $ny=intdiv($container->innerDimensions->width->ticks,$envelope->width->ticks);
            $layerStep=$envelope->height->ticks-$nestingTicks;
            $innerHeight=$container->innerDimensions->height->ticks;
            $nz=$innerHeight<$envelope->height->ticks?0:intdiv($innerHeight-$envelope->height->ticks,$layerStep)+1;
            if($singleLayer)$nz=min($nz,1);
            // Every item in a uniform column has the same weight. Its bottom item
            // therefore bears `(nz - 1) * weight`, so the exact greatest safe layer
            // count is floor(maxTopLoad / weight) + 1. The weight-zero case carries no
            // load at any height and deliberately leaves the geometric capacity alone.
            if($prototype->maxTopLoad!==null&&$prototype->weight->ticks>0){
                $layersAbove=intdiv($prototype->maxTopLoad->ticks,$prototype->weight->ticks);
                // Compare before adding one: when both public values approach
                // PHP_INT_MAX, an unneeded `PHP_INT_MAX + 1` would become a float.
                if($layersAbove<$nz-1)$nz=$layersAbove+1;
            }
            // A uniform column of $nz identical items leaves $nz-1 resting above its
            // bottom one, which is exactly the count maxStackedItems bounds. The lattice
            // can express this in its layer count, so it caps rather than falling back.
            if($prototype->maxStackedItems!==null)$nz=min($nz,$prototype->maxStackedItems+1);
            $capacity=$nx*$ny*$nz;
            if($container->maxItems!==null)$capacity=min($capacity,$container->maxItems);
            if($container->maxPayload!==null&&$prototype->weight->ticks>0)$capacity=min($capacity,intdiv($container->maxPayload->ticks,$prototype->weight->ticks));
            foreach(array_intersect($prototype->tags,array_keys($container->tagLimits)) as $tag)$capacity=min($capacity,$container->tagLimits[$tag]);
            $score=array_merge([-$capacity],$envelope->volumeKey(),[$rotation->value]);
            if($best===null||$score<$best[0])$best=[$score,$rotation,$physical,$envelope,$nx,$ny,$layerStep,$capacity];
        }
        [,$rotation,$physical,$envelope,$nx,$ny,$layerStep,$capacity]=$best;
        $total=min($capacity,count($items));
        // A group is all-or-nothing: a lattice that cannot hold every member holds none.
        if($prototype->group!==null&&$total<count($items))return new SingleContainerSolution($state,$items,dominantLattice:true);
        $clearance=$config->clearance->ticks;
        $sameDeclaredType=count(array_unique(array_map(static fn($i)=>$i->item->id,array_slice($items,0,$total))))===1;
        if($total>0&&!$config->requirePlacementCoordinates&&$nestingTicks===0&&$sameDeclaredType){
            // Quantity-compression fast path: the lattice parameters above
            // already fully determine every coordinate in O(r); skip the O(n) loop
            // that would otherwise build one Placement per instance purely to fill
            // them in. nestingTicks===0 keeps this to the case usedVolume/
            // centreOfMass reduce to a plain per-item sum -- see LatticeSummary.
            // LatticeSummary::$itemType is a single string, so this path additionally
            // requires every instance to share one *declared* id (the fungibility rule widened
            // supports() to admit geometrically fungible but differently-declared
            // types too; those still take the per-item loop below, which tags each
            // Placement from its own ItemInstance).
            $summary=new LatticeSummary($prototype->id,$rotation,$physical,$envelope,$nx,$ny,$layerStep,$clearance,$total,$prototype->weight->ticks);
            $state->addLattice($summary,array_slice($items,0,$total));
            $stats->searchNodesExpanded++;
            $stats->candidatePointsConsidered+=$total;
            $stats->candidatesEvaluated+=$total;
            $stats->placementsAttempted+=$total;
            return new SingleContainerSolution($state,array_slice($items,$total));
        }
        $targetFootprint=[$physical->length->ticks,$physical->width->ticks,$physical->height->ticks];
        $placed=0;
        for($index=0;$index<$total;$index++){
            if($deadline->expired())break;
            $stats->searchNodesExpanded++;
            $stats->candidatePointsConsidered++;
            $x=$index%$nx;$y=intdiv($index,$nx)%$ny;$z=intdiv($index,$nx*$ny);
            $point=new Point($x*$envelope->length->ticks,$y*$envelope->width->ticks,$z*$layerStep);
            $position=new Point($point->x+$clearance,$point->y+$clearance,$point->z+$clearance);
            // A mixed-declared-type lattice group is only reached when every
            // item shares the same achievable footprint set, not necessarily the same
            // rotation labels reaching it -- the rotation stored on each Placement
            // must be looked up per item, never copied from $prototype.
            $ownRotation=$ownPhysical=null;
            foreach($items[$index]->item->dimensions->uniqueRotations($items[$index]->item->allowedRotations) as [$candidateRotation,$candidateDims]){
                if([$candidateDims->length->ticks,$candidateDims->width->ticks,$candidateDims->height->ticks]===$targetFootprint){
                    $ownRotation=$candidateRotation;$ownPhysical=$candidateDims;break;
                }
            }
            assert($ownRotation!==null,'supports() guarantees a matching rotation exists');
            $state->addDirect(new Placement($items[$index],$position,$ownRotation,$ownPhysical,$point,$envelope,1.0));
            $placed++;
            $stats->candidatesEvaluated++;
            $stats->placementsAttempted++;
        }
        if($prototype->group!==null&&$placed<count($items))return new SingleContainerSolution(new ContainerState($container,$sequence),$items,false,$deadline->expired(),true);
        return new SingleContainerSolution($state,array_slice($items,$placed),false,$placed<$total&&$deadline->expired(),true);
    }
}
