<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Constraint\Internal\LoadSupportGraph;
/**
 * Support ratios arrive as floats from the public API but must never decide feasibility
 * in floating point. The ratio is converted once to a scaled integer and the comparison
 * below is exact. See docs/UNITS-AND-NUMERICS.md.
 */
final readonly class SupportConstraint implements PlacementConstraint
{
    public const SCALE=1_000_000;

    public function __construct(private float $globalMinimum=0.0){}

    /**
     * Whether this constraint can reject some non-floor placement of `$item`.
     *
     * Solver diagnostics use the query on every registered SupportConstraint,
     * including caller-supplied instances with their own global minimum, so an
     * inactive default constraint can be skipped without silently ignoring an active
     * custom one.
     *
     * This is a public capability query for solver diagnostics, not a separate
     * placement rule.
     */
    public function canReject(\Packvium\Domain\ItemInstance $item):bool
    {
        $rule=$item->item->groundContactRule;
        return ($rule!==null&&$rule!=='free')
            ||max($this->globalMinimum,$item->item->minimumSupportRatio)>0;
    }

    public function evaluate(ConstraintContext $c):ConstraintResult
    {
        if($c->point->z===0)return ConstraintResult::allow();
        if(!$this->canReject($c->item))return ConstraintResult::allow();
        $box=$c->envelopeBox();
        $support=LoadSupportGraph::candidateView($c->placements,$c->item,$box);
        $surfaces=$support->surfaces;
        $supporters=$support->supporterBoxes;
        $rule=$c->item->item->groundContactRule;
        if($rule!==null&&$rule!=='free'){
            $count=count($supporters);
            if($rule==='covered'&&!self::touchesCorners($box,$surfaces))
                return ConstraintResult::reject('ground_contact_violation',"covered: {$count} supporter(s), not all four corners touched");
            if($rule==='single'&&$count!==1)
                return ConstraintResult::reject('ground_contact_violation',"single: rests on {$count} item(s)");
            if($rule==='multiple'&&$count<2)
                return ConstraintResult::reject('ground_contact_violation',"multiple: rests on {$count} item(s)");
        }
        $required=max($this->globalMinimum,$c->item->item->minimumSupportRatio);
        if($required<=0)return ConstraintResult::allow();
        $area=$support->supportingArea;
        $baseArea=$c->envelopeDimensions->baseAreaTicks();
        if($area<self::requiredArea($baseArea,self::scaled($required)))
            return ConstraintResult::reject('insufficient_support',sprintf('%d/%d < %.6f',$area,$baseArea,$required));
        // Area alone is not stability: a candidate can clear the ratio
        // while overhanging its own centre of gravity, which the ratio check above
        // cannot see. Checked only once a minimum ratio is already being enforced,
        // so a caller who never asked for support checking sees no new rejection
        // code and no behaviour change.
        $hull=SupportPolygon::convexHull(SupportPolygon::contactHullPoints($box,$supporters));
        if(!SupportPolygon::pointInHull(SupportPolygon::doubledCentroid($box),$hull))
            return ConstraintResult::reject('centre_of_gravity_unsupported',sprintf('%d/%d met but centroid outside the %d-point support hull',$area,$baseArea,count($hull)));
        return ConstraintResult::allow();
    }

    /**
     * Whether every one of the candidate's four base corners rests on some surface.
     *
     * A ratio cannot express this: 90% of the footprint concentrated in the middle
     * passes any ratio below that but leaves every corner hanging.
     *
     * @param list<\Packvium\Domain\AxisAlignedBox> $surfaces
     */
    /**
     * Public, not private: `Sequence\LoadingDependencyGraph::verifyLoadingPrefixBusinessRules`
     * reuses this exact corner-touch calculation against every loading
     * prefix, not only the finished scene -- widened visibility only, no behaviour
     * change, so every existing caller of this constraint is unaffected.
     */
    public static function touchesCorners(\Packvium\Domain\AxisAlignedBox $box,array $surfaces):bool
    {
        $corners=[[$box->origin->x,$box->origin->y],[$box->x2(),$box->origin->y],[$box->origin->x,$box->y2()],[$box->x2(),$box->y2()]];
        foreach($corners as [$x,$y]){
            $touched=false;
            foreach($surfaces as $surface)
                if($surface->origin->x<=$x&&$x<=$surface->x2()&&$surface->origin->y<=$y&&$y<=$surface->y2()){$touched=true;break;}
            if(!$touched)return false;
        }
        return true;
    }

    public static function scaled(float $ratio):int{return (int)($ratio*self::SCALE+0.5);}

    /** Exact floor(baseArea * scaled / SCALE) without an overflowing product. */
    public static function requiredArea(int $baseArea,int $scaled):int
    {
        return intdiv($baseArea,self::SCALE)*$scaled+intdiv(($baseArea%self::SCALE)*$scaled,self::SCALE);
    }
}
