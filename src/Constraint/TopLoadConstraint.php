<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Constraint\Internal\{LoadAnalysis,LoadSupportGraph};
use Packvium\Domain\Placement;
/**
 * Rejects a placement that would rest on something unable to carry it.
 *
 * Bearing limits are checked against the cumulative load of the whole stack, not only
 * the box directly underneath, so a tower of light items cannot crush its base.
 *
 * The support graph over the *placed* boxes is the same for every candidate evaluated
 * against one search state, and rebuilding it per candidate was the cost 
 * removes. One base per placement list is kept here and each candidate is appended to
 * it. The cache is deliberately a single entry: search evaluates a run of candidates
 * against one state before moving on, so one entry captures the whole run.
 */
final class TopLoadConstraint implements PlacementConstraint
{
    /** @var list<Placement>|null */
    private ?array $placements=null;
    private ?LoadSupportGraph $base=null;
    /** @var list<LoadUnit> */
    private array $baseUnits=[];
    private int $hint=1;

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
        $this->baseUnits=LoadCalculator::units($placements);
        $this->base=new LoadSupportGraph($this->baseUnits,$this->hint);
        return $this->base;
    }

    public function evaluate(ConstraintContext $c):ConstraintResult
    {
        if(!$c->stackSensitive)return ConstraintResult::allow();
        $candidate=$c->envelopeBox();
        $item=$c->item->item;
        $unit=new LoadUnit(
            $candidate,$c->item->weight()->ticks,$item->maxTopLoad?->ticks,$item->maxStackedItems,$c->item->id(),
            $item->nestingHeight===null?null:$item->id,$item->nestingHeight?->ticks,
            $item->compressionRatioPpm,$item->maxCompressionPressureKpa,
        );
        $footprint=max($candidate->x2()-$candidate->origin->x,$candidate->y2()-$candidate->origin->y);
        $base=$this->baseFor($c->placements,$footprint);
        $units=$this->baseUnits;
        $units[]=$unit;
        $graph=$base->withUnit($unit,$this->hint);
        $nonStackable=$graph->nonStackableFailure($c->placements,$c->item,count($units)-1);
        if($nonStackable!==null)return ConstraintResult::reject($nonStackable[0],$nonStackable[1]);
        $densityLimit=$c->container->maxStackDensity?->ticks;
        $analysis=new LoadAnalysis($units,$graph);
        $failure=$analysis->overloaded()
            ??$analysis->crushed()
            ??$analysis->stackLimitExceeded()
            ??$analysis->stackDensityExceeded($densityLimit);
        return $failure===null?ConstraintResult::allow():ConstraintResult::reject($failure[0],$failure[1]);
    }
}
