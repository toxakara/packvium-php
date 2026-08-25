<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Constraint\Internal\LoadSupportGraph;
use Packvium\Domain\{AxisAlignedBox,ItemInstance};
final class SupportCalculator
{
    /** Reported support fraction, from resting contact with other placements only. */
    public static function ratio(ContainerState $state,ItemInstance $item,Candidate $c):float
    {
        if($c->point->z===0)return 1.0;
        $box=new AxisAlignedBox($c->point,$c->envelopeDimensions);
        $support=LoadSupportGraph::candidateView($state->placements,$item,$box);
        return $support->supportingArea/$c->envelopeDimensions->baseAreaTicks();
    }
}
