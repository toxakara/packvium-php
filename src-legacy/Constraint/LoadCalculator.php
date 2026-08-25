<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Constraint\Internal\LoadSupportGraph;
use Packvium\Domain\Placement;
use Packvium\Support\Arithmetic;
use Packvium\Support\BigInt;
use Packvium\Unit\Length;
final class LoadCalculator
{
    /**
     * One square metre in length ticks squared, the reference area a stack density
     * limit is expressed per (e.g. "1500 kg/m^2 floor loading"), derived from the
     * single tick scale rather than hard-coded so it can never drift from it.
     */
    public const SQUARE_METRE_TICKS=(1000*Length::TICKS_PER_MM)**2;
    /** @param list<Placement> $placements @return list<LoadUnit> */
    public static function units(array $placements,?LoadUnit $extra=null):array
    {
        $units=[];
        foreach($placements as $p){
            $item=$p->instance->item;
            $nesting=$item->nestingHeight;
            $units[]=new LoadUnit(
                $p->envelopeBox(),$p->instance->weight()->ticks,($nullsafeVariable1 = $item->maxTopLoad) ? $nullsafeVariable1->ticks : null,$item->maxStackedItems,$p->instance->id(),
                $nesting===null?null:$item->id,($nullsafeVariable2 = $nesting) ? $nullsafeVariable2->ticks : null,
            );
        }
        if($extra!==null)$units[]=$extra;
        return $units;
    }

    /**
     * Weight borne by each unit, propagated down the whole stack.
     *
     * Every box pushes its own weight plus everything already resting on it onto its
     * direct face or nesting supports, split by contact area. The integer remainder
     * goes to the last supporter so the distributed total is conserved exactly.
     *
     * @param list<LoadUnit> $units @return list<int>
     */
    public static function topLoads(array $units):array
    {
        $count=count($units);
        if($count===0)return [];
        $loads=array_fill(0,$count,0);
        $graph=new LoadSupportGraph($units);
        $order=range(0,$count-1);
        usort($order,function (int $a, int $b) use ($units): int {
            return [-$units[$a]->box->z2(),-$units[$a]->box->origin->z,$a]<=>[-$units[$b]->box->z2(),-$units[$b]->box->origin->z,$b];
        });
        foreach($order as $upperIndex){
            $supports=$graph->supporters($upperIndex);
            $totalArea=array_sum(array_map(static function (ContactEdge $e): int {
                return $e->area;
            },$supports));
            if($totalArea===0)continue;
            $downward=$units[$upperIndex]->weightTicks+$loads[$upperIndex];
            $assigned=0;$last=count($supports)-1;
            foreach($supports as $position=>$edge){
                $share=$position===$last?$downward-$assigned:Arithmetic::mulDiv($downward,$edge->area,$totalArea);
                $assigned+=$share;
                $loads[$edge->index]+=$share;
            }
        }
        return $loads;
    }

    /** First unit whose bearing limit is exceeded, as [code, detail]. @param list<LoadUnit> $units @return array{0:string,1:string}|null */
    public static function overloaded(array $units):?array
    {
        $bearing=false;
        foreach($units as $unit)if($unit->maxTopLoadTicks!==null){$bearing=true;break;}
        if(!$bearing)return null;
        $loads=self::topLoads($units);
        foreach($units as $index=>$unit)
            if($unit->maxTopLoadTicks!==null&&$loads[$index]>$unit->maxTopLoadTicks)return ['top_load_exceeded',$unit->label];
        return null;
    }

    /**
     * Everything resting anywhere above each unit, following the support graph upward.
     *
     * An item stacked through two levels of intermediaries appears in the set of every
     * level beneath it, not merely the one it directly touches. Each entry is a set
     * keyed by unit index.
     *
     * @param list<LoadUnit> $units @return list<array<int,true>>
     */
    public static function restingAbove(array $units):array
    {
        $count=count($units);
        $graph=new LoadSupportGraph($units);
        $memo=[];
        $aboveSet=function(int $index) use (&$aboveSet,&$memo,$graph):array{
            if(isset($memo[$index]))return $memo[$index];
            $result=[];
            foreach($graph->children($index) as $child){
                $result[$child]=true;
                foreach($aboveSet($child) as $ancestor=>$_)$result[$ancestor]=true;
            }
            return $memo[$index]=$result;
        };
        $sets=[];
        for($index=0;$index<$count;$index++)$sets[]=$aboveSet($index);
        return $sets;
    }

    /** Items resting anywhere above each unit. A count, not a weight. @param list<LoadUnit> $units @return list<int> */
    public static function stackedCounts(array $units):array
    {
        return array_map(static function (array $above): int {
            return count($above);
        },self::restingAbove($units));
    }

    /**
     * First item due at an earlier stop with a later-stop item resting above it.
     *
     * docs/VALIDATION-CONTRACT.md requires every item due at a stop to be removable
     * before the route moves on to the next one. Vertical blocking is the half a
     * placement decision can see by itself: an item bound for a later stop, resting
     * anywhere above an earlier-stop one, has to come off first, and nothing happening
     * at the earlier stop can move it. The other half -- whether a box that is
     * unblocked can also slide out of some wall -- is a whole-container reachability
     * question the post-validator answers; this is the necessary condition, not the
     * whole of it. A null stop rides the whole route: never removed, always a blocker.
     *
     * @param list<LoadUnit> $units @param list<float> $stops @return array{0:string,1:string}|null
     */
    public static function routeOrderViolated(array $units,array $stops):?array
    {
        $routed=false;
        foreach($stops as $stop)if($stop!==INF){$routed=true;break;}
        if(!$routed)return null;
        foreach(self::restingAbove($units) as $index=>$above)
            foreach(array_keys($above) as $upper)
                if($stops[$upper]>$stops[$index])
                    return ['unloading_order_violation',$units[$index]->label.' blocked by '.$units[$upper]->label];
        return null;
    }

    /** First unit whose stacked-item limit is exceeded, as [code, detail]. @param list<LoadUnit> $units @return array{0:string,1:string}|null */
    public static function stackLimitExceeded(array $units):?array
    {
        $limited=false;
        foreach($units as $unit)if($unit->maxStackedItems!==null){$limited=true;break;}
        if(!$limited)return null;
        $counts=self::stackedCounts($units);
        foreach($units as $index=>$unit)
            if($unit->maxStackedItems!==null&&$counts[$index]>$unit->maxStackedItems)return ['stacked_item_limit_exceeded',$unit->label];
        return null;
    }

    /**
     * First unit whose cumulative load crushes its own footprint, as [code, detail].
     *
     * `maxDensityTicks` is weight ticks allowed per square metre of a unit's own base
     * area, checked at every level of the stack, not only the floor: a wide item
     * narrowing to a small waist part-way up is exactly the case a flat per-item
     * `maxTopLoad` cannot express, since the same absolute load becomes crushing once
     * concentrated onto less area. Compared via `BigInt`, not divided: a one-metre
     * footprint (2.56e14 ticks^2) times a multi-tonne weight in eighth-micrograms
     * overflows a 64-bit int well before either factor alone would.
     *
     * @param list<LoadUnit> $units
     * @return array{0:string,1:string}|null
     */
    public static function stackDensityExceeded(array $units,?int $maxDensityTicks):?array
    {
        if($maxDensityTicks===null)return null;
        $loads=self::topLoads($units);
        foreach($units as $index=>$unit){
            // `topLoads` gives only what rests *on* a unit, the right meaning for a
            // flat `maxTopLoad`. Floor loading is about everything bearing down
            // *through* a unit's own footprint, which includes its own weight too.
            $total=$unit->weightTicks+$loads[$index];
            $bearing=BigInt::multiply($total,self::SQUARE_METRE_TICKS);
            $allowed=BigInt::multiply($maxDensityTicks,$unit->box->dimensions->baseAreaTicks());
            if(BigInt::compare($bearing,$allowed)>0)return ['stack_density_exceeded',$unit->label];
        }
        return null;
    }
}
