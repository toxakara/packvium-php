<?php
declare(strict_types=1);
namespace Packvium\Extension;
use Packvium\Algorithm\SingleContainerSolution;
use Packvium\Domain\Container;
use Packvium\Domain\Nesting;
use Packvium\Support\BigInt;
/** Prefers the container that holds the most items, then the cheapest, then the tightest. */
final class DefaultContainerSelector implements ContainerSelector
{
    public function score(Container $c,SingleContainerSolution $s):array
    {
        $used=$s->state->latticeSummary!==null?$s->state->latticeSummary->usedVolumeString():Nesting::usedVolume($s->state->placements);
        $leftover=BigInt::chunks(BigInt::subtract($c->innerDimensions->volumeString(),$used));
        return array_merge([-$s->state->placementCount(), $c->costMinor], $leftover, [$c->id]);
    }
}
