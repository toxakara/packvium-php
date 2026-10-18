<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Constraint\Internal\{LoadAnalysis,PlacedSupportBase};
/**
 * Rejects a placement that would rest on something unable to carry it.
 *
 * Bearing limits are checked against the cumulative load of the whole stack, not only
 * the box directly underneath, so a tower of light items cannot crush its base. The graph
 * comes from a PlacedSupportBase, so it is not rebuilt per candidate.
 */
final class TopLoadConstraint implements PlacementConstraint
{
    // Created on first use rather than in a constructor, which would widen the frozen
    // public surface (spec/api/php.json) for an internal cache.
    /**
     * @var \Packvium\Constraint\Internal\PlacedSupportBase|null
     */
    private $support;

    public function evaluate(ConstraintContext $c):ConstraintResult
    {
        if(!$c->stackSensitive)return ConstraintResult::allow();
        $item=$c->item->item;
        $unit=new LoadUnit(
            $c->envelopeBox(),$c->item->weight()->ticks,($nullsafeVariable1 = $item->maxTopLoad) ? $nullsafeVariable1->ticks : null,$item->maxStackedItems,$c->item->id(),
            $item->nestingHeight===null?null:$item->id,($nullsafeVariable2 = $item->nestingHeight) ? $nullsafeVariable2->ticks : null,
            $item->compressionRatioPpm,$item->maxCompressionPressureKpa,
        );
        [$units,$graph]=($this->support = $this->support ?? new PlacedSupportBase())->withCandidate($c->placements,$unit);
        $nonStackable=$graph->nonStackableFailure($c->placements,$c->item,count($units)-1);
        if($nonStackable!==null)return ConstraintResult::reject($nonStackable[0],$nonStackable[1]);
        $densityLimit=($nullsafeVariable3 = $c->container->maxStackDensity) ? $nullsafeVariable3->ticks : null;
        $analysis=new LoadAnalysis($units,$graph);
        $failure=$analysis->overloaded()
            ??$analysis->crushed()
            ??$analysis->stackLimitExceeded()
            ??$analysis->stackDensityExceeded($densityLimit);
        return $failure===null?ConstraintResult::allow():ConstraintResult::reject($failure[0],$failure[1]);
    }
}
