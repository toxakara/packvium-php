<?php
declare(strict_types=1);
namespace Packvium\Domain;
use InvalidArgumentException;
use Packvium\Support\ObjectRefusal;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;
final class Container
{
    /**
     * @readonly
     * @var string
     */
    public $id;
    /**
     * @readonly
     * @var \Packvium\Domain\Dimensions
     */
    public $innerDimensions;
    /**
     * @readonly
     * @var \Packvium\Domain\Dimensions|null
     */
    public $outerDimensions;
    /**
     * @readonly
     * @var \Packvium\Unit\Weight
     */
    public $tareWeight;
    /**
     * @readonly
     * @var \Packvium\Unit\Weight|null
     */
    public $maxPayload;
    /**
     * @readonly
     * @var int
     */
    public $costMinor = 0;
    /**
     * @readonly
     * @var int|null
     */
    public $quantity;
    /**
     * @var list<Obstacle>
     * @readonly
     */
    public $obstacles = [];
    /**
     * @readonly
     * @var mixed[]
     */
    public $tags = [];
    /**
     * @readonly
     * @var int|null
     */
    public $maxItems;
    /**
     * @readonly
     * @var mixed[]
     */
    public $metadata = [];
    /**
     * @readonly
     * @var float
     */
    public $voidFillReserveRatio = 0.0;
    /**
     * @readonly
     * @var mixed[]
     */
    public $tagLimits = [];
    /**
     * @readonly
     * @var \Packvium\Unit\Weight|null
     */
    public $maxStackDensity;
    /**
     * @readonly
     * @var mixed[]|null
     */
    public $axles;
    /**
     * @readonly
     * @var \Packvium\Domain\RateTable|null
     */
    public $rateTable;
    /**
     * @readonly
     * @var mixed[]
     */
    public $preloaded = [];
    /**
     * @readonly
     * @var string|null
     */
    public $deformableBoundary;
    /**
     * @readonly
     * @var \Packvium\Domain\MadeToMeasure|null
     */
    public $madeToMeasure;
    /**
     * @readonly
     * @var \Packvium\Domain\OverhangLimit|null
     */
    public $palletOverhangLimit;
    /**
     * @readonly
     * @var \Packvium\Domain\Deck|null
     */
    public $deck;
    /**
     * @readonly
     * @var \Packvium\Unit\Length|null
     */
    public $sideGussetDepth;
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
     * @readonly
     */
    public $accessDirections;

    /**
     * `$preloaded`: items already in this one container instance before search starts. Set
     * only by the orchestrator, on the instance a request's `fixed_placements` name, so every
     * solver's `ContainerState` starts from them without knowing they exist; a packed result
     * carries the container with this emptied again (docs/PLAN-REVISIONS.md).
     *
     * `$deformableBoundary`: `'flat_bag'` makes this a flexible bag whose package conforms to its
     * contents (`FlatBag`); `'gusseted_bag'` is one whose width girth is widened by
     * `$sideGussetDepth`, which that kind requires and every other container refuses (1.10).
     * null is a rigid container.
     *
     * `$palletOverhangLimit`: how far a load may pass this pallet deck's edges (,
     * `PalletOverhang`). `$deck`: the deck inside a solving frame, set only by
     * `PalletOverhang::solvingFrame`, which widens `$innerDimensions` to the frame and drops the
     * limit.
     *
     * @param list<Obstacle> $obstacles @param list<string> $tags @param array<string,int> $tagLimits @param array{0:Axle,1:Axle}|null $axles @param list<string> $accessDirections @param list<Placement> $preloaded
     */
    public function __construct(string $id,Dimensions $innerDimensions,?Dimensions $outerDimensions=null,?Weight $tareWeight=null,?Weight $maxPayload=null,int $costMinor=0,?int $quantity=null,array $obstacles=[],array $tags=[],?int $maxItems=null,array $metadata=[],float $voidFillReserveRatio=0.0,array $tagLimits=[],?Weight $maxStackDensity=null,?array $axles=null,?RateTable $rateTable=null,array $accessDirections=[],array $preloaded=[],?string $deformableBoundary=null,?MadeToMeasure $madeToMeasure=null,?OverhangLimit $palletOverhangLimit=null,?Deck $deck=null,?Length $sideGussetDepth=null)
    {$tareWeight = $tareWeight ?? new Weight(0);
    $this->id = $id;
    $this->innerDimensions = $innerDimensions;
    $this->outerDimensions = $outerDimensions;
    $this->tareWeight = $tareWeight;
    $this->maxPayload = $maxPayload;
    $this->costMinor = $costMinor;
    $this->quantity = $quantity;
    $this->obstacles = $obstacles;
    $this->tags = $tags;
    $this->maxItems = $maxItems;
    $this->metadata = $metadata;
    $this->voidFillReserveRatio = $voidFillReserveRatio;
    $this->tagLimits = $tagLimits;
    $this->maxStackDensity = $maxStackDensity;
    $this->axles = $axles;
    $this->rateTable = $rateTable;
    $this->preloaded = $preloaded;
    $this->deformableBoundary = $deformableBoundary;
    $this->madeToMeasure = $madeToMeasure;
    $this->palletOverhangLimit = $palletOverhangLimit;
    $this->deck = $deck;
    $this->sideGussetDepth = $sideGussetDepth;
    if($id==='')throw new ObjectRefusal('invalid_value','Container id is required');($nullsafeVariable1 = $madeToMeasure) ? $nullsafeVariable1->admits([$innerDimensions->length->ticks,$innerDimensions->width->ticks,$innerDimensions->height->ticks]) : null;if($deformableBoundary!==null)self::requireBagFields($deformableBoundary,$innerDimensions,$sideGussetDepth,$obstacles,$axles,$accessDirections,$maxStackDensity,$outerDimensions,$voidFillReserveRatio,$palletOverhangLimit);elseif($sideGussetDepth!==null)throw new ObjectRefusal('not_allowed','side_gusset_depth requires deformable_boundary gusseted_bag');if($palletOverhangLimit!==null)self::requirePalletFields($obstacles,$axles,$accessDirections,$voidFillReserveRatio,$madeToMeasure);if($quantity!==null&&$quantity<=0)throw new ObjectRefusal('below_minimum','Container quantity must be positive');if($costMinor<0)throw new ObjectRefusal('below_minimum','Container cost cannot be negative');if($maxItems!==null&&$maxItems<1)throw new ObjectRefusal('below_minimum','Container max_items must be at least 1');if(!($voidFillReserveRatio>=0&&$voidFillReserveRatio<=1))throw ObjectRefusal::ratio($voidFillReserveRatio,'void_fill_reserve_ratio must be between 0 and 1');foreach($tagLimits as $limit)if($limit<1)throw new ObjectRefusal('below_minimum','tag_limits must be at least 1');if($outerDimensions!==null&&!self::declaredInnerOf($innerDimensions,$deck)->fitsInside($outerDimensions))throw new ObjectRefusal('invalid_value','Outer dimensions cannot be smaller than inner dimensions');$boundary=new AxisAlignedBox(new Point(0,0,0),$innerDimensions);foreach($obstacles as $o)foreach($o->boxes() as $box)if(!$boundary->contains($box))throw new ObjectRefusal('invalid_value',"Obstacle {$o->id} lies outside container");
        if($axles!==null){[$front,$rear]=$axles;if($front->position->ticks>=$rear->position->ticks)throw new ObjectRefusal('invalid_value','The front axle must be strictly nearer the origin than the rear axle');if($front->position->ticks<0||$rear->position->ticks>$innerDimensions->length->ticks)throw new ObjectRefusal('invalid_value',"Axle positions must lie within the container's length");}
        foreach($accessDirections as $direction)if(!in_array($direction,SweptRegion::ALL_DIRECTIONS,true))throw new InvalidArgumentException("unknown movement direction {$direction}");
        // Deduplicated into the canonical order rather than kept as given: two callers
        // naming the same doors in a different order must search identically.
        $this->accessDirections=$accessDirections===[]?[]:array_values(array_filter(SweptRegion::ALL_DIRECTIONS,static function (string $d) use ($accessDirections): bool {
            return in_array($d,$accessDirections,true);
        }));}
    /** The same container holding `$placements` before search. @param list<Placement> $placements */
    public function withPreloaded(array $placements):self{return new self($this->id,$this->innerDimensions,$this->outerDimensions,$this->tareWeight,$this->maxPayload,$this->costMinor,$this->quantity,$this->obstacles,$this->tags,$this->maxItems,$this->metadata,$this->voidFillReserveRatio,$this->tagLimits,$this->maxStackDensity,$this->axles,$this->rateTable,$this->accessDirections,$placements,$this->deformableBoundary,$this->madeToMeasure,$this->palletOverhangLimit,$this->deck,$this->sideGussetDepth);}
    public function isFlatBag():bool{return $this->deformableBoundary!==null;}
    /** The side gusset depth G in ticks; 0 for a flat bag and every rigid container. */
    public function sideGussetTicks():int{return $this->sideGussetDepth===null?0:$this->sideGussetDepth->ticks;}
    /**
     * The same container packed in `$inner` with `$deck` as its only weight-bearing floor, and
     * no overhang limit: how `PalletOverhang` builds a pallet's solving frame and its deck rung
     *. A frame states no limit, so it is never widened twice. O(1).
     *
     * @internal
     */
    public function framed(Dimensions $inner,?Deck $deck):self{return new self($this->id,$inner,$this->outerDimensions,$this->tareWeight,$this->maxPayload,$this->costMinor,$this->quantity,$this->obstacles,$this->tags,$this->maxItems,$this->metadata,$this->voidFillReserveRatio,$this->tagLimits,$this->maxStackDensity,$this->axles,$this->rateTable,$this->accessDirections,$this->preloaded,$this->deformableBoundary,$this->madeToMeasure,null,$deck,$this->sideGussetDepth);}
    /** The inner size the caller declared: a solving frame's deck at its height, else `innerDimensions`. */
    public function declaredInner():Dimensions{return self::declaredInnerOf($this->innerDimensions,$this->deck);}
    private static function declaredInnerOf(Dimensions $inner,?Deck $deck):Dimensions{return $deck===null?$inner:new Dimensions(new Length($deck->length),new Length($deck->width),$inner->height);}
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
    private function resized(Dimensions $inner,?Dimensions $outer,int $costMinor,Weight $tareWeight):self{return new self($this->id,$inner,$outer,$tareWeight,$this->maxPayload,$costMinor,$this->quantity,$this->obstacles,$this->tags,$this->maxItems,$this->metadata,$this->voidFillReserveRatio,$this->tagLimits,$this->maxStackDensity,$this->axles,$this->rateTable,$this->accessDirections,$this->preloaded,$this->deformableBoundary,$this->madeToMeasure,$this->palletOverhangLimit,$this->deck,$this->sideGussetDepth);}
    /** @param array{0:int,1:int,2:int} $sides */
    private static function dimensionsOf(array $sides):Dimensions{return new Dimensions(new Length($sides[0]),new Length($sides[1]),new Length($sides[2]));}
    /**
     * A bag has no walls, floor plan or chassis for a wall-anchored field to refer to, so those
     * fields are refused rather than read against a frame that moves with its contents. The JSON
     * path names the same fields as request errors first.
     *
     * @param list<Obstacle> $obstacles @param array{0:Axle,1:Axle}|null $axles @param list<string> $accessDirections
     */
    private static function requireBagFields(string $kind,Dimensions $inner,?Length $sideGussetDepth,array $obstacles,?array $axles,array $accessDirections,?Weight $maxStackDensity,?Dimensions $outerDimensions,float $voidFillReserveRatio,?OverhangLimit $palletOverhangLimit):void
    {
        if(!in_array($kind,FlatBag::KINDS,true))throw new ObjectRefusal('not_allowed',"unknown deformable_boundary kind {$kind}");
        self::requireGussetRule($kind===FlatBag::GUSSETED_BAG,$inner,$sideGussetDepth);
        $present=['obstacles'=>$obstacles!==[],'axles'=>$axles!==null,'access_directions'=>$accessDirections!==[],'max_stack_density'=>$maxStackDensity!==null,'outer_dimensions'=>$outerDimensions!==null,'void_fill_reserve_ratio'=>$voidFillReserveRatio>0,'pallet_overhang_limit'=>$palletOverhangLimit!==null];
        foreach($present as $name=>$set)if($set)throw new ObjectRefusal('not_allowed',"{$name} cannot be combined with deformable_boundary");
    }
    /**
     * A gusseted bag's side gusset is required, positive and no deeper than the bag is wide: a
     * deeper fold would have no flat sheet left to fold from. A flat bag takes none. O(1).
     */
    private static function requireGussetRule(bool $gusseted,Dimensions $inner,?Length $sideGussetDepth):void
    {
        if($sideGussetDepth===null){
            if($gusseted)throw new ObjectRefusal('missing_field','a gusseted_bag requires side_gusset_depth');
            return;
        }
        if(!$gusseted)throw new ObjectRefusal('not_allowed','side_gusset_depth requires deformable_boundary gusseted_bag');
        if($sideGussetDepth->ticks<=0)throw new ObjectRefusal('invalid_value','side_gusset_depth must be positive');
        if($sideGussetDepth->ticks>$inner->width->ticks)throw new ObjectRefusal('invalid_value','side_gusset_depth exceeds inner_dimensions width');
    }
    /**
     * The fields a pallet with an overhang limit refuses: each reads the declared frame or a wall
     * the widened frame moves. The JSON path refuses the same combinations as
     * `unsupported_feature` first.
     *
     * @param list<Obstacle> $obstacles @param array{0:Axle,1:Axle}|null $axles @param list<string> $accessDirections
     */
    private static function requirePalletFields(array $obstacles,?array $axles,array $accessDirections,float $voidFillReserveRatio,?MadeToMeasure $madeToMeasure):void
    {
        $present=['obstacles'=>$obstacles!==[],'axles'=>$axles!==null,'access_directions'=>$accessDirections!==[],'void_fill_reserve_ratio'=>$voidFillReserveRatio>0,'made_to_measure'=>$madeToMeasure!==null];
        foreach($present as $name=>$set)if($set)throw new ObjectRefusal('not_allowed',"{$name} cannot be combined with pallet_overhang_limit");
    }
    /**
     * @param \Packvium\Unit\Weight|int|string|mixed[] $tareWeight
     * @param \Packvium\Unit\Weight|int|string|mixed[]|null $maxPayload
     * @param \Packvium\Unit\Weight|int|string|mixed[]|null $maxStackDensity
     */
    public static function create(string $id,Dimensions $innerDimensions,$tareWeight=0,$maxPayload=null,?Dimensions $outerDimensions=null,int $costMinor=0,?int $quantity=null,array $obstacles=[],array $tags=[],?int $maxItems=null,array $metadata=[],float $voidFillReserveRatio=0.0,array $tagLimits=[],$maxStackDensity=null,?array $axles=null,?RateTable $rateTable=null,array $accessDirections=[],?string $deformableBoundary=null,?Length $sideGussetDepth=null):self{return new self($id,$innerDimensions,$outerDimensions,Weight::parse($tareWeight),$maxPayload===null?null:Weight::parse($maxPayload),$costMinor,$quantity,$obstacles,$tags,$maxItems,$metadata,$voidFillReserveRatio,$tagLimits,$maxStackDensity===null?null:Weight::parse($maxStackDensity),$axles,$rateTable,$accessDirections,[],$deformableBoundary,null,null,null,$sideGussetDepth);}
}
