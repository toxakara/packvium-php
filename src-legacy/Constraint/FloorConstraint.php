<?php
declare(strict_types=1);
namespace Packvium\Constraint;
final class FloorConstraint implements PlacementConstraint{public function evaluate(ConstraintContext $c):ConstraintResult{return $c->item->item->mustBeOnFloor&&$c->point->z!==0?ConstraintResult::reject('must_be_on_floor'):ConstraintResult::allow();}}
