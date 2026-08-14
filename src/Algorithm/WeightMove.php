<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
/** One item relocated from one already-packed container to another. */
final readonly class WeightMove
{
    public function __construct(public string $itemId, public string $fromContainerId, public string $toContainerId) {}
}
