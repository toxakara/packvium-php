<?php
declare(strict_types=1);
namespace Packvium\Sequence;

/**
 * One step of a generated order, carrying the evidence the acceptance calls for
 * alongside the bare index: which other placements this step structurally depended
 * on (support), and which specific direction its accessibility check actually used.
 * A companion to the plain `list<int>` the base generators return, not a
 * replacement for it.
 */
final class SequenceStep
{
    /** @param array<int,true> $dependsOn */
    public function __construct(
        public readonly int $index,
        public readonly string $direction,
        public readonly array $dependsOn,
    ) {}

    /**
     * Canonical JSON shape shared with the Python, Rust and JavaScript
     * implementations: `index`, `direction` and `depends_on` as a
     * sorted list, matching `conformance/scene/sequence-fixtures.json`.
     *
     * @return array{index:int,direction:string,depends_on:list<int>}
     */
    public function toArray(): array
    {
        $dependsOn = array_map('intval', array_keys($this->dependsOn));
        sort($dependsOn);
        return [
            'index' => $this->index,
            'direction' => $this->direction,
            'depends_on' => $dependsOn,
        ];
    }
}
