<?php
declare(strict_types=1);
namespace Packvium\Domain;
use InvalidArgumentException;
/**
 * An item already in a known place before the solve: loaded, or locked there.
 *
 * Addressed by container type and instance rather than by a result's container index, which
 * does not exist until the solve finishes. `$position` is the physical origin, as a result
 * reports it, so a result placement can be fixed by quoting it (docs/PLAN-REVISIONS.md).
 */
final class FixedPlacement
{
    /**
     * @readonly
     * @var string
     */
    public $itemId;
    /**
     * @readonly
     * @var string
     */
    public $containerId;
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
     * @var int
     */
    public $containerInstance = 1;
    public function __construct(string $itemId,string $containerId,Point $position,string $rotation,int $containerInstance=1)
    {
        $this->itemId = $itemId;
        $this->containerId = $containerId;
        $this->position = $position;
        $this->rotation = $rotation;
        $this->containerInstance = $containerInstance;
        if($containerInstance<1)throw new InvalidArgumentException('container_instance counts from 1');
    }

    public function packedContainerId():string{return "{$this->containerId}#{$this->containerInstance}";}
}
