<?php
declare(strict_types=1);
namespace Packvium\Constraint;
final class ConstraintSet
{/** @param list<PlacementConstraint> $custom @return list<PlacementConstraint> */public static function defaults(float $support,array $custom=[]):array{return array_merge([new FloorConstraint(), new ContainerEligibilityConstraint(), new CompatibilityConstraint(), new TagCountConstraint(), new SupportConstraint($support), new TopLoadConstraint(), new RouteOrderConstraint(), new AxleLoadConstraint()], $custom);}}
