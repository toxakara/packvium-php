<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

/** An axis-aligned box in ticks; its far corner and base area are computed once. */
final class Cuboid
{
    /** @var int|string */
    public $x;
    /** @var int|string */
    public $y;
    /** @var int|string */
    public $z;
    /** @var int|string */
    public $length;
    /** @var int|string */
    public $width;
    /** @var int|string */
    public $height;
    /** @var int|string */
    public $x2;
    /** @var int|string */
    public $y2;
    /** @var int|string */
    public $z2;
    /** @var int|string */
    public $baseArea;

    /** @param int|string $x @param int|string $y @param int|string $z @param int|string $length @param int|string $width @param int|string $height */
    public function __construct($x, $y, $z, $length, $width, $height)
    {
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
        $this->length = $length;
        $this->width = $width;
        $this->height = $height;
        $this->x2 = Exact::add($x, $length);
        $this->y2 = Exact::add($y, $width);
        $this->z2 = Exact::add($z, $height);
        $this->baseArea = Exact::mul($length, $width);
    }

    public function intersects(self $other): bool
    {
        return Exact::compare($this->x, $other->x2) < 0 && Exact::compare($other->x, $this->x2) < 0
            && Exact::compare($this->y, $other->y2) < 0 && Exact::compare($other->y, $this->y2) < 0
            && Exact::compare($this->z, $other->z2) < 0 && Exact::compare($other->z, $this->z2) < 0;
    }

    public function contains(self $other): bool
    {
        return Exact::compare($this->x, $other->x) <= 0 && Exact::compare($this->y, $other->y) <= 0
            && Exact::compare($this->z, $other->z) <= 0 && Exact::compare($other->x2, $this->x2) <= 0
            && Exact::compare($other->y2, $this->y2) <= 0 && Exact::compare($other->z2, $this->z2) <= 0;
    }

    /** @return int|string */
    public function overlapAreaXy(self $other)
    {
        $dx = Exact::max(0, Exact::sub(self::min($this->x2, $other->x2), Exact::max($this->x, $other->x)));
        $dy = Exact::max(0, Exact::sub(self::min($this->y2, $other->y2), Exact::max($this->y, $other->y)));
        return Exact::mul($dx, $dy);
    }

    /** @return list<array{0:int|string,1:int|string,2:int|string}> the eight corners, in the reference's order */
    public function vertices(): array
    {
        $corners = [];
        foreach ([false, true] as $farX) {
            foreach ([false, true] as $farY) {
                foreach ([false, true] as $farZ) {
                    $corners[] = [$farX ? $this->x2 : $this->x, $farY ? $this->y2 : $this->y, $farZ ? $this->z2 : $this->z];
                }
            }
        }
        return $corners;
    }

    /** @param int|string $a @param int|string $b @return int|string */
    private static function min($a, $b)
    {
        return Exact::compare($a, $b) <= 0 ? $a : $b;
    }
}
