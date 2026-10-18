<?php
declare(strict_types=1);
namespace Packvium\Constraint\Internal;
use Packvium\Constraint\{LoadCalculator,LoadUnit};
use Packvium\Domain\Placement;

/**
 * The support graph over one placement list, with each candidate appended to it.
 *
 * The support graph over the *placed* boxes is the same for every candidate evaluated
 * against one search state, and rebuilding it per candidate was the cost
 * removes. Every rule that reads the graph -- bearing and route order -- keeps
 * one of these. The cache is deliberately a single entry: search evaluates a run of
 * candidates against one state before moving on, so one entry captures the whole run.
 */
final class PlacedSupportBase
{
    /** @var list<Placement>|null */
    private ?array $placements=null;
    private ?LoadSupportGraph $base=null;
    /** @var list<LoadUnit> */
    private array $baseUnits=[];
    private int $hint=1;

    /**
     * `$placements`' units and graph with `$unit` appended at the last index.
     *
     * @param list<Placement> $placements
     * @return array{0:list<LoadUnit>,1:LoadSupportGraph}
     */
    public function withCandidate(array $placements,LoadUnit $unit):array
    {
        $box=$unit->box;
        $base=$this->baseFor($placements,max($box->x2()-$box->origin->x,$box->y2()-$box->origin->y));
        $units=$this->baseUnits;
        $units[]=$unit;
        return [$units,$base->withUnit($unit,$this->hint)];
    }

    /**
     * The support graph over `$placements` alone, rebuilt only when it cannot serve.
     *
     * The cell hint has to cover every candidate that will be appended to this base, and
     * the widest of them is not known in advance -- a candidate is a *new* item and may
     * be the widest in the request. So the hint grows to fit the first candidate that
     * needs it and the base is rebuilt that once; after that the run is served from
     * cache. Sizing it from the container instead would always be safe and always
     * coarse, and a cell far larger than the boxes collapses the spatial hash back into
     * the all-pairs scan it exists to avoid.
     *
     * @param list<Placement> $placements
     */
    private function baseFor(array $placements,int $footprint):LoadSupportGraph
    {
        $same=$this->base!==null&&$this->placements===$placements;
        if($same&&$footprint<=$this->hint)return $this->base;
        $this->hint=max($same?$this->hint:1,$footprint);
        $this->placements=$placements;
        $this->baseUnits=LoadCalculator::units($placements);
        $this->base=new LoadSupportGraph($this->baseUnits,$this->hint);
        return $this->base;
    }
}
