<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
/** One item relocated from one already-packed container to another. */
final class WeightMove
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
    public $fromContainerId;
    /**
     * @readonly
     * @var string
     */
    public $toContainerId;
    public function __construct(string $itemId, string $fromContainerId, string $toContainerId)
    {
        $this->itemId = $itemId;
        $this->fromContainerId = $fromContainerId;
        $this->toContainerId = $toContainerId;
    }
}
