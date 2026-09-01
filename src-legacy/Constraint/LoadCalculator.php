<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Constraint\Internal\{LoadAnalysis,LoadSupportGraph};
use Packvium\Domain\Placement;
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
                $item->compressionRatioPpm,$item->maxCompressionPressureKpa,
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
        return (new LoadAnalysis($units,new LoadSupportGraph($units)))->topLoads();
    }

    /** First unit whose bearing limit is exceeded, as [code, detail]. @param list<LoadUnit> $units @return array{0:string,1:string}|null */
    public static function overloaded(array $units):?array
    {
        return (new LoadAnalysis($units,new LoadSupportGraph($units)))->overloaded();
    }

    /**
     * First compressible unit carrying more pressure than it declared it can take.
     *
     * Deliberately shaped like `overloaded` and reading the same propagated loads, because
     * the two answer one question in two currencies: `max_top_load` is a mass the box below
     * must bear, `max_compression_pressure_kpa` a pressure the item itself must survive. An
     * item can pass one and fail the other, so both are asked.
     *
     * @param list<LoadUnit> $units @return array{0:string,1:string}|null
     */
    public static function crushed(array $units):?array
    {
        return (new LoadAnalysis($units,new LoadSupportGraph($units)))->crushed();
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
        return (new LoadAnalysis($units,new LoadSupportGraph($units)))->restingAbove();
    }

    /** Items resting anywhere above each unit. A count, not a weight. @param list<LoadUnit> $units @return list<int> */
    public static function stackedCounts(array $units):array
    {
        return (new LoadAnalysis($units,new LoadSupportGraph($units)))->stackedCounts();
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
        return (new LoadAnalysis($units,new LoadSupportGraph($units)))->stackLimitExceeded();
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
        return (new LoadAnalysis($units,new LoadSupportGraph($units)))->stackDensityExceeded($maxDensityTicks);
    }
}
