<?php
declare(strict_types=1);
namespace Packvium\Constraint\Internal;
use Packvium\Constraint\{ConstraintContext,ConstraintResult,LoadCalculator,LoadUnit};
use Packvium\Domain\{Compression,Placement};
use Packvium\Support\{BigInt,ExactGeometry,ExactInteger};

/**
 * The support graph over one placement list, with each candidate appended to it.
 *
 * The support graph over the *placed* boxes is the same for every candidate evaluated
 * against one search state, and rebuilding it per candidate was the cost
 * removes. Every rule that reads the graph -- bearing and route order -- keeps
 * one of these. The cache is deliberately a single entry: search evaluates a run of
 * candidates against one state before moving on, so one entry captures the whole run.
 */
final class PlacedSupportBase
{
    /** @var list<Placement>|null */
    private ?array $placements=null;
    private ?LoadSupportGraph $base=null;
    /** @var list<LoadUnit> */
    private array $baseUnits=[];
    private int $hint=1;
    /** @var list<int>|null */
    private ?array $baseLoads=null;
    /** @var list<int>|null */
    private ?array $baseStackedCounts=null;
    private bool $baseHasMaxTop=false;
    private bool $baseHasCrush=false;
    private bool $baseHasMaxStacked=false;
    private ?bool $baseBearingHolds=null;
    private ?int $baseDensityChecked=null;
    private ?bool $baseDensityHolds=null;
    /** @var list<float>|null */
    private ?array $baseStops=null;
    private bool $baseRouted=false;
    /** @var list<float>|null */
    private ?array $baseMaxStopAbove=null;
    private ?bool $baseRouteHolds=null;

    /**
     * The support graph over `$placements` alone, rebuilt only when it cannot serve.
     *
     * The cell hint has to cover every candidate that will be appended to this base, and
     * the widest of them is not known in advance -- a candidate is a *new* item and may
     * be the widest in the request. So the hint grows to fit the first candidate that
     * needs it and the base is rebuilt that once; after that the run is served from
     * cache. Sizing it from the container instead would always be safe and always
     * coarse, and a cell far larger than the boxes collapses the spatial hash back into
     * the all-pairs scan it exists to avoid.
     *
     * @param list<Placement> $placements
     */
    private function baseFor(array $placements,int $footprint):LoadSupportGraph
    {
        $same=$this->base!==null&&$this->placements===$placements;
        if($same&&$footprint<=$this->hint)return $this->base;
        $this->hint=max($same?$this->hint:1,$footprint);
        $this->placements=$placements;
        if(!$same)$this->baseUnits=LoadCalculator::units($placements);
        $this->base=new LoadSupportGraph($this->baseUnits,$this->hint);
        if(!$same){
            $this->baseLoads=null;
            $this->baseStackedCounts=null;
            $this->baseBearingHolds=null;
            $this->baseDensityChecked=null;
            $this->baseDensityHolds=null;
            $this->baseStops=null;
            $this->baseRouted=false;
            $this->baseMaxStopAbove=null;
            $this->baseRouteHolds=null;
        }
        return $this->base;
    }

    private function settleBearing(?int $densityLimit):bool
    {
        assert($this->base!==null);
        $baseUnits=$this->baseUnits;
        if($this->baseBearingHolds===null){
            $hasMaxTop=false;$hasCrush=false;$hasMaxStacked=false;
            foreach($baseUnits as $u){
                if($u->maxTopLoadTicks!==null)$hasMaxTop=true;
                if($u->maxCompressionPressureKpa!==null)$hasCrush=true;
                if($u->maxStackedItems!==null)$hasMaxStacked=true;
            }
            $this->baseHasMaxTop=$hasMaxTop;
            $this->baseHasCrush=$hasCrush;
            $this->baseHasMaxStacked=$hasMaxStacked;
            $analysis=new LoadAnalysis($baseUnits,$this->base);
            $loads=$analysis->topLoads();
            $this->baseLoads=$loads;
            $holds=$analysis->overloaded()===null&&$analysis->crushed()===null;
            if($hasMaxStacked){
                $counts=$analysis->stackedCounts();
                $this->baseStackedCounts=$counts;
                if($holds){
                    foreach($baseUnits as $idx=>$u)
                        if($u->maxStackedItems!==null&&$counts[$idx]>$u->maxStackedItems){$holds=false;break;}
                }
            }
            $this->baseBearingHolds=$holds;
        }
        if(!$this->baseBearingHolds)return false;
        if($densityLimit===null)return true;
        if($this->baseDensityChecked!==$densityLimit||$this->baseDensityHolds===null){
            assert($this->baseLoads!==null);
            $this->baseDensityChecked=$densityLimit;
            $holds=true;
            foreach($baseUnits as $idx=>$u){
                $total=$u->weightTicks+$this->baseLoads[$idx];
                $bearing=BigInt::multiply($total,LoadCalculator::SQUARE_METRE_TICKS);
                $allowed=BigInt::multiply($densityLimit,ExactGeometry::baseArea($u->box->dimensions));
                if(BigInt::compare($bearing,$allowed)>0){$holds=false;break;}
            }
            $this->baseDensityHolds=$holds;
        }
        return $this->baseDensityHolds;
    }

    public function evaluateTopLoad(ConstraintContext $c):ConstraintResult
    {
        $candidate=$c->envelopeBox();
        $item=$c->item->item;
        $footprint=max($candidate->x2()-$candidate->origin->x,$candidate->y2()-$candidate->origin->y);
        $base=$this->baseFor($c->placements,$footprint);
        $baseUnits=$this->baseUnits;
        $densityLimit=$c->container->maxStackDensity?->ticks;
        if(!$base->isNested()&&$item->nestingHeight===null&&$this->settleBearing($densityLimit)){
            $below=$base->contactsBelow($candidate);
            $above=$base->contactsAbove($candidate);
            $upperGroup=$item->stackingGroup;
            $placements=$c->placements;
            foreach($below as $edge){
                $lower=$placements[$edge->index]->instance->item;
                $code=LoadSupportGraph::stackingRefusal($lower,$upperGroup);
                if($code!==null)return ConstraintResult::reject($code,$lower->id);
            }
            if(!$item->stackable||$item->acceptsStackingGroups!==[]){
                foreach($above as $edge){
                    $code=LoadSupportGraph::stackingRefusal($item,$placements[$edge->index]->instance->item->stackingGroup);
                    if($code!==null)return ConstraintResult::reject($code,$item->id);
                }
            }
            $hasMaxTop=$this->baseHasMaxTop||$item->maxTopLoad!==null;
            $hasCrush=$this->baseHasCrush||$item->maxCompressionPressureKpa!==null;
            $hasMaxStacked=$this->baseHasMaxStacked||$item->maxStackedItems!==null;
            if(!$hasMaxTop&&!$hasCrush&&!$hasMaxStacked&&$densityLimit===null)return ConstraintResult::allow();
            if($below===[]&&$above===[]){
                if($densityLimit!==null){
                    $bearing=BigInt::multiply($c->item->weight()->ticks,LoadCalculator::SQUARE_METRE_TICKS);
                    $allowed=BigInt::multiply($densityLimit,ExactGeometry::baseArea($candidate->dimensions));
                    if(BigInt::compare($bearing,$allowed)>0)return ConstraintResult::reject('stack_density_exceeded',$c->item->id());
                }
                return ConstraintResult::allow();
            }
            if($above===[]){
                $baseLoads=$this->baseLoads;
                assert($baseLoads!==null);
                $allFloor=true;
                foreach($below as $edge)if($base->supporters($edge->index)!==[]){$allFloor=false;break;}
                if($allFloor){
                    $downward=$c->item->weight()->ticks;
                    $totalArea=0;
                    foreach($below as $edge)$totalArea=ExactInteger::add($totalArea,$edge->area);
                    $assigned=0;$lastPos=count($below)-1;
                    $shares=[];
                    foreach($below as $pos=>$edge){
                        if($totalArea!==0&&$downward>0){
                            $share=$pos===$lastPos?$downward-$assigned:ExactInteger::mulDiv($downward,$edge->area,$totalArea);
                            $assigned+=$share;
                        }else $share=0;
                        $shares[]=$share;
                    }
                    if($this->baseHasMaxTop){
                        foreach($below as $pos=>$edge){
                            $u=$edge->index;
                            $limitTicks=$baseUnits[$u]->maxTopLoadTicks;
                            if($limitTicks!==null&&$baseLoads[$u]+$shares[$pos]>$limitTicks)
                                return ConstraintResult::reject('top_load_exceeded',$baseUnits[$u]->label);
                        }
                    }
                    if($this->baseHasCrush){
                        foreach($below as $pos=>$edge){
                            $unitU=$baseUnits[$edge->index];
                            $limitKpa=$unitU->maxCompressionPressureKpa;
                            if($limitKpa===null)continue;
                            $boxU=$unitU->box;
                            $fp=$unitU->footprintTicks2??ExactInteger::mul($boxU->x2()-$boxU->origin->x,$boxU->y2()-$boxU->origin->y);
                            if(Compression::exceeds(Compression::pressure($baseLoads[$edge->index]+$shares[$pos],$fp),$limitKpa))
                                return ConstraintResult::reject('crush_violation',$unitU->label);
                        }
                    }
                    if($this->baseHasMaxStacked){
                        assert($this->baseStackedCounts!==null);
                        foreach($below as $edge){
                            $u=$edge->index;
                            $maxStacked=$baseUnits[$u]->maxStackedItems;
                            if($maxStacked!==null&&$this->baseStackedCounts[$u]+1>$maxStacked)
                                return ConstraintResult::reject('stacked_item_limit_exceeded',$baseUnits[$u]->label);
                        }
                    }
                    if($densityLimit!==null){
                        foreach($below as $pos=>$edge){
                            $u=$edge->index;
                            $unitU=$baseUnits[$u];
                            $bearing=BigInt::multiply($unitU->weightTicks+$baseLoads[$u]+$shares[$pos],LoadCalculator::SQUARE_METRE_TICKS);
                            $allowed=BigInt::multiply($densityLimit,ExactGeometry::baseArea($unitU->box->dimensions));
                            if(BigInt::compare($bearing,$allowed)>0)return ConstraintResult::reject('stack_density_exceeded',$unitU->label);
                        }
                        $bearing=BigInt::multiply($downward,LoadCalculator::SQUARE_METRE_TICKS);
                        $allowed=BigInt::multiply($densityLimit,ExactGeometry::baseArea($candidate->dimensions));
                        if(BigInt::compare($bearing,$allowed)>0)return ConstraintResult::reject('stack_density_exceeded',$c->item->id());
                    }
                    return ConstraintResult::allow();
                }

                $pending=[];$seenAffected=[];
                foreach($below as $edge){$pending[]=$edge->index;$seenAffected[$edge->index]=true;}
                $idx=0;
                while($idx<count($pending)){
                    $u=$pending[$idx++];
                    foreach($base->supporters($u) as $edge){
                        if(!isset($seenAffected[$edge->index])){
                            $seenAffected[$edge->index]=true;
                            $pending[]=$edge->index;
                        }
                    }
                }
                $deltaLoads=[];
                if($hasMaxTop||$hasCrush||$densityLimit!==null){
                    $downward=$c->item->weight()->ticks;
                    $totalArea=0;
                    foreach($below as $edge)$totalArea=ExactInteger::add($totalArea,$edge->area);
                    if($totalArea!==0&&$downward>0){
                        $assigned=0;$lastPos=count($below)-1;
                        foreach($below as $pos=>$edge){
                            $share=$pos===$lastPos?$downward-$assigned:ExactInteger::mulDiv($downward,$edge->area,$totalArea);
                            $assigned+=$share;
                            $deltaLoads[$edge->index]=($deltaLoads[$edge->index]??0)+$share;
                        }
                    }
                    $order=array_keys($seenAffected);
                    usort($order,static fn(int $a,int $b):int=>[-$baseUnits[$a]->box->z2(),-$baseUnits[$a]->box->origin->z,$a]<=>[-$baseUnits[$b]->box->z2(),-$baseUnits[$b]->box->origin->z,$b]);
                    foreach($order as $u){
                        $dLoad=$deltaLoads[$u]??0;
                        if($dLoad===0)continue;
                        $supports=$base->supporters($u);
                        if($supports===[])continue;
                        $totalArea=0;
                        foreach($supports as $edge)$totalArea=ExactInteger::add($totalArea,$edge->area);
                        if($totalArea===0)continue;
                        $oldDownward=$baseUnits[$u]->weightTicks+$baseLoads[$u];
                        $newDownward=$oldDownward+$dLoad;
                        $oldAssigned=0;$newAssigned=0;$lastPos=count($supports)-1;
                        foreach($supports as $pos=>$edge){
                            if($pos===$lastPos){
                                $oldShare=$oldDownward-$oldAssigned;
                                $newShare=$newDownward-$newAssigned;
                            }else{
                                $oldShare=ExactInteger::mulDiv($oldDownward,$edge->area,$totalArea);
                                $newShare=ExactInteger::mulDiv($newDownward,$edge->area,$totalArea);
                                $oldAssigned+=$oldShare;
                                $newAssigned+=$newShare;
                            }
                            $diff=$newShare-$oldShare;
                            if($diff!==0)$deltaLoads[$edge->index]=($deltaLoads[$edge->index]??0)+$diff;
                        }
                    }
                }
                $ascending=array_keys($seenAffected);
                sort($ascending);
                if($this->baseHasMaxTop){
                    foreach($ascending as $u){
                        $limitTicks=$baseUnits[$u]->maxTopLoadTicks;
                        if($limitTicks!==null&&$baseLoads[$u]+($deltaLoads[$u]??0)>$limitTicks)
                            return ConstraintResult::reject('top_load_exceeded',$baseUnits[$u]->label);
                    }
                }
                if($this->baseHasCrush){
                    foreach($ascending as $u){
                        $unitU=$baseUnits[$u];
                        $limitKpa=$unitU->maxCompressionPressureKpa;
                        if($limitKpa===null)continue;
                        $boxU=$unitU->box;
                        $fp=$unitU->footprintTicks2??ExactInteger::mul($boxU->x2()-$boxU->origin->x,$boxU->y2()-$boxU->origin->y);
                        if(Compression::exceeds(Compression::pressure($baseLoads[$u]+($deltaLoads[$u]??0),$fp),$limitKpa))
                            return ConstraintResult::reject('crush_violation',$unitU->label);
                    }
                }
                if($this->baseHasMaxStacked){
                    assert($this->baseStackedCounts!==null);
                    foreach($ascending as $u){
                        $maxStacked=$baseUnits[$u]->maxStackedItems;
                        if($maxStacked!==null&&$this->baseStackedCounts[$u]+1>$maxStacked)
                            return ConstraintResult::reject('stacked_item_limit_exceeded',$baseUnits[$u]->label);
                    }
                }
                if($densityLimit!==null){
                    foreach($ascending as $u){
                        $unitU=$baseUnits[$u];
                        $bearing=BigInt::multiply($unitU->weightTicks+$baseLoads[$u]+($deltaLoads[$u]??0),LoadCalculator::SQUARE_METRE_TICKS);
                        $allowed=BigInt::multiply($densityLimit,ExactGeometry::baseArea($unitU->box->dimensions));
                        if(BigInt::compare($bearing,$allowed)>0)return ConstraintResult::reject('stack_density_exceeded',$unitU->label);
                    }
                    $bearing=BigInt::multiply($c->item->weight()->ticks,LoadCalculator::SQUARE_METRE_TICKS);
                    $allowed=BigInt::multiply($densityLimit,ExactGeometry::baseArea($candidate->dimensions));
                    if(BigInt::compare($bearing,$allowed)>0)return ConstraintResult::reject('stack_density_exceeded',$c->item->id());
                }
                return ConstraintResult::allow();
            }
        }

        $unit=new LoadUnit(
            $candidate,$c->item->weight()->ticks,$item->maxTopLoad?->ticks,$item->maxStackedItems,$c->item->id(),
            $item->nestingHeight===null?null:$item->id,$item->nestingHeight?->ticks,
            $item->compressionRatioPpm,$item->maxCompressionPressureKpa,
            ExactInteger::mul($c->dimensions->length->ticks,$c->dimensions->width->ticks),
        );
        $units=$baseUnits;
        $units[]=$unit;
        $graph=$base->withUnit($unit,$this->hint);
        $nonStackable=$graph->nonStackableFailure($c->placements,$c->item,count($units)-1);
        if($nonStackable!==null)return ConstraintResult::reject($nonStackable[0],$nonStackable[1]);
        $analysis=new LoadAnalysis($units,$graph);
        $failure=$analysis->overloaded()
            ??$analysis->crushed()
            ??$analysis->stackLimitExceeded()
            ??$analysis->stackDensityExceeded($densityLimit);
        return $failure===null?ConstraintResult::allow():ConstraintResult::reject($failure[0],$failure[1]);
    }

    /** @param list<Placement> $placements */
    private function settleRoute(array $placements):void
    {
        assert($this->base!==null);
        if($this->baseStops!==null)return;
        $stops=[];$routed=false;
        foreach($placements as $p){
            $stop=$p->instance->item->stopIndex===null?INF:(float)$p->instance->item->stopIndex;
            if($stop!==INF)$routed=true;
            $stops[]=$stop;
        }
        $this->baseStops=$stops;
        $this->baseRouted=$routed;
        $count=count($stops);
        $maxStopAbove=$count===0?[]:array_fill(0,$count,-INF);
        if($routed&&$count>0){
            foreach($this->base->descendingIndices() as $index){
                $best=-INF;
                foreach($this->base->children($index) as $child){
                    $childStop=$stops[$child];
                    if($childStop>$best)$best=$childStop;
                    $childAbove=$maxStopAbove[$child];
                    if($childAbove>$best)$best=$childAbove;
                }
                $maxStopAbove[$index]=$best;
            }
            $holds=true;
            foreach($stops as $index=>$stop)
                if($maxStopAbove[$index]>$stop){$holds=false;break;}
            $this->baseRouteHolds=$holds;
        }else{
            $this->baseRouteHolds=true;
        }
        $this->baseMaxStopAbove=$maxStopAbove;
    }

    public function evaluateRouteOrder(ConstraintContext $c):ConstraintResult
    {
        $box=$c->envelopeBox();
        $footprint=max($box->x2()-$box->origin->x,$box->y2()-$box->origin->y);
        $base=$this->baseFor($c->placements,$footprint);
        $baseUnits=$this->baseUnits;
        $this->settleRoute($c->placements);
        assert($this->baseStops!==null&&$this->baseMaxStopAbove!==null);
        $candidateStop=$c->item->item->stopIndex===null?INF:(float)$c->item->item->stopIndex;
        if(!$this->baseRouted&&$candidateStop===INF)return ConstraintResult::allow();
        if(!$base->isNested()&&$c->item->item->nestingHeight===null&&$this->baseRouteHolds){
            $below=$base->contactsBelow($box);
            $above=$base->contactsAbove($box);
            if($below===[]&&$above===[])return ConstraintResult::allow();
            $baseStops=$this->baseStops;
            $baseMaxAbove=$this->baseMaxStopAbove;
            $violated=false;
            foreach($below as $edge){
                if($candidateStop>$baseStops[$edge->index]){$violated=true;break;}
            }
            if(!$violated){
                foreach($above as $edge){
                    $ch=$edge->index;
                    if($baseStops[$ch]>$candidateStop||$baseMaxAbove[$ch]>$candidateStop){$violated=true;break;}
                }
            }
            if(!$violated)return ConstraintResult::allow();
            if($above===[]){
                $pending=[];$seen=[];
                foreach($below as $edge){$pending[]=$edge->index;$seen[$edge->index]=true;}
                $idx=0;$offender=null;
                while($idx<count($pending)){
                    $u=$pending[$idx++];
                    if($baseStops[$u]<$candidateStop&&($offender===null||$u<$offender))$offender=$u;
                    foreach($base->supporters($u) as $edge){
                        if(!isset($seen[$edge->index])){
                            $seen[$edge->index]=true;
                            $pending[]=$edge->index;
                        }
                    }
                }
                assert($offender!==null);
                return ConstraintResult::reject(
                    'unloading_order_violation',
                    $baseUnits[$offender]->label.' blocked by '.$c->item->id(),
                );
            }
        }
        $candidate=new LoadUnit(
            $box,$c->item->weight()->ticks,null,null,$c->item->id(),
            $c->item->item->nestingHeight===null?null:$c->item->item->id,$c->item->item->nestingHeight?->ticks,
        );
        $stops=$this->baseStops;
        $stops[]=$candidateStop;
        $units=$baseUnits;
        $units[]=$candidate;
        $graph=$base->withUnit($candidate,$this->hint);
        $failure=(new LoadAnalysis($units,$graph))->routeOrderViolated($stops);
        return $failure===null?ConstraintResult::allow():ConstraintResult::reject($failure[0],$failure[1]);
    }
}
