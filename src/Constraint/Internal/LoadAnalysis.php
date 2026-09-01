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
    private array $units;
    private LoadSupportGraph $graph;
    /** @var list<int>|null */
    private ?array $topLoads=null;
    /** @var list<array<int,true>>|null */
    private ?array $restingAbove=null;

    /** @param list<LoadUnit> $units */
    public function __construct(array $units,LoadSupportGraph $graph)
    {
        $this->units=$units;
        $this->graph=$graph;
    }

    /** @return list<int> */
    public function topLoads():array
    {
        if($this->topLoads!==null)return $this->topLoads;
        $count=count($this->units);
        if($count===0)return $this->topLoads=[];
        $loads=array_fill(0,$count,0);
        $order=range(0,$count-1);
        usort($order,fn(int $a,int $b):int=>[-$this->units[$a]->box->z2(),-$this->units[$a]->box->origin->z,$a]<=>[-$this->units[$b]->box->z2(),-$this->units[$b]->box->origin->z,$b]);
        foreach($order as $upperIndex){
            $supports=$this->graph->supporters($upperIndex);
            $totalArea=array_sum(array_map(static fn(ContactEdge $edge):int=>$edge->area,$supports));
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

    /** @return list<array<int,true>> */
    public function restingAbove():array
    {
        if($this->restingAbove!==null)return $this->restingAbove;
        $memo=[];
        $aboveSet=function(int $index) use (&$aboveSet,&$memo):array{
            if(isset($memo[$index]))return $memo[$index];
            $result=[];
            foreach($this->graph->children($index) as $child){
                $result[$child]=true;
                foreach($aboveSet($child) as $ancestor=>$_)$result[$ancestor]=true;
            }
            return $memo[$index]=$result;
        };
        $sets=[];
        for($index=0,$count=count($this->units);$index<$count;$index++)$sets[]=$aboveSet($index);
        return $this->restingAbove=$sets;
    }

    /** @return list<int> */
    public function stackedCounts():array
    {
        return array_map(static fn(array $above):int=>count($above),$this->restingAbove());
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
