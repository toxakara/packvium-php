<?php
declare(strict_types=1);
namespace Packvium\Constraint;
/**
 * Rejects a placement that would bury an item due off at an earlier stop.
 *
 * A no-op unless some item declares a `stopIndex`: route enforcement is opt-in, matching
 * every other optional rule in this namespace, so a request that never mentions a route
 * sees no new rejection code and no behaviour change.
 */
final class RouteOrderConstraint implements PlacementConstraint
{
    public function evaluate(ConstraintContext $c):ConstraintResult
    {
        if(!$c->routeSensitive)return ConstraintResult::allow();
        $units=LoadCalculator::units(
            $c->placements,
            new LoadUnit(
                $c->envelopeBox(),$c->item->weight()->ticks,null,null,$c->item->id(),
                $c->item->item->nestingHeight===null?null:$c->item->item->id,$c->item->item->nestingHeight?->ticks,
            ),
        );
        $stops=[];
        foreach($c->placements as $p)$stops[]=$p->instance->item->stopIndex===null?INF:(float)$p->instance->item->stopIndex;
        $stops[]=$c->item->item->stopIndex===null?INF:(float)$c->item->item->stopIndex;
        $failure=LoadCalculator::routeOrderViolated($units,$stops);
        return $failure===null?ConstraintResult::allow():ConstraintResult::reject($failure[0],$failure[1]);
    }
}
