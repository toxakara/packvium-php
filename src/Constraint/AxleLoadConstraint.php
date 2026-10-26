<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Constraint\Internal\AxleTotals;
use Packvium\Domain\{Container,Placement};

/**
 * Rejects a placement that would push either of a two-axle container's axles over
 * its own limit.
 *
 * A no-op unless `Container::$axles` is set: axle enforcement is opt-in, matching
 * every other whole-container weight rule in this namespace, so a caller who never
 * configured axles sees no new rejection code and no behaviour change.
 *
 * Search evaluates a run of candidates against one placed scene, so the scene's gross
 * weight and moment are summed once per scene (one entry, as `PlacedSupportBase`) and
 * each candidate adds only itself: O(1) per candidate instead of O(m).
 */
final class AxleLoadConstraint implements PlacementConstraint
{
    /** @var list<Placement>|null */
    private ?array $placements = null;
    private ?Container $container = null;
    /** @var array{0:string,1:string} */
    private array $base = ['0', '0'];

    public function evaluate(ConstraintContext $c): ConstraintResult
    {
        if ($c->container->axles === null) {
            return ConstraintResult::allow();
        }
        if ($this->placements !== $c->placements || $this->container !== $c->container) {
            $this->base = AxleTotals::ofPlacements(
                $c->placements,
                $c->container->tareWeight->ticks,
                $c->container->innerDimensions->length->ticks,
            );
            $this->placements = $c->placements;
            $this->container = $c->container;
        }
        $failure = AxleTotals::exceeded(
            $c->container->axles,
            AxleTotals::plus($this->base, $c->envelopeBox(), $c->item->weight()->ticks),
        );
        return $failure === null ? ConstraintResult::allow() : ConstraintResult::reject($failure[0], $failure[1]);
    }
}
