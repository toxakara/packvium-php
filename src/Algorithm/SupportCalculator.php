<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Constraint\Internal\LoadSupportGraph;
use Packvium\Domain\{AxisAlignedBox,ItemInstance,PalletOverhang};
use Packvium\Support\{ExactGeometry,ExactInteger};
final class SupportCalculator
{
    /** Reported support fraction, from resting contact with other placements, or on the floor with a pallet's deck. */
    public static function ratio(ContainerState $state,ItemInstance $item,Candidate $c):float
    {
        $box=new AxisAlignedBox($c->point,$c->envelopeDimensions);
        // A floor without a deck is whole support; deciding it here keeps `PalletOverhang` unloaded.
        if($c->point->z===0)return $state->container->deck===null?1.0:PalletOverhang::floorSupportRatio($state->container->deck,$box);
        $support=LoadSupportGraph::candidateView($state->placements,$item,$box);
        return ExactInteger::ratio($support->supportingArea,ExactGeometry::baseArea($c->envelopeDimensions));
    }
}
