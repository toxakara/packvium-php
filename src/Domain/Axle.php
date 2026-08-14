<?php
declare(strict_types=1);
namespace Packvium\Domain;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;

/**
 * One axle's position along the container's length axis and its own limit.
 *
 * Two-axle only (front, rear), matching the usual two-axle scope: the load on more
 * than two supports is statically indeterminate without further assumptions this
 * library has no basis for making, so it is not modelled.
 */
final readonly class Axle
{
    public function __construct(public Length $position, public ?Weight $maxLoad = null) {}
}
