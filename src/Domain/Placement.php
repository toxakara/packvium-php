<?php
declare(strict_types=1);
namespace Packvium\Domain;
use Packvium\Unit\Weight;
final readonly class Placement
{
    public function __construct(public ItemInstance $instance,public Point $position,public Rotation $rotation,public Dimensions $dimensions,public Point $envelopeOrigin,public Dimensions $envelopeDimensions,public float $supportRatio=1.0,public Weight $topLoad=new Weight(0)){}
    public function box():AxisAlignedBox{return new AxisAlignedBox($this->position,$this->dimensions);}public function envelopeBox():AxisAlignedBox{return new AxisAlignedBox($this->envelopeOrigin,$this->envelopeDimensions);}
}
