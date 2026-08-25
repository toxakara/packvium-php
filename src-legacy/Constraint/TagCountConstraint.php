<?php
declare(strict_types=1);
namespace Packvium\Constraint;
/**
 * Caps how many items carrying a tag may share one container.
 *
 * A group is atomic but cannot express a count: "at most two of this class per
 * container" is a policy about the container, not about any one item's neighbours,
 * so it lives on `Container::$tagLimits` rather than on the item.
 */
final class TagCountConstraint implements PlacementConstraint
{
    public function evaluate(ConstraintContext $c):ConstraintResult
    {
        $limits=$c->container->tagLimits;
        if($limits===[])return ConstraintResult::allow();
        $relevant=array_intersect($c->item->item->tags,array_keys($limits));
        foreach($relevant as $tag){
            $limit=$limits[$tag];
            $count=0;
            foreach($c->placements as $p)if(in_array($tag,$p->instance->item->tags,true))$count++;
            if($count+1>$limit)return ConstraintResult::reject('tag_count_exceeded',"{$tag}: limit {$limit}, would be ".($count+1));
        }
        return ConstraintResult::allow();
    }
}
