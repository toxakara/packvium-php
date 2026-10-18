<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Constraint\Internal\{LoadAnalysis,PlacedSupportBase};
/**
 * Rejects a placement that would bury an item due off at an earlier stop.
 *
 * A no-op unless some item declares a `stopIndex`: route enforcement is opt-in, matching
 * every other optional rule in this namespace, so a request that never mentions a route
 * sees no new rejection code and no behaviour change.
 */
final class RouteOrderConstraint implements PlacementConstraint
{
    // Its own base rather than a rebuild per candidate: 60 routed placements once cost
    // thousands of graph constructions. Created on first use, as in
    // TopLoadConstraint, so the frozen public surface keeps no constructor.
    private ?PlacedSupportBase $support=null;

    public function evaluate(ConstraintContext $c):ConstraintResult
    {
        if(!$c->routeSensitive)return ConstraintResult::allow();
        $candidate=new LoadUnit(
            $c->envelopeBox(),$c->item->weight()->ticks,null,null,$c->item->id(),
            $c->item->item->nestingHeight===null?null:$c->item->item->id,$c->item->item->nestingHeight?->ticks,
        );
        $stops=[];
        foreach($c->placements as $p)$stops[]=$p->instance->item->stopIndex===null?INF:(float)$p->instance->item->stopIndex;
        $stops[]=$c->item->item->stopIndex===null?INF:(float)$c->item->item->stopIndex;
        if(!LoadAnalysis::routed($stops))return ConstraintResult::allow();
        [$units,$graph]=($this->support??=new PlacedSupportBase())->withCandidate($c->placements,$candidate);
        $failure=(new LoadAnalysis($units,$graph))->routeOrderViolated($stops);
        return $failure===null?ConstraintResult::allow():ConstraintResult::reject($failure[0],$failure[1]);
    }
}
