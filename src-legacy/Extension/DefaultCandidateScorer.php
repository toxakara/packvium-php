<?php
declare(strict_types=1);
namespace Packvium\Extension;
use Packvium\Algorithm\ContainerState;
use Packvium\Domain\{Dimensions,Point};
/** Prefers low, tight placements. Every component is an exact integer. */
final class DefaultCandidateScorer implements CandidateScorer
{
    public function score(ContainerState $state,Point $point,Dimensions $envelope):array
    {
        $inner=$state->container->innerDimensions;
        $newHeight=max($state->maxZ,$point->z+$envelope->height->ticks);
        $rx=$inner->length->ticks-($point->x+$envelope->length->ticks);
        $ry=$inner->width->ticks-($point->y+$envelope->width->ticks);
        $rz=$inner->height->ticks-($point->z+$envelope->height->ticks);
        return [$point->z,$newHeight,$rx*$ry+$rz,$point->y,$point->x];
    }
}
