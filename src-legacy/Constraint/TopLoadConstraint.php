<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Constraint\Internal\{LoadAnalysis,PlacedSupportBase};
use Packvium\Support\ExactInteger;
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
        return ($this->support = $this->support ?? new PlacedSupportBase())->evaluateTopLoad($c);
    }
}
