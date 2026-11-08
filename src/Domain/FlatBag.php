<?php
declare(strict_types=1);
namespace Packvium\Domain;
use Packvium\Unit\Length;
/**
 * The flat-bag girth rule (docs/DEFORMABLE-CONTAINERS.md "1.6 readiness and contract").
 *
 * A `flat_bag` container is two sheets of usable flat size L x W filled to at most thickness
 * H, its inner dimensions. With a, b and c the largest envelope x2, y2 and z2 over its
 * placements, measured from the container origin, a packing is admitted only when the ordinary
 * boundary holds and a + c <= L and b + c <= W: a loop of film round the package's
 * cross-section is at least as long as that section's perimeter. Integer additions and
 * comparisons, so every check here is O(1) and needs no wider type than a coordinate.
 *
 * The extents only grow as boxes are added, so a verdict reached for a candidate never flips
 * when more items follow, and any subset of an admitted set is admitted.
 *
 * A `gusseted_bag` (1.10) has side gussets of depth G folded into each long edge: the tube
 * round the package's y-z section is 2(W + G) long instead of 2W, so the width sum becomes
 * b + c <= W + G. The length sum is unchanged (no bottom gusset in 1.10), and G = 0 is the
 * flat bag. Every method here takes the gusset depth in ticks, 0 for a flat bag.
 */
final class FlatBag
{
    public const GUSSETED_BAG='gusseted_bag';
    public const KINDS=['flat_bag',self::GUSSETED_BAG];

    /** Whether a package of extents (a, b, c) passes both girth sums; the frame is the caller's. */
    public static function girthAdmits(Dimensions $inner,int $a,int $b,int $c,int $gusset=0):bool
    {
        return $a+$c<=$inner->length->ticks&&$b+$c<=$inner->width->ticks+$gusset;
    }

    /** Whether one box of these dimensions, alone at the origin, fits a bag. */
    public static function fitsAlone(Dimensions $inner,Dimensions $box,int $gusset=0):bool
    {
        return $box->fitsInside($inner)&&self::girthAdmits($inner,$box->length->ticks,$box->width->ticks,$box->height->ticks,$gusset);
    }

    /**
     * `[max x2, max y2, max z2]` over the envelopes, `[0, 0, 0]` for none. O(p).
     *
     * @param list<Placement> $placements @return array{0:int,1:int,2:int}
     */
    public static function extentOf(array $placements):array
    {
        $a=$b=$c=0;
        foreach($placements as $p){$e=$p->envelopeBox();$a=max($a,$e->x2());$b=max($b,$e->y2());$c=max($c,$e->z2());}
        return [$a,$b,$c];
    }

    /** The achieved extent as dimensions; a packed bag always holds at least one box. @param array{0:int,1:int,2:int} $extent */
    public static function asDimensions(array $extent):Dimensions
    {
        return new Dimensions(new Length($extent[0]),new Length($extent[1]),new Length($extent[2]));
    }
}
