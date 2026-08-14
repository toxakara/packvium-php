<?php
declare(strict_types=1);
namespace Packvium\Domain;
/**
 * An excluded zone. `$additionalBoxes` expresses a non-rectangular shape (a wheel
 * arch, a tapered roof) as a union of exact boxes rather than modelling slants
 * directly, so the geometry stays integral. Every existing single-box
 * obstacle is exactly the one-box case of this union, so nothing about the default
 * construction changes.
 */
final readonly class Obstacle
{
    /** @param list<AxisAlignedBox> $additionalBoxes */
    public function __construct(public string $id,public AxisAlignedBox $box,public array $additionalBoxes=[]){}

    /** @return list<AxisAlignedBox> */
    public function boxes():array{return [$this->box,...$this->additionalBoxes];}
}
