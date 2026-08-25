<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Constraint\LoadCalculator;
use Packvium\Domain\Placement;
use Packvium\Unit\Weight;
final class TopLoadAssigner
{
    /** Fills in each placement's borne load once the container is final. @param list<Placement> $placements @return list<Placement> */
    public static function assign(array $placements):array
    {
        $loads=LoadCalculator::topLoads(LoadCalculator::units($placements));
        $out=[];
        foreach($placements as $index=>$p)
            $out[]=new Placement($p->instance,$p->position,$p->rotation,$p->dimensions,$p->envelopeOrigin,$p->envelopeDimensions,$p->supportRatio,new Weight($loads[$index]));
        return $out;
    }
}
