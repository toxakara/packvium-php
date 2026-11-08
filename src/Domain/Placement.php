<?php
declare(strict_types=1);
namespace Packvium\Domain;
use Packvium\Support\CanonicalJson;
use Packvium\Unit\Weight;
final readonly class Placement
{
    // `$fixed`: placed by the request's `fixed_placements`, not by search; nothing may move it.
    public function __construct(public ItemInstance $instance,public Point $position,public Rotation $rotation,public Dimensions $dimensions,public Point $envelopeOrigin,public Dimensions $envelopeDimensions,public float $supportRatio=1.0,public Weight $topLoad=new Weight(0),public bool $fixed=false){}
    public function box():AxisAlignedBox{return new AxisAlignedBox($this->position,$this->dimensions);}public function envelopeBox():AxisAlignedBox{return new AxisAlignedBox($this->envelopeOrigin,$this->envelopeDimensions);}

    /** This placement's cross-language id inside the container with id `$containerId` (1.10). */
    public function idIn(string $containerId):string{return self::identifier($containerId,$this->position,$this->instance->item->id,$this->rotation->value);}

    /**
     * A placement's cross-language id (1.10): the RFC 8785 canonical JSON text of
     * `[container id, x, y, z, item type, orientation]`, with the physical position in ticks as
     * integers. Two valid placements never share that tuple -- the same solid in the same place
     * would coincide -- and canonical JSON spells any id with no grammar of its own to escape.
     * A position is below its container's side, itself at most 2^53 - 1 ticks, so every integer
     * has a canonical spelling; a PHP string holds no lone UTF-16 surrogate, so the reference's
     * surrogate escape never arises here. A pure function of the placement, so a validator
     * recomputes it. O(length of the ids).
     */
    public static function identifier(string $containerId,Point $position,string $itemType,string $orientation):string
    {
        return CanonicalJson::encode([$containerId,$position->x,$position->y,$position->z,$itemType,$orientation]);
    }

    /**
     * This placement's rotated hull, or null when its box is the honest answer.
     *
     * Null for every `rigid_cuboid`, and for the three cases `hullCollisionIsExact` names.
     */
    public function hullShape():?HullShape
    {
        $item=$this->instance->item;
        $sameEnvelope=[$this->envelopeDimensions->length->ticks,$this->envelopeDimensions->width->ticks,$this->envelopeDimensions->height->ticks]
            ===[$this->dimensions->length->ticks,$this->dimensions->width->ticks,$this->dimensions->height->ticks];
        if(!self::hullCollisionIsExact($item,$sameEnvelope))return null;
        return HullShape::shapeFor($item->hullVertices,$this->rotation);
    }

    /**
     * Whether this item's collisions may be decided by its hull rather than by its box.
     *
     * Three conditions, each falling back to the box for its own reason: it is not a
     * `convex_hull`; a clearance has inflated the envelope past the physical box, and a
     * margin around a hull is not a hull; or the item is on a route, where the sequence
     * replay reasons with box sweeps only and packing tighter than it can verify would
     * produce arrangements the engine then reports as unloadable. Every fallback
     * over-reserves space, the only safe direction to be wrong in.
     */
    public static function hullCollisionIsExact(Item $item,bool $envelopeMatchesPhysical):bool
    {
        return $item->shapeType===ShapeType::CONVEX_HULL&&$envelopeMatchesPhysical&&$item->stopIndex===null;
    }

    /**
     * Do two placed items actually overlap?
     *
     * The axis-aligned envelope test is the broad phase and stays mandatory; this refines its
     * answer only when a hull is one of the two solids. One definition, so the solver, the
     * sequence check and the final validation cannot disagree about what "collides" means.
     */
    public static function collide(self $left,self $right):bool
    {
        $leftBox=$left->envelopeBox();$rightBox=$right->envelopeBox();
        if(!$leftBox->intersects($rightBox))return false;
        $leftShape=$left->hullShape();$rightShape=$right->hullShape();
        if($leftShape===null&&$rightShape===null)return true;
        return HullShape::collide(
            $leftShape??self::boxShape($leftBox),[$leftBox->origin->x,$leftBox->origin->y,$leftBox->origin->z],
            $rightShape??self::boxShape($rightBox),[$rightBox->origin->x,$rightBox->origin->y,$rightBox->origin->z],
        );
    }

    /** Whether a placed item overlaps a plain box -- an obstacle, or any other fixed solid. */
    public static function hitsBox(self $placement,AxisAlignedBox $box):bool
    {
        $envelope=$placement->envelopeBox();
        if(!$envelope->intersects($box))return false;
        $shape=$placement->hullShape();
        if($shape===null)return true;
        return HullShape::collide(
            $shape,[$envelope->origin->x,$envelope->origin->y,$envelope->origin->z],
            self::boxShape($box),[$box->origin->x,$box->origin->y,$box->origin->z],
        );
    }

    private static function boxShape(AxisAlignedBox $box):HullShape
    {
        return HullShape::box($box->dimensions->length->ticks,$box->dimensions->width->ticks,$box->dimensions->height->ticks);
    }
}
