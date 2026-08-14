<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Domain\{AxisAlignedBox,Container,Dimensions,ItemInstance,Placement,Point,Rotation};
final readonly class ConstraintContext
{
    /**
     * @param list<Placement> $placements
     * @param bool $stackSensitive False only when nothing in the container can refuse or
     *   be crushed by a load, which lets the bearing check skip a per-candidate walk of
     *   the whole stack.
     * @param bool $routeSensitive False when no item in play declares a `stopIndex`,
     *   which is every request that is not a multi-stop route.
     */
    public function __construct(public Container $container,public array $placements,public ItemInstance $item,public Point $point,public Rotation $rotation,public Dimensions $dimensions,public Dimensions $envelopeDimensions,public bool $stackSensitive=true,public bool $routeSensitive=true){}

    public function envelopeBox():AxisAlignedBox{return new AxisAlignedBox($this->point,$this->envelopeDimensions);}
}
