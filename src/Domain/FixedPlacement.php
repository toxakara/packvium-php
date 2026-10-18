<?php
declare(strict_types=1);
namespace Packvium\Domain;
/**
 * An item already in a known place before the solve: loaded, or locked there.
 *
 * Addressed by container type and instance rather than by a result's container index, which
 * does not exist until the solve finishes. `$position` is the physical origin, as a result
 * reports it, so a result placement can be fixed by quoting it (docs/PLAN-REVISIONS.md).
 */
final readonly class FixedPlacement
{
    /**
     * Every rule on these fields is checked where the placement sits in a request, by
     * `Packer::pack` (`RequestObjects::check`), with the JSON path's refusal and pointer.
     */
    public function __construct(public string $itemId,public string $containerId,public Point $position,public Rotation $rotation,public int $containerInstance=1)
    {
    }

    public function packedContainerId():string{return "{$this->containerId}#{$this->containerInstance}";}
}
