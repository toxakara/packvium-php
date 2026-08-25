<?php
declare(strict_types=1);
namespace Packvium\Domain;
use InvalidArgumentException;
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
    /** @param list<Obstacle> $obstacles @param list<string> $tags @param array<string,int> $tagLimits @param array{0:Axle,1:Axle}|null $axles */
    public function __construct(string $id,Dimensions $innerDimensions,?Dimensions $outerDimensions=null,?Weight $tareWeight=null,?Weight $maxPayload=null,int $costMinor=0,?int $quantity=null,array $obstacles=[],array $tags=[],?int $maxItems=null,array $metadata=[],float $voidFillReserveRatio=0.0,array $tagLimits=[],?Weight $maxStackDensity=null,?array $axles=null,?RateTable $rateTable=null)
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
    if($id==='')throw new InvalidArgumentException('Container id is required');if($quantity!==null&&$quantity<=0)throw new InvalidArgumentException('Container quantity must be positive');if($costMinor<0)throw new InvalidArgumentException('Container cost cannot be negative');if($voidFillReserveRatio<0||$voidFillReserveRatio>1)throw new InvalidArgumentException('void_fill_reserve_ratio must be between 0 and 1');foreach($tagLimits as $limit)if($limit<1)throw new InvalidArgumentException('tag_limits must be at least 1');if($outerDimensions!==null&&!$innerDimensions->fitsInside($outerDimensions))throw new InvalidArgumentException('Outer dimensions cannot be smaller than inner dimensions');$boundary=new AxisAlignedBox(new Point(0,0,0),$innerDimensions);foreach($obstacles as $o)foreach($o->boxes() as $box)if(!$boundary->contains($box))throw new InvalidArgumentException("Obstacle {$o->id} lies outside container");
        if($axles!==null){[$front,$rear]=$axles;if($front->position->ticks>=$rear->position->ticks)throw new InvalidArgumentException('The front axle must be strictly nearer the origin than the rear axle');if($front->position->ticks<0||$rear->position->ticks>$innerDimensions->length->ticks)throw new InvalidArgumentException("Axle positions must lie within the container's length");}}
    /**
     * @param \Packvium\Unit\Weight|int|string|mixed[] $tareWeight
     * @param \Packvium\Unit\Weight|int|string|mixed[]|null $maxPayload
     * @param \Packvium\Unit\Weight|int|string|mixed[]|null $maxStackDensity
     */
    public static function create(string $id,Dimensions $innerDimensions,$tareWeight=0,$maxPayload=null,?Dimensions $outerDimensions=null,int $costMinor=0,?int $quantity=null,array $obstacles=[],array $tags=[],?int $maxItems=null,array $metadata=[],float $voidFillReserveRatio=0.0,array $tagLimits=[],$maxStackDensity=null,?array $axles=null,?RateTable $rateTable=null):self{return new self($id,$innerDimensions,$outerDimensions,Weight::parse($tareWeight),$maxPayload===null?null:Weight::parse($maxPayload),$costMinor,$quantity,$obstacles,$tags,$maxItems,$metadata,$voidFillReserveRatio,$tagLimits,$maxStackDensity===null?null:Weight::parse($maxStackDensity),$axles,$rateTable);}
}
