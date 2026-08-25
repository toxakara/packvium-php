<?php
declare(strict_types=1);
namespace Packvium\Domain;
use InvalidArgumentException;
use Packvium\Unit\Length;
final class Point
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
    public $z;
    public function __construct(int $x,int $y,int $z){$this->x = $x;
    $this->y = $y;
    $this->z = $z;
    if(min($x,$y,$z)<0)throw new InvalidArgumentException('Point coordinates cannot be negative');}
    public function toArray(string $unit='mm'):array{return ['x'=>(new Length($this->x))->toArray($unit),'y'=>(new Length($this->y))->toArray($unit),'z'=>(new Length($this->z))->toArray($unit)];}
}
