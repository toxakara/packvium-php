<?php
declare(strict_types=1);
namespace Packvium\Constraint;
interface PlacementConstraint{public function evaluate(ConstraintContext $context):ConstraintResult;}
