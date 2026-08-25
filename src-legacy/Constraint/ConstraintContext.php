<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Domain\{AxisAlignedBox,Container,Dimensions,ItemInstance,Placement,Point,Rotation};
final class ConstraintContext
{
    /**
     * @readonly
     * @var \Packvium\Domain\Container
     */
    public $container;
    /**
     * @var list<Placement>
     * @readonly
     */
    public $placements;
    /**
     * @readonly
     * @var \Packvium\Domain\ItemInstance
     */
    public $item;
    /**
     * @readonly
     * @var \Packvium\Domain\Point
     */
    public $point;
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
     * @var \Packvium\Domain\Dimensions
     */
    public $envelopeDimensions;
    /**
     * @var bool
     * @readonly
     */
    public $stackSensitive = true;
    /**
     * @var bool
     * @readonly
     */
    public $routeSensitive = true;
    /**
     * @param list<Placement> $placements
     * @param bool $stackSensitive False only when nothing in the container can refuse or
     *   be crushed by a load, which lets the bearing check skip a per-candidate walk of
     *   the whole stack.
     * @param bool $routeSensitive False when no item in play declares a `stopIndex`,
     *   which is every request that is not a multi-stop route.
     */
    public function __construct(Container $container, array $placements, ItemInstance $item, Point $point, string $rotation, Dimensions $dimensions, Dimensions $envelopeDimensions, bool $stackSensitive=true, bool $routeSensitive=true)
    {
        $this->container = $container;
        $this->placements = $placements;
        $this->item = $item;
        $this->point = $point;
        $this->rotation = $rotation;
        $this->dimensions = $dimensions;
        $this->envelopeDimensions = $envelopeDimensions;
        $this->stackSensitive = $stackSensitive;
        $this->routeSensitive = $routeSensitive;
    }

    public function envelopeBox():AxisAlignedBox{return new AxisAlignedBox($this->point,$this->envelopeDimensions);}
}
