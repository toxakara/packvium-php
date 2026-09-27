<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Constraint\Internal\LoadSupportGraph;
use Packvium\Domain\{AxisAlignedBox,Container,Dimensions,FixedPlacement,Item,ItemInstance,PackedContainer,PackingRequest,Placement,Point};
use Packvium\Support\CanonicalJson;
use Packvium\Unit\{Length,Weight};
use Packvium\Validation\{FixedPlacementException,IndependentSolutionValidator};
/**
 * Admission of a request's fixed placements (docs/PLAN-REVISIONS.md).
 *
 * A fixed placement is an item already in a known place: loaded, or locked there by an
 * operator. It enters the solve as a real `Placement` -- weight, support, top load and all --
 * seeded into the one container instance it names, so every rule the engine already enforces
 * holds for it with no rule of its own.
 *
 * What this class adds is the refusal. A fixed set that is not a valid packing on its own is
 * refused before any search, with `invalid_fixed_placement`, rather than exempted: an exempt
 * item would need a second validator, and four engines would have to agree on which rules it
 * skips. The check is the ordinary `IndependentSolutionValidator` run over the fixed set
 * alone, with item accounting left out because the free items have not been placed yet.
 */
final class FixedPlacementAdmission
{
    /**
     * Resolve `$request->fixedPlacements`, or refuse the request.
     *
     * O(f log f) to resolve f entries, plus one validator pass over them, which is O(f^2) in
     * the worst case of its pairwise sweep. It runs once, before search.
     */
    public static function admit(PackingRequest $request,float $minimumSupportRatio,Length $clearance,?int $maxContainers):FixedLoad
    {
        if($request->fixedPlacements===[])return new FixedLoad([],$request->instances());
        $items=[];foreach($request->items as $item)$items[$item->id]=$item;
        $containers=[];foreach($request->containers as $container)$containers[$container->id]=$container;
        foreach($request->fixedPlacements as $entry)self::requireKnown($entry,$items,$containers);
        $instances=self::assignInstances($request->fixedPlacements,$items);
        $grouped=self::groupByContainer($request,$instances,$clearance);
        self::requireContiguousInstances($grouped,$containers,$maxContainers);
        $packed=[];
        foreach($grouped as [$containerId,$sequence,$placements])
            $packed[]=new PackedContainer($containers[$containerId],$sequence,self::withSupportAndLoads($placements));
        self::requireValidAlone($request,$packed,$minimumSupportRatio,$clearance);
        $taken=[];foreach($packed as $container)foreach($container->placements as $placement)$taken[$placement->instance->id()]=true;
        $free=array_values(array_filter($request->instances(),static fn(ItemInstance $instance):bool=>!isset($taken[$instance->id()])));
        return new FixedLoad($packed,$free);
    }

    /** @param array<string,Item> $items @param array<string,Container> $containers */
    private static function requireKnown(FixedPlacement $entry,array $items,array $containers):void
    {
        $item=$items[$entry->itemId]??null;
        if($item===null)throw new FixedPlacementException('unknown item type '.CanonicalJson::spelling($entry->itemId));
        if(!isset($containers[$entry->containerId]))throw new FixedPlacementException('unknown container type '.CanonicalJson::spelling($entry->containerId));
        if(!in_array($entry->rotation,$item->allowedRotations,true))
            throw new FixedPlacementException("{$entry->itemId} may not be placed in orientation {$entry->rotation->value}");
    }

    /**
     * Fixed items take the first instances of their type, in the order they are listed.
     *
     * @param list<FixedPlacement> $entries @param array<string,Item> $items @return list<ItemInstance>
     */
    private static function assignInstances(array $entries,array $items):array
    {
        $taken=[];$instances=[];
        foreach($entries as $entry){
            $item=$items[$entry->itemId];
            $taken[$item->id]=($taken[$item->id]??0)+1;
            if($taken[$item->id]>$item->quantity)
                throw new FixedPlacementException("{$taken[$item->id]} {$item->id} fixed, {$item->quantity} requested");
            $instances[]=new ItemInstance($item,$taken[$item->id]);
        }
        return $instances;
    }

    /**
     * @param list<ItemInstance> $instances
     * @return list<array{0:string,1:int,2:list<Placement>}>
     */
    private static function groupByContainer(PackingRequest $request,array $instances,Length $clearance):array
    {
        $grouped=[];
        foreach($request->fixedPlacements as $index=>$entry){
            $key=$entry->containerId."\0".$entry->containerInstance;
            $grouped[$key]??=[$entry->containerId,$entry->containerInstance,[]];
            $grouped[$key][2][]=self::placement($entry,$instances[$index],$clearance);
        }
        $order=[];foreach($request->containers as $position=>$container)$order[$container->id]=$position;
        $groups=array_values($grouped);
        usort($groups,static fn(array $left,array $right):int=>[$order[$left[0]],$left[1]]<=>[$order[$right[0]],$right[1]]);
        return $groups;
    }

    private static function placement(FixedPlacement $entry,ItemInstance $instance,Length $clearance):Placement
    {
        $margin=$clearance->ticks;
        $position=$entry->position;
        // The clearance envelope must be inside the container, as it must for any placement;
        // an item flush against a wall has an envelope that starts before it.
        if(min($position->x,$position->y,$position->z)<$margin)
            throw new FixedPlacementException("outside_container: {$instance->id()}");
        $dimensions=$instance->dimensions()->rotated($entry->rotation);
        // A far corner past PHP_INT_MAX would become a float mid-geometry; no container reaches
        // it, so the item is outside, as the reference's unbounded integers find it.
        if(self::farCornerOverflows($position,$dimensions,$margin))
            throw new FixedPlacementException("outside_container: {$instance->id()}");
        $envelopeOrigin=new Point($position->x-$margin,$position->y-$margin,$position->z-$margin);
        $envelope=$margin>0?$dimensions->expand($clearance):$dimensions;
        return new Placement($instance,$position,$entry->rotation,$dimensions,$envelopeOrigin,$envelope,1.0,new Weight(0),true);
    }

    private static function farCornerOverflows(Point $position,Dimensions $dimensions,int $margin):bool
    {
        return $position->x>PHP_INT_MAX-$dimensions->length->ticks-$margin
            ||$position->y>PHP_INT_MAX-$dimensions->width->ticks-$margin
            ||$position->z>PHP_INT_MAX-$dimensions->height->ticks-$margin;
    }

    /**
     * @param list<array{0:string,1:int,2:list<Placement>}> $grouped @param array<string,Container> $containers
     */
    private static function requireContiguousInstances(array $grouped,array $containers,?int $maxContainers):void
    {
        $named=[];
        foreach($grouped as [$containerId,$sequence])$named[$containerId][]=$sequence;
        foreach($named as $containerId=>$sequences){
            $count=count($sequences);
            if($sequences!==range(1,$count))
                throw new FixedPlacementException("{$containerId} instances ".CanonicalJson::spelling($sequences)." are not numbered 1..{$count}");
            $quantity=$containers[$containerId]->quantity;
            if($quantity!==null&&$count>$quantity)
                throw new FixedPlacementException("{$count} {$containerId} named, {$quantity} available");
        }
        if($maxContainers!==null&&count($grouped)>$maxContainers)
            throw new FixedPlacementException(count($grouped)." containers hold fixed items, max_containers is {$maxContainers}");
    }

    /**
     * Each fixed item's support ratio from the fixed items under it, and its top load.
     *
     * Support is measured against every other fixed item in the container, not only the ones
     * listed before it: the set is one arrangement, and listing order is not physics.
     *
     * @param list<Placement> $placements @return list<Placement>
     */
    private static function withSupportAndLoads(array $placements):array
    {
        $supported=[];
        foreach($placements as $index=>$placement){
            $others=$placements;unset($others[$index]);
            $supported[]=new Placement($placement->instance,$placement->position,$placement->rotation,$placement->dimensions,
                $placement->envelopeOrigin,$placement->envelopeDimensions,self::supportRatio(array_values($others),$placement),new Weight(0),true);
        }
        return TopLoadAssigner::assign($supported);
    }

    /** @param list<Placement> $others */
    private static function supportRatio(array $others,Placement $placement):float
    {
        if($placement->envelopeOrigin->z===0)return 1.0;
        $box=new AxisAlignedBox($placement->envelopeOrigin,$placement->envelopeDimensions);
        $support=LoadSupportGraph::candidateView($others,$placement->instance,$box);
        return $support->supportingArea/$placement->envelopeDimensions->baseAreaTicks();
    }

    /** @param list<PackedContainer> $packed */
    private static function requireValidAlone(PackingRequest $request,array $packed,float $minimumSupportRatio,Length $clearance):void
    {
        $report=(new IndependentSolutionValidator())->validate($request,$packed,$minimumSupportRatio,$clearance,null);
        if(!$report->valid){
            $issue=$report->issues[0];
            throw new FixedPlacementException("{$issue->code}: {$issue->detail}");
        }
    }
}
