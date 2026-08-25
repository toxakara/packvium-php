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
}
