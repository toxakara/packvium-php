<?php
declare(strict_types=1);
namespace Packvium\Constraint;

/**
 * Rejects a placement that would push either of a two-axle container's axles over
 * its own limit.
 *
 * A no-op unless `Container::$axles` is set: axle enforcement is opt-in, matching
 * every other whole-container weight rule in this namespace, so a caller who never
 * configured axles sees no new rejection code and no behaviour change.
 */
final class AxleLoadConstraint implements PlacementConstraint
{
    public function evaluate(ConstraintContext $c): ConstraintResult
    {
        if ($c->container->axles === null) {
            return ConstraintResult::allow();
        }
        $candidate = $c->envelopeBox();
        $units = LoadCalculator::units(
            $c->placements,
            new LoadUnit(
                $candidate, $c->item->weight()->ticks, null, null, $c->item->id(),
                $c->item->item->nestingHeight===null?null:$c->item->item->id,
                ($nullsafeVariable1 = $c->item->item->nestingHeight) ? $nullsafeVariable1->ticks : null,
            ),
        );
        $failure = AxleLoad::exceeded(
            $c->container->axles,
            $units,
            $c->container->tareWeight->ticks,
            $c->container->innerDimensions->length->ticks,
        );
        return $failure === null ? ConstraintResult::allow() : ConstraintResult::reject($failure[0], $failure[1]);
    }
}
