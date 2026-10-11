<?php
declare(strict_types=1);
namespace Packvium\Domain;
use InvalidArgumentException;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;
final readonly class Container
{
    /**
     * Which walls this container can be unloaded through.
     *
     * Empty means the horizontal half of route order is not enforced for it -- not that it
     * is sealed. A container with no stated doors is the pre-default, and
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

    /**
     * `$preloaded`: items already in this one container instance before search starts. Set
     * only by the orchestrator, on the instance a request's `fixed_placements` name, so every
     * solver's `ContainerState` starts from them without knowing they exist; a packed result
     * carries the container with this emptied again (docs/PLAN-REVISIONS.md).
     *
     * `$deformableBoundary`: `'flat_bag'` makes this a flexible bag whose package conforms to its
     * contents (`FlatBag`); null is a rigid container.
     *
     * @param list<Obstacle> $obstacles @param list<string> $tags @param array<string,int> $tagLimits @param array{0:Axle,1:Axle}|null $axles @param list<string> $accessDirections @param list<Placement> $preloaded
     */
    public function __construct(public string $id,public Dimensions $innerDimensions,public ?Dimensions $outerDimensions=null,public Weight $tareWeight=new Weight(0),public ?Weight $maxPayload=null,public int $costMinor=0,public ?int $quantity=null,public array $obstacles=[],public array $tags=[],public ?int $maxItems=null,public array $metadata=[],public float $voidFillReserveRatio=0.0,public array $tagLimits=[],public ?Weight $maxStackDensity=null,public ?array $axles=null,public ?RateTable $rateTable=null,array $accessDirections=[],public array $preloaded=[],public ?string $deformableBoundary=null,public ?MadeToMeasure $madeToMeasure=null)
    {if($id==='')throw new InvalidArgumentException('Container id is required');$madeToMeasure?->admits([$innerDimensions->length->ticks,$innerDimensions->width->ticks,$innerDimensions->height->ticks]);if($deformableBoundary!==null)self::requireBagFields($deformableBoundary,$obstacles,$axles,$accessDirections,$maxStackDensity,$outerDimensions,$voidFillReserveRatio);if($quantity!==null&&$quantity<=0)throw new InvalidArgumentException('Container quantity must be positive');if($costMinor<0)throw new InvalidArgumentException('Container cost cannot be negative');if($maxItems!==null&&$maxItems<1)throw new InvalidArgumentException('Container max_items must be at least 1');if($voidFillReserveRatio<0||$voidFillReserveRatio>1)throw new InvalidArgumentException('void_fill_reserve_ratio must be between 0 and 1');foreach($tagLimits as $limit)if($limit<1)throw new InvalidArgumentException('tag_limits must be at least 1');if($outerDimensions!==null&&!$innerDimensions->fitsInside($outerDimensions))throw new InvalidArgumentException('Outer dimensions cannot be smaller than inner dimensions');$boundary=new AxisAlignedBox(new Point(0,0,0),$innerDimensions);foreach($obstacles as $o)foreach($o->boxes() as $box)if(!$boundary->contains($box))throw new InvalidArgumentException("Obstacle {$o->id} lies outside container");
        if($axles!==null){[$front,$rear]=$axles;if($front->position->ticks>=$rear->position->ticks)throw new InvalidArgumentException('The front axle must be strictly nearer the origin than the rear axle');if($front->position->ticks<0||$rear->position->ticks>$innerDimensions->length->ticks)throw new InvalidArgumentException("Axle positions must lie within the container's length");}
        foreach($accessDirections as $direction)if(!in_array($direction,SweptRegion::ALL_DIRECTIONS,true))throw new InvalidArgumentException("unknown movement direction {$direction}");
        // Deduplicated into the canonical order rather than kept as given: two callers
        // naming the same doors in a different order must search identically.
        $this->accessDirections=array_values(array_filter(SweptRegion::ALL_DIRECTIONS,static fn(string $d):bool=>in_array($d,$accessDirections,true)));}
    /** The same container holding `$placements` before search. @param list<Placement> $placements */
    public function withPreloaded(array $placements):self{return new self($this->id,$this->innerDimensions,$this->outerDimensions,$this->tareWeight,$this->maxPayload,$this->costMinor,$this->quantity,$this->obstacles,$this->tags,$this->maxItems,$this->metadata,$this->voidFillReserveRatio,$this->tagLimits,$this->maxStackDensity,$this->axles,$this->rateTable,$this->accessDirections,$placements,$this->deformableBoundary,$this->madeToMeasure);}
    public function isFlatBag():bool{return $this->deformableBoundary!==null;}
    /** The declared container solved inside `$rung`: same id, everything else unchanged. @param array{0:int,1:int,2:int} $rung */
    public function withEnvelope(array $rung):self{return $this->resized(self::dimensionsOf($rung),null,$this->costMinor,$this->tareWeight);}
    /**
     * The box cut for an achieved extent: equal to this declared machine except its size, outer
     * size, price and tare. O(1). Containment is a theorem, not a check: every placement lies
     * inside `$achieved`, and the grid never rounds below it or past the maximum (D4).
     *
     * @param array{0:int,1:int,2:int} $achieved
     */
    public function fabricate(array $achieved):self
    {
        $machine=$this->madeToMeasure;
        if($machine===null)throw new \LogicException("container {$this->id} is not made to measure");
        $maximum=[$this->innerDimensions->length->ticks,$this->innerDimensions->width->ticks,$this->innerDimensions->height->ticks];
        $sides=[];$outer=[];
        for($axis=0;$axis<3;$axis++){
            $sides[]=$machine->grid($axis,$achieved[$axis]);
            if($sides[$axis]>$maximum[$axis])throw new \LogicException('fabricated box exceeds the machine');
            $outer[]=$sides[$axis]+$machine->outerAllowance[$axis];
        }
        return $this->resized(self::dimensionsOf($sides),self::dimensionsOf($outer),$machine->boxCostMinor($this->costMinor,$sides),new Weight($machine->boxTareTicks($this->tareWeight->ticks,$sides)));
    }
    private function resized(Dimensions $inner,?Dimensions $outer,int $costMinor,Weight $tareWeight):self{return new self($this->id,$inner,$outer,$tareWeight,$this->maxPayload,$costMinor,$this->quantity,$this->obstacles,$this->tags,$this->maxItems,$this->metadata,$this->voidFillReserveRatio,$this->tagLimits,$this->maxStackDensity,$this->axles,$this->rateTable,$this->accessDirections,$this->preloaded,$this->deformableBoundary,$this->madeToMeasure);}
    /** @param array{0:int,1:int,2:int} $sides */
    private static function dimensionsOf(array $sides):Dimensions{return new Dimensions(new Length($sides[0]),new Length($sides[1]),new Length($sides[2]));}
    /**
     * A bag has no walls, floor plan or chassis for a wall-anchored field to refer to, so those
     * fields are refused rather than read against a frame that moves with its contents. The JSON
     * path names the same fields as request errors first.
     *
     * @param list<Obstacle> $obstacles @param array{0:Axle,1:Axle}|null $axles @param list<string> $accessDirections
     */
    private static function requireBagFields(string $kind,array $obstacles,?array $axles,array $accessDirections,?Weight $maxStackDensity,?Dimensions $outerDimensions,float $voidFillReserveRatio):void
    {
        if(!in_array($kind,FlatBag::KINDS,true))throw new InvalidArgumentException("unknown deformable_boundary kind {$kind}");
        $present=['obstacles'=>$obstacles!==[],'axles'=>$axles!==null,'access_directions'=>$accessDirections!==[],'max_stack_density'=>$maxStackDensity!==null,'outer_dimensions'=>$outerDimensions!==null,'void_fill_reserve_ratio'=>$voidFillReserveRatio>0];
        foreach($present as $name=>$set)if($set)throw new InvalidArgumentException("{$name} cannot be combined with deformable_boundary");
    }
    public static function create(string $id,Dimensions $innerDimensions,Weight|int|string|array $tareWeight=0,Weight|int|string|array|null $maxPayload=null,?Dimensions $outerDimensions=null,int $costMinor=0,?int $quantity=null,array $obstacles=[],array $tags=[],?int $maxItems=null,array $metadata=[],float $voidFillReserveRatio=0.0,array $tagLimits=[],Weight|int|string|array|null $maxStackDensity=null,?array $axles=null,?RateTable $rateTable=null,array $accessDirections=[],?string $deformableBoundary=null):self{return new self($id,$innerDimensions,$outerDimensions,Weight::parse($tareWeight),$maxPayload===null?null:Weight::parse($maxPayload),$costMinor,$quantity,$obstacles,$tags,$maxItems,$metadata,$voidFillReserveRatio,$tagLimits,$maxStackDensity===null?null:Weight::parse($maxStackDensity),$axles,$rateTable,$accessDirections,[],$deformableBoundary);}
}
