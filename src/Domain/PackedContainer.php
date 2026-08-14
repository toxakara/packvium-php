<?php
declare(strict_types=1);
namespace Packvium\Domain;
use Packvium\Unit\Weight;
final readonly class PackedContainer
{
    /**
     * @param list<Placement> $placements
     * @param list<ItemInstance> $latticeItems The specific instances the compact
     *     fast path consumed, in placement order -- needed to reconstruct
     *     identical Placement objects via expandPlacements(). A reference to
     *     already-existing ItemInstance objects, not new domain objects, so keeping
     *     it is far cheaper than the Placement construction it replaces.
     */
    public function __construct(
        public Container $container,
        public int $sequence,
        public array $placements,
        public ?LatticeSummary $latticeSummary = null,
        public array $latticeItems = [],
    ) {}

    public function id():string{return $this->container->id.'#'.$this->sequence;}

    public function placementCount(): int
    {
        return $this->latticeSummary !== null ? $this->latticeSummary->count : count($this->placements);
    }

    public function payloadWeight():Weight
    {
        if ($this->latticeSummary !== null) { return new Weight($this->latticeSummary->totalWeightTicks()); }
        $t=0;foreach($this->placements as $p)$t+=$p->instance->weight()->ticks;return new Weight($t);
    }

    public function grossWeight():Weight{return new Weight($this->container->tareWeight->ticks+$this->payloadWeight()->ticks);}

    public function usedVolumeString():string
    {
        return $this->latticeSummary !== null ? $this->latticeSummary->usedVolumeString() : Nesting::usedVolume($this->placements);
    }

    public function utilization():float{return (float)$this->usedVolumeString()/$this->container->innerDimensions->volumeScore();}

    public function maxZTicks(): int
    {
        if ($this->latticeSummary !== null) { return $this->latticeSummary->maxZTicks(); }
        $top = 0;
        foreach ($this->placements as $p) { $top = max($top, $p->envelopeBox()->z2()); }
        return $top;
    }

    public function centreOfMassOffsetPpm():int
    {
        if ($this->latticeSummary !== null) {
            $inner = $this->container->innerDimensions;
            return $this->latticeSummary->centreOfMassOffsetPpm($inner->length->ticks, $inner->width->ticks);
        }
        return CentreOfMass::offsetPpm($this->container->innerDimensions,$this->placements);
    }

    /**
     * The full per-item Placement list, reconstructed from latticeSummary if the
     * compact fast path built this container, otherwise $placements unchanged.
     * Reconstruction is exact: same order, same coordinates, same rotation as the
     * O(n) loop this fast path replaces.
     *
     * @return list<Placement>
     */
    public function expandPlacements(): array
    {
        return $this->latticeSummary !== null ? $this->latticeSummary->expand($this->latticeItems) : $this->placements;
    }

    public function asItem(?string $id=null,bool $keepUpright=true,?Weight $maxTopLoad=null):Item
    {
        return Item::create(
            $id??$this->id(),
            $this->container->outerDimensions??$this->container->innerDimensions,
            $this->grossWeight(),
            1,
            Rotation::all(),
            $keepUpright,
            true,
            false,
            $maxTopLoad,
            0.0,
            null,
            [],
            [],
            0,
            ['source_packed_container'=>$this->id()],
        );
    }
}
