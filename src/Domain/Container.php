<?php
declare(strict_types=1);
namespace Packvium\Domain;
use InvalidArgumentException;
use Packvium\Unit\Weight;
final readonly class Container
{
    /**
     * Which walls this container can be unloaded through.
     *
     * Empty means the horizontal half of route order is not enforced for it -- not that it
     * is sealed. A container with no stated doors is the pre- default, and
     * defaulting to all six instead would enforce a rule true of no real vehicle: a box is
     * almost always free through *some* face, so six doors is nearly the same as none, but
     * it is a *different* nearly-nothing and would change answers for every caller who
     * never set the field.
     *
     * Declared rather than promoted because a promoted readonly property cannot be
     * rewritten in the constructor body, and the doors are canonicalised there -- this is
     * the one place every construction path passes through.
     *
     * @var list<string>
     */
    public readonly array $accessDirections;

    /** @param list<Obstacle> $obstacles @param list<string> $tags @param array<string,int> $tagLimits @param array{0:Axle,1:Axle}|null $axles @param list<string> $accessDirections */
    public function __construct(public string $id,public Dimensions $innerDimensions,public ?Dimensions $outerDimensions=null,public Weight $tareWeight=new Weight(0),public ?Weight $maxPayload=null,public int $costMinor=0,public ?int $quantity=null,public array $obstacles=[],public array $tags=[],public ?int $maxItems=null,public array $metadata=[],public float $voidFillReserveRatio=0.0,public array $tagLimits=[],public ?Weight $maxStackDensity=null,public ?array $axles=null,public ?RateTable $rateTable=null,array $accessDirections=[])
    {if($id==='')throw new InvalidArgumentException('Container id is required');if($quantity!==null&&$quantity<=0)throw new InvalidArgumentException('Container quantity must be positive');if($costMinor<0)throw new InvalidArgumentException('Container cost cannot be negative');if($voidFillReserveRatio<0||$voidFillReserveRatio>1)throw new InvalidArgumentException('void_fill_reserve_ratio must be between 0 and 1');foreach($tagLimits as $limit)if($limit<1)throw new InvalidArgumentException('tag_limits must be at least 1');if($outerDimensions!==null&&!$innerDimensions->fitsInside($outerDimensions))throw new InvalidArgumentException('Outer dimensions cannot be smaller than inner dimensions');$boundary=new AxisAlignedBox(new Point(0,0,0),$innerDimensions);foreach($obstacles as $o)foreach($o->boxes() as $box)if(!$boundary->contains($box))throw new InvalidArgumentException("Obstacle {$o->id} lies outside container");
        if($axles!==null){[$front,$rear]=$axles;if($front->position->ticks>=$rear->position->ticks)throw new InvalidArgumentException('The front axle must be strictly nearer the origin than the rear axle');if($front->position->ticks<0||$rear->position->ticks>$innerDimensions->length->ticks)throw new InvalidArgumentException("Axle positions must lie within the container's length");}
        foreach($accessDirections as $direction)if(!in_array($direction,SweptRegion::ALL_DIRECTIONS,true))throw new InvalidArgumentException("unknown movement direction {$direction}");
        // Deduplicated into the canonical order rather than kept as given: two callers
        // naming the same doors in a different order must search identically.
        $this->accessDirections=array_values(array_filter(SweptRegion::ALL_DIRECTIONS,static fn(string $d):bool=>in_array($d,$accessDirections,true)));}
    public static function create(string $id,Dimensions $innerDimensions,Weight|int|string|array $tareWeight=0,Weight|int|string|array|null $maxPayload=null,?Dimensions $outerDimensions=null,int $costMinor=0,?int $quantity=null,array $obstacles=[],array $tags=[],?int $maxItems=null,array $metadata=[],float $voidFillReserveRatio=0.0,array $tagLimits=[],Weight|int|string|array|null $maxStackDensity=null,?array $axles=null,?RateTable $rateTable=null,array $accessDirections=[]):self{return new self($id,$innerDimensions,$outerDimensions,Weight::parse($tareWeight),$maxPayload===null?null:Weight::parse($maxPayload),$costMinor,$quantity,$obstacles,$tags,$maxItems,$metadata,$voidFillReserveRatio,$tagLimits,$maxStackDensity===null?null:Weight::parse($maxStackDensity),$axles,$rateTable,$accessDirections);}
}
