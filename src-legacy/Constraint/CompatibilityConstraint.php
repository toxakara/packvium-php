<?php
declare(strict_types=1);
namespace Packvium\Constraint;
final class CompatibilityConstraint implements PlacementConstraint{public function evaluate(ConstraintContext $c):ConstraintResult{$item=$c->item->item;foreach($c->placements as $p){$other=$p->instance->item;if(array_intersect($item->incompatibleTags,$other->tags)||array_intersect($other->incompatibleTags,$item->tags))return ConstraintResult::reject('incompatible_items',"{$item->id} is incompatible with {$other->id}");}return ConstraintResult::allow();}}
