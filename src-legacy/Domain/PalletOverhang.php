<?php
declare(strict_types=1);
namespace Packvium\Domain;
use Packvium\Support\{ExactGeometry,ExactInteger};
use Packvium\Unit\Length;
/**
 * Pallet overhang (docs/PALLET-AND-ROBOT-RULES.md "container.pallet_overhang_limit").
 *
 * A container with `pallet_overhang_limit` `(l, w)` is a deck `L x W`, its inner dimensions,
 * that a load may overhang by at most `l` past each end and `w` past each side. Every solver
 * packs the solving frame `(L + 2l) x (W + 2w) x H`, whose origin is the corner of the overhang
 * zone, so the deck lies on `[l, l + L] x [w, w + W]` and every coordinate stays non-negative.
 *
 * Only the deck carries weight. A placement on the floor rests on its overlap with the deck,
 * which `minimum_support_ratio` and the centre-of-gravity rule judge exactly as they judge a
 * supporting box; at a ratio of 0 nothing is enforced, as everywhere else. The packed pallet is
 * billed and handed up at the size it reached: its declared footprint (outer if stated, else
 * inner) grown by the overhang actually used past each edge. Exact integers throughout.
 */
final class PalletOverhang
{
    /**
     * The container every solver packs: itself without a limit, else the widened frame carrying
     * its deck. The frame states no limit, so a second call returns it unchanged. O(1).
     */
    public static function solvingFrame(Container $container):Container
    {
        $limit=$container->palletOverhangLimit;
        if($limit===null)return $container;
        $inner=$container->innerDimensions;
        $overhangX=$limit->length->ticks;$overhangY=$limit->width->ticks;
        $frame=new Dimensions(new Length($inner->length->ticks+2*$overhangX),new Length($inner->width->ticks+2*$overhangY),$inner->height);
        return $container->framed($frame,new Deck($overhangX,$overhangY,$inner->length->ticks,$inner->width->ticks));
    }

    /** A solving frame's container as declared, without the overhang zone: the rung a pallet trial packs first. O(1). */
    public static function deckAlone(Container $frame):Container
    {
        return $frame->framed($frame->declaredInner(),null);
    }

    /**
     * The shift that seats a frame trial's load with the least overhang. Per axis: flush with the
     * deck's near edge when the load is no longer than the deck, else centred on the deck with an
     * odd tick past the far edge. Either way the load stays inside the frame, since its span is at
     * most the deck plus both allowances. O(p); `[0, 0]` for none.
     *
     * @param list<Placement> $placements @return array{0:int,1:int}
     */
    public static function centringOffset(Deck $deck,array $placements):array
    {
        if($placements===[])return [0,0];
        $lowX=$lowY=\PHP_INT_MAX;$highX=$highY=0;
        foreach($placements as $placement){
            $box=$placement->envelopeBox();
            $lowX=min($lowX,$box->origin->x);$lowY=min($lowY,$box->origin->y);
            $highX=max($highX,$box->x2());$highY=max($highY,$box->y2());
        }
        return [self::seat($deck->x,$deck->length,$lowX,$highX),self::seat($deck->y,$deck->width,$lowY,$highY)];
    }

    private static function seat(int $start,int $length,int $low,int $high):int
    {
        $span=$high-$low;
        $target=$span<=$length?$start:$start-intdiv($span-$length,2);
        return $target-$low;
    }

    /** The area of a floor placement's footprint that rests on the deck. O(1).
     * @return int|string */
    public static function deckContactArea(Deck $deck,AxisAlignedBox $box)
    {
        return ExactGeometry::overlapAreaXY($deck->asBox(),$box);
    }

    /** The support ratio a placement on the floor reports: whole without a deck. O(1). */
    public static function floorSupportRatio(?Deck $deck,AxisAlignedBox $box):float
    {
        if($deck===null)return 1.0;
        return ExactInteger::ratio(self::deckContactArea($deck,$box),ExactGeometry::baseArea($box->dimensions));
    }

    /**
     * The deck grown by the overhang the placements use past each of its edges: `[length, width]`
     * of the footprint the load reaches. O(p).
     *
     * @param list<Placement> $placements @return array{0:int,1:int}
     */
    public static function footprint(Deck $deck,array $placements):array
    {
        $lowX=$deck->x;$lowY=$deck->y;$highX=$deck->x2();$highY=$deck->y2();
        foreach($placements as $placement){
            $box=$placement->envelopeBox();
            $lowX=min($lowX,$box->origin->x);$lowY=min($lowY,$box->origin->y);
            $highX=max($highX,$box->x2());$highY=max($highY,$box->y2());
        }
        return [$highX-$lowX,$highY-$lowY];
    }

    /**
     * The footprint the loaded pallet reaches, at its load's height. A packed container holds at
     * least one box. O(p).
     *
     * @param list<Placement> $placements
     */
    public static function achievedDimensions(Deck $deck,array $placements):Dimensions
    {
        [$length,$width]=self::footprint($deck,$placements);
        return new Dimensions(new Length($length),new Length($width),new Length(FlatBag::extentOf($placements)[2]));
    }

    /**
     * Per horizontal axis the larger of the declared footprint, outer if stated, and the deck
     * grown by the overhang used; the declared height. An outer margin is not positioned against
     * the deck, so it is never added to the overhang. O(p).
     *
     * @param list<Placement> $placements
     */
    public static function billedDimensions(Container $frame,Deck $deck,array $placements):Dimensions
    {
        $base=$frame->outerDimensions??$frame->declaredInner();
        [$length,$width]=self::footprint($deck,$placements);
        return new Dimensions(new Length(max($base->length->ticks,$length)),new Length(max($base->width->ticks,$width)),$base->height);
    }
}
