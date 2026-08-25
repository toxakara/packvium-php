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
final class Obstacle
{
    /**
     * @readonly
     * @var string
     */
    public $id;
    /**
     * @readonly
     * @var \Packvium\Domain\AxisAlignedBox
     */
    public $box;
    /**
     * @var list<AxisAlignedBox>
     * @readonly
     */
    public $additionalBoxes = [];
    /** @param list<AxisAlignedBox> $additionalBoxes */
    public function __construct(string $id, AxisAlignedBox $box, array $additionalBoxes=[])
    {
        $this->id = $id;
        $this->box = $box;
        $this->additionalBoxes = $additionalBoxes;
    }

    /** @return list<AxisAlignedBox> */
    public function boxes():array{return array_merge([$this->box], $this->additionalBoxes);}
}
