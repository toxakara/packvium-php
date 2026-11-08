<?php
declare(strict_types=1);
namespace Packvium\Domain;
use Packvium\Unit\Length;
/**
 * The weight-bearing footprint inside a pallet's solving frame, in frame ticks. Set
 * only by `PalletOverhang::solvingFrame`.
 */
final class Deck
{
    /**
     * @readonly
     * @var int
     */
    public $x;
    /**
     * @readonly
     * @var int
     */
    public $y;
    /**
     * @readonly
     * @var int
     */
    public $length;
    /**
     * @readonly
     * @var int
     */
    public $width;
    public function __construct(int $x, int $y, int $length, int $width)
    {
        $this->x = $x;
        $this->y = $y;
        $this->length = $length;
        $this->width = $width;
    }

    public function x2():int{return $this->x+$this->length;}
    public function y2():int{return $this->y+$this->width;}

    /** The deck as a one-tick slab on the floor: only its footprint is ever read. */
    public function asBox():AxisAlignedBox{return new AxisAlignedBox(new Point($this->x,$this->y,0),new Dimensions(new Length($this->length),new Length($this->width),new Length(1)));}
}
