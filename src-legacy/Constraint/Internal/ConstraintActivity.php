<?php
declare(strict_types=1);
namespace Packvium\Constraint\Internal;

use Packvium\Constraint\AxleLoadConstraint;
use Packvium\Constraint\CompatibilityConstraint;
use Packvium\Constraint\ContainerEligibilityConstraint;
use Packvium\Constraint\FloorConstraint;
use Packvium\Constraint\PlacementConstraint;
use Packvium\Constraint\RouteOrderConstraint;
use Packvium\Constraint\StopAccessibilityConstraint;
use Packvium\Constraint\TagCountConstraint;
use Packvium\Constraint\TopLoadConstraint;
use Packvium\Domain\Container;
use Packvium\Domain\ItemInstance;

/**
 * Sweep-level activity specification for the built-in constraint chain.
 *
 * It deliberately knows only the built-ins created by ConstraintSet. A registered custom
 * constraint never matches and is therefore always evaluated. Keeping the specification
 * internal avoids adding an optimisation-only method to the frozen public constraint API.
 */
final class ConstraintActivity
{
    /** @param list<string> $defaultAccessDirections */
    public static function isInert(
        PlacementConstraint $constraint,
        Container $container,
        ItemInstance $item,
        bool $stackSensitive,
        bool $routeSensitive,
        array $defaultAccessDirections
    ): bool {
        if ($constraint instanceof FloorConstraint) {
            return !$item->item->mustBeOnFloor;
        }
        if ($constraint instanceof ContainerEligibilityConstraint) {
            $tags = $item->item->eligibleContainerTags;
            return $tags === [] || array_intersect($tags, $container->tags) !== [];
        }
        if ($constraint instanceof CompatibilityConstraint) {
            return $item->item->tags === [] && $item->item->incompatibleTags === [];
        }
        if ($constraint instanceof TagCountConstraint) {
            return $container->tagLimits === []
                || array_intersect($item->item->tags, array_keys($container->tagLimits)) === [];
        }
        if ($constraint instanceof TopLoadConstraint) {
            return !$stackSensitive;
        }
        if ($constraint instanceof RouteOrderConstraint) {
            return !$routeSensitive;
        }
        if ($constraint instanceof StopAccessibilityConstraint) {
            return !$routeSensitive
                || ($container->accessDirections === [] && $defaultAccessDirections === []);
        }
        if ($constraint instanceof AxleLoadConstraint) {
            return $container->axles === null;
        }
        return false;
    }
}
