<?php
declare(strict_types=1);
namespace Packvium\Domain;
use Packvium\Unit\Weight;
final class Placement
{
    /**
     * @readonly
     * @var \Packvium\Domain\ItemInstance
     */
    public $instance;
    /**
     * @readonly
     * @var \Packvium\Domain\Point
     */
    public $position;
    /**
     * @readonly
     * @var string
     */
    public $rotation;
    /**
     * @readonly
     * @var \Packvium\Domain\Dimensions
     */
    public $dimensions;
    /**
     * @readonly
     * @var \Packvium\Domain\Point
     */
    public $envelopeOrigin;
    /**
     * @readonly
     * @var \Packvium\Domain\Dimensions
     */
    public $envelopeDimensions;
    /**
     * @readonly
     * @var float
     */
    public $supportRatio = 1.0;
    /**
     * @readonly
     * @var \Packvium\Unit\Weight
     */
    public $topLoad;
    public function __construct(ItemInstance $instance, Point $position, string $rotation, Dimensions $dimensions, Point $envelopeOrigin, Dimensions $envelopeDimensions, float $supportRatio=1.0, ?Weight $topLoad=null)
    {
        $topLoad = $topLoad ?? new Weight(0);
        $this->instance = $instance;
        $this->position = $position;
        $this->rotation = $rotation;
        $this->dimensions = $dimensions;
        $this->envelopeOrigin = $envelopeOrigin;
        $this->envelopeDimensions = $envelopeDimensions;
        $this->supportRatio = $supportRatio;
        $this->topLoad = $topLoad;
    }
    public function box():AxisAlignedBox{return new AxisAlignedBox($this->position,$this->dimensions);}public function envelopeBox():AxisAlignedBox{return new AxisAlignedBox($this->envelopeOrigin,$this->envelopeDimensions);}

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
