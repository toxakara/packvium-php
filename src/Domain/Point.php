<?php
declare(strict_types=1);
namespace Packvium\Domain;
use InvalidArgumentException;
use Packvium\Unit\Length;
final readonly class Point
{
    public function __construct(public int $x,public int $y,public int $z){if(min($x,$y,$z)<0)throw new InvalidArgumentException('Point coordinates cannot be negative');}
    public function toArray(string $unit='mm'):array{return ['x'=>(new Length($this->x))->toArray($unit),'y'=>(new Length($this->y))->toArray($unit),'z'=>(new Length($this->z))->toArray($unit)];}
}
