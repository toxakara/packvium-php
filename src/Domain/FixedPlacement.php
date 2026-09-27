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
final readonly class FixedPlacement
{
    public function __construct(public string $itemId,public string $containerId,public Point $position,public Rotation $rotation,public int $containerInstance=1)
    {
        if($containerInstance<1)throw new InvalidArgumentException('container_instance counts from 1');
    }

    public function packedContainerId():string{return "{$this->containerId}#{$this->containerInstance}";}
}
