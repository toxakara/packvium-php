<?php
declare(strict_types=1);
namespace Packvium\Domain;
final readonly class AxisAlignedBox
{
    public function __construct(public Point $origin,public Dimensions $dimensions){}
    public function x2():int{return $this->origin->x+$this->dimensions->length->ticks;}public function y2():int{return $this->origin->y+$this->dimensions->width->ticks;}public function z2():int{return $this->origin->z+$this->dimensions->height->ticks;}
    public function contains(self $o):bool{return $o->origin->x>=$this->origin->x&&$o->origin->y>=$this->origin->y&&$o->origin->z>=$this->origin->z&&$o->x2()<=$this->x2()&&$o->y2()<=$this->y2()&&$o->z2()<=$this->z2();}
    public function intersects(self $o):bool{return $this->origin->x<$o->x2()&&$this->x2()>$o->origin->x&&$this->origin->y<$o->y2()&&$this->y2()>$o->origin->y&&$this->origin->z<$o->z2()&&$this->z2()>$o->origin->z;}
    public function overlapAreaXY(self $o):int{$x=max(0,min($this->x2(),$o->x2())-max($this->origin->x,$o->origin->x));$y=max(0,min($this->y2(),$o->y2())-max($this->origin->y,$o->origin->y));return $x*$y;}
    /** @return array{0:int,1:int,2:int,3:int,4:int,5:int} Integer extents for the candidate scan's hot loop. */
    public function extent():array{return [$this->origin->x,$this->origin->y,$this->origin->z,$this->x2(),$this->y2(),$this->z2()];}
    public function containsPoint(Point $p):bool{return $p->x>=$this->origin->x&&$p->x<$this->x2()&&$p->y>=$this->origin->y&&$p->y<$this->y2()&&$p->z>=$this->origin->z&&$p->z<$this->z2();}
}
