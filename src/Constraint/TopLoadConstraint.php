<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Constraint\Internal\LoadSupportGraph;
/**
 * Rejects a placement that would rest on something unable to carry it.
 *
 * Bearing limits are checked against the cumulative load of the whole stack, not only
 * the box directly underneath, so a tower of light items cannot crush its base.
 */
final class TopLoadConstraint implements PlacementConstraint
{
    public function evaluate(ConstraintContext $c):ConstraintResult
    {
        if(!$c->stackSensitive)return ConstraintResult::allow();
        $candidate=$c->envelopeBox();
        $item=$c->item->item;
        $units=LoadCalculator::units(
            $c->placements,
            new LoadUnit(
                $candidate,$c->item->weight()->ticks,$item->maxTopLoad?->ticks,$item->maxStackedItems,$c->item->id(),
                $item->nestingHeight===null?null:$item->id,$item->nestingHeight?->ticks,
            ),
        );
        $graph=new LoadSupportGraph($units);
        $nonStackable=$graph->nonStackableFailure($c->placements,$c->item,count($units)-1);
        if($nonStackable!==null)return ConstraintResult::reject($nonStackable[0],$nonStackable[1]);
        $densityLimit=$c->container->maxStackDensity?->ticks;
        $failure=LoadCalculator::overloaded($units)??LoadCalculator::stackLimitExceeded($units)??LoadCalculator::stackDensityExceeded($units,$densityLimit);
        return $failure===null?ConstraintResult::allow():ConstraintResult::reject($failure[0],$failure[1]);
    }
}
