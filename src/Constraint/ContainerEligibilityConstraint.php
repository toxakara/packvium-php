<?php
declare(strict_types=1);
namespace Packvium\Constraint;
/**
 * Rejects a placement into a container the item did not opt into.
 *
 * An item with no `eligibleContainerTags` may go anywhere, matching the same
 * "empty means unconstrained" convention as `Item::$tags`/`$incompatibleTags`.
 */
final class ContainerEligibilityConstraint implements PlacementConstraint
{
    public function evaluate(ConstraintContext $c):ConstraintResult
    {
        $item=$c->item->item;
        if($item->eligibleContainerTags===[])return ConstraintResult::allow();
        if(array_intersect($item->eligibleContainerTags,$c->container->tags)!==[])return ConstraintResult::allow();
        return ConstraintResult::reject('container_ineligible',$c->container->id);
    }
}
