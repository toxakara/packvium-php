<?php
declare(strict_types=1);
namespace Packvium\Constraint\Internal;

use Packvium\Constraint\{ContactEdge,LoadCalculator,LoadUnit};
use Packvium\Domain\Compression;
use Packvium\Support\{Arithmetic,BigInt};

/**
 * Per-scene load analysis over an already constructed support graph.
 *
 * The public LoadCalculator remains the frozen, allocation-owning facade. Search code
 * that already owns an incremental LoadSupportGraph uses this internal session instead,
 * so the graph and its derived load/ancestor vectors are each computed at most once.
 *
 * Building the vectors is O(n + E) time and O(n + E) space after the graph exists.
 * Query methods are O(n) in the number of units and never rebuild contact geometry.
 */
final class LoadAnalysis
{
    /** @var list<LoadUnit> */
    private $units;
    /**
     * @var \Packvium\Constraint\Internal\LoadSupportGraph|null
     */
    private $graph;
    /** @var list<int>|null */
    private $topLoads;
    /** @var list<array<int,true>>|null */
    private $restingAbove;

    /** @param list<LoadUnit> $units */
    public function __construct(array $units,?LoadSupportGraph $graph=null)
    {
        $this->units=$units;
        $this->graph=$graph;
    }

    public function graph():LoadSupportGraph
    {
        return $this->graph = $this->graph ?? new LoadSupportGraph($this->units);
    }

    /** @return list<int> */
    public function topLoads():array
    {
        if($this->topLoads!==null)return $this->topLoads;
        $count=count($this->units);
        if($count===0)return $this->topLoads=[];
        $graph=$this->graph();
        $loads=array_fill(0,$count,0);
        $order=$graph->descendingIndices();
        foreach($order as $upperIndex){
            $supports=$graph->supporters($upperIndex);
            $totalArea=0;
            foreach($supports as $edge)$totalArea+=$edge->area;
            if($totalArea===0)continue;
            $downward=$this->units[$upperIndex]->weightTicks+$loads[$upperIndex];
            $assigned=0;$last=count($supports)-1;
            foreach($supports as $position=>$edge){
                $share=$position===$last?$downward-$assigned:Arithmetic::mulDiv($downward,$edge->area,$totalArea);
                $assigned+=$share;
                $loads[$edge->index]+=$share;
            }
        }
        return $this->topLoads=$loads;
    }

    /** @return array{0:string,1:string}|null */
    public function overloaded():?array
    {
        $bearing=false;
        foreach($this->units as $unit)if($unit->maxTopLoadTicks!==null){$bearing=true;break;}
        if(!$bearing)return null;
        $loads=$this->topLoads();
        foreach($this->units as $index=>$unit)
            if($unit->maxTopLoadTicks!==null&&$loads[$index]>$unit->maxTopLoadTicks)return ['top_load_exceeded',$unit->label];
        return null;
    }

    /**
     * First compressible unit whose applied pressure exceeds its declared limit.
     *
     * A crush is a hard boundary, not a worse score: the caller gets a refusal rather than a
     * plan in which something arrived flattened.
     *
     * @return array{0:string,1:string}|null
     */
    public function crushed():?array
    {
        $compressible=false;
        foreach($this->units as $unit)if($unit->maxCompressionPressureKpa!==null){$compressible=true;break;}
        if(!$compressible)return null;
        $loads=$this->topLoads();
        foreach($this->units as $index=>$unit){
            $limit=$unit->maxCompressionPressureKpa;
            if($limit===null)continue;
            $box=$unit->box;
            $footprint=($box->x2()-$box->origin->x)*($box->y2()-$box->origin->y);
            if(Compression::exceeds(Compression::pressure($loads[$index],$footprint),$limit))
                return ['crush_violation',$unit->label];
        }
        return null;
    }

    /**
     * Walked iteratively in the load order topLoads uses: a child's top is above its
     * supporter's, so every child's set is complete before its supporter reads it. The
     * recursive walk spent one frame per stack level. Set keys are in no
     * promised order; a caller that names a member sorts first.
     *
     * @return list<array<int,true>>
     */
    public function restingAbove():array
    {
        if($this->restingAbove!==null)return $this->restingAbove;
        $count=count($this->units);
        if($count===0)return $this->restingAbove=[];
        $graph=$this->graph();
        $sets=array_fill(0,$count,[]);
        foreach($graph->descendingIndices() as $index){
            $result=[];
            foreach($graph->children($index) as $child)$result+=[$child=>true]+$sets[$child];
            $sets[$index]=$result;
        }
        return $this->restingAbove=$sets;
    }

    /**
     * Whether any stop is a real one. A scene where everything rides the whole route has
     * no order to violate, so callers ask this before building a graph at all.
     *
     * @param list<float> $stops
     */
    public static function routed(array $stops):bool
    {
        foreach($stops as $stop)if($stop!==INF)return true;
        return false;
    }

    /**
     * First unit due at an earlier stop with a later-stop unit resting above it, as
     * [code, detail]. `$stops` is parallel to the units; INF rides the whole route.
     *
     * @param list<float> $stops @return array{0:string,1:string}|null
     */
    public function routeOrderViolated(array $stops):?array
    {
        $count=count($this->units);
        if($count===0)return null;
        $graph=$this->graph();
        $maxStopAbove=array_fill(0,$count,-INF);
        foreach($graph->descendingIndices() as $index){
            $best=-INF;
            foreach($graph->children($index) as $child){
                $childStop=$stops[$child];
                if($childStop>$best)$best=$childStop;
                $childAbove=$maxStopAbove[$child];
                if($childAbove>$best)$best=$childAbove;
            }
            $maxStopAbove[$index]=$best;
        }
        foreach($stops as $index=>$stop){
            if($maxStopAbove[$index]<=$stop)continue;
            // Ascending, as Python iterates: the detail names the first violator (S16).
            $seen=[];
            $pending=$graph->children($index);
            $upperMin=null;
            while($pending!==[]){
                $upper=array_pop($pending);
                if(isset($seen[$upper]))continue;
                $seen[$upper]=true;
                if($stops[$upper]>$stop&&($upperMin===null||$upper<$upperMin))$upperMin=$upper;
                foreach($graph->children($upper) as $child)$pending[]=$child;
            }
            return ['unloading_order_violation',$this->units[$index]->label.' blocked by '.$this->units[$upperMin]->label];
        }
        return null;
    }

    /** @return list<int> */
    public function stackedCounts():array
    {
        return array_map(static function (array $above): int {
            return count($above);
        },$this->restingAbove());
    }

    /** @return array{0:string,1:string}|null */
    public function stackLimitExceeded():?array
    {
        $limited=false;
        foreach($this->units as $unit)if($unit->maxStackedItems!==null){$limited=true;break;}
        if(!$limited)return null;
        $counts=$this->stackedCounts();
        foreach($this->units as $index=>$unit)
            if($unit->maxStackedItems!==null&&$counts[$index]>$unit->maxStackedItems)return ['stacked_item_limit_exceeded',$unit->label];
        return null;
    }

    /** @return array{0:string,1:string}|null */
    public function stackDensityExceeded(?int $maxDensityTicks):?array
    {
        if($maxDensityTicks===null)return null;
        $loads=$this->topLoads();
        foreach($this->units as $index=>$unit){
            $total=$unit->weightTicks+$loads[$index];
            $bearing=BigInt::multiply($total,LoadCalculator::SQUARE_METRE_TICKS);
            $allowed=BigInt::multiply($maxDensityTicks,$unit->box->dimensions->baseAreaTicks());
            if(BigInt::compare($bearing,$allowed)>0)return ['stack_density_exceeded',$unit->label];
        }
        return null;
    }
}
