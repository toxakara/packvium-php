<?php
declare(strict_types=1);
namespace Packvium\Domain;
use InvalidArgumentException;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;
final class Item
{
    /**
     * The largest `stop_index` every engine can carry identically.
     *
     * Route order is decided by comparing stop indices, and JavaScript holds numbers as
     * doubles: `JSON.parse` collapses 2**53 + 1 to 2**53 before any constraint sees it,
     * so two consecutive stops above this bound become one number there while PHP's
     * 64-bit integers keep them apart. The JavaScript engine already refuses anything
     * outside the safe range; this makes the other engines agree rather than accept a
     * value they would order differently.
     */
    public const MAX_EXACT_STOP_INDEX = 9007199254740991;

    public const GROUND_CONTACT_RULES=['free','covered','single','multiple'];
    /**
     * @readonly
     * @var string
     */
    public $id; /**
     * @readonly
     * @var \Packvium\Domain\Dimensions
     */
    public $dimensions; /**
     * @readonly
     * @var \Packvium\Unit\Weight
     */
    public $weight; /**
     * @readonly
     * @var int
     */
    public $quantity; /** @var list<Rotation>
     * @readonly */
    public $allowedRotations; /**
     * @readonly
     * @var bool
     */
    public $keepUpright; /**
     * @readonly
     * @var bool
     */
    public $stackable; /**
     * @readonly
     * @var bool
     */
    public $mustBeOnFloor; /**
     * @readonly
     * @var \Packvium\Unit\Weight|null
     */
    public $maxTopLoad; /**
     * @readonly
     * @var float
     */
    public $minimumSupportRatio; /**
     * @readonly
     * @var string|null
     */
    public $groundContactRule; /**
     * @readonly
     * @var string|null
     */
    public $group; /** @var list<string>
     * @readonly */
    public $tags; /** @var list<string>
     * @readonly */
    public $incompatibleTags; /**
     * @readonly
     * @var int
     */
    public $priority; /**
     * @readonly
     * @var mixed[]
     */
    public $metadata; /**
     * @readonly
     * @var int|null
     */
    public $maxStackedItems; /** @var list<string>
     * @readonly */
    public $eligibleContainerTags; /**
     * @readonly
     * @var \Packvium\Unit\Length|null
     */
    public $nestingHeight;
    // Which stop along a multi-stop route this item is unloaded at, lower leaving
    // first. null means the item is not on a route at all -- every existing
    // single-stop request leaves this unset, so the route check has nothing to
    // enforce and behaves exactly as it did before this field existed.
    /**
     * @readonly
     * @var int|null
     */
    public $stopIndex;
    // Exact, unit-less economic worth for the `maximum_value` objective,
    // which ranks by the total value of unpacked items rather than treating every
    // item as equally worth leaving behind. null (the default) never affects
    // placement or scoring under any objective, maximum_value included.
    /**
     * @readonly
     * @var int|null
     */
    public $value;
    // How much of `dimensions` this item actually occupies. The default is the
    // contract as it stood before this epic -- the item is its box -- and the three fields
    // below are the data the two narrower shapes need. Each belongs to exactly one shape;
    // setting one against the wrong shape is refused rather than ignored, because a
    // compression_ratio silently dropped on a convex_hull reads as an item that was packed
    // to limits it never had.
    /**
     * @readonly
     * @var \Packvium\Domain\ShapeType
     */
    public $shapeType;
    /** @var list<array{int,int,int}>|null
     * @readonly */
    public $hullVertices;
    /**
     * @readonly
     * @var int|null
     */
    public $compressionRatioPpm;
    /**
     * @readonly
     * @var int|null
     */
    public $maxCompressionPressureKpa;
    /**
     * @param mixed $shapeType
     */
    public function __construct(string $id,Dimensions $dimensions,?Weight $weight=null,int $quantity=1,array $allowedRotations=[Rotation::LWH,Rotation::LHW,Rotation::WLH,Rotation::WHL,Rotation::HLW,Rotation::HWL],bool $keepUpright=false,bool $stackable=true,bool $mustBeOnFloor=false,?Weight $maxTopLoad=null,float $minimumSupportRatio=0.0,?string $group=null,array $tags=[],array $incompatibleTags=[],int $priority=0,array $metadata=[],?int $maxStackedItems=null,array $eligibleContainerTags=[],?string $groundContactRule=null,?Length $nestingHeight=null,?int $stopIndex=null,?int $value=null,$shapeType=ShapeType::RIGID_CUBOID,?array $hullVertices=null,?int $compressionRatioPpm=null,?int $maxCompressionPressureKpa=null)
    {
        if($id==='')throw new InvalidArgumentException('Item id is required');if($quantity<=0)throw new InvalidArgumentException('Item quantity must be positive');if($minimumSupportRatio<0||$minimumSupportRatio>1)throw new InvalidArgumentException('Minimum support ratio must be between 0 and 1');if($maxStackedItems!==null&&$maxStackedItems<1)throw new InvalidArgumentException('max_stacked_items must be at least 1');if($groundContactRule!==null&&!in_array($groundContactRule,self::GROUND_CONTACT_RULES,true))throw new InvalidArgumentException('ground_contact_rule must be one of '.implode(', ',self::GROUND_CONTACT_RULES));
        if($nestingHeight!==null&&!($nestingHeight->ticks>=0&&$nestingHeight->ticks<$dimensions->height->ticks))throw new InvalidArgumentException("nesting_height must be at least zero and strictly less than the item's own height");
        if($stopIndex!==null&&($stopIndex<0||$stopIndex>self::MAX_EXACT_STOP_INDEX))throw new InvalidArgumentException('stop_index must be a non-negative safe integer');
        if($value!==null&&$value<0)throw new InvalidArgumentException('value must be non-negative');
        if($keepUpright)$allowedRotations=array_values(array_filter($allowedRotations,static function (string $r) {
            return in_array($r,Rotation::upright(),true);
        }));if($allowedRotations===[])throw new InvalidArgumentException('At least one rotation must be allowed');
        $this->id=$id;$this->dimensions=$dimensions;$this->weight=$weight??new Weight(0);$this->quantity=$quantity;$this->allowedRotations=$allowedRotations;$this->keepUpright=$keepUpright;$this->stackable=$stackable;$this->mustBeOnFloor=$mustBeOnFloor;$this->maxTopLoad=$maxTopLoad;$this->minimumSupportRatio=$minimumSupportRatio;$this->groundContactRule=$groundContactRule;$this->group=$group;$this->tags=array_values(array_unique($tags));$this->incompatibleTags=array_values(array_unique($incompatibleTags));$this->priority=$priority;$this->metadata=$metadata;$this->maxStackedItems=$maxStackedItems;$this->eligibleContainerTags=array_values(array_unique($eligibleContainerTags));$this->nestingHeight=$nestingHeight;$this->stopIndex=$stopIndex;$this->value=$value;
        $this->shapeType=$shapeType;
        $this->hullVertices=self::admitShape($shapeType,$hullVertices,$compressionRatioPpm,$maxCompressionPressureKpa,$dimensions,$nestingHeight);
        $this->compressionRatioPpm=$compressionRatioPpm;$this->maxCompressionPressureKpa=$maxCompressionPressureKpa;
    }
    /**
     * @param \Packvium\Unit\Weight|int|string|mixed[] $weight
     * @param \Packvium\Unit\Weight|int|string|mixed[]|null $maxTopLoad
     * @param mixed $shapeType
     */
    public static function create(string $id,Dimensions $dimensions,$weight=0,int $quantity=1,array $allowedRotations=[Rotation::LWH,Rotation::LHW,Rotation::WLH,Rotation::WHL,Rotation::HLW,Rotation::HWL],bool $keepUpright=false,bool $stackable=true,bool $mustBeOnFloor=false,$maxTopLoad=null,float $minimumSupportRatio=0.0,?string $group=null,array $tags=[],array $incompatibleTags=[],int $priority=0,array $metadata=[],?int $maxStackedItems=null,array $eligibleContainerTags=[],?string $groundContactRule=null,?Length $nestingHeight=null,?int $stopIndex=null,?int $value=null,$shapeType=ShapeType::RIGID_CUBOID,?array $hullVertices=null,?int $compressionRatioPpm=null,?int $maxCompressionPressureKpa=null):self{return new self($id,$dimensions,Weight::parse($weight),$quantity,$allowedRotations,$keepUpright,$stackable,$mustBeOnFloor,$maxTopLoad===null?null:Weight::parse($maxTopLoad),$minimumSupportRatio,$group,$tags,$incompatibleTags,$priority,$metadata,$maxStackedItems,$eligibleContainerTags,$groundContactRule,$nestingHeight,$stopIndex,$value,$shapeType,$hullVertices,$compressionRatioPpm,$maxCompressionPressureKpa);}
    /**
     * Admit this item's shape, or refuse it with the reason.
     *
     * Kept out of the constructor body because it is the only rule here spanning four fields
     * at once: which are required, which are forbidden, and what the survivors must agree
     * with. Mirrors the Python engine exactly -- the two must refuse the same requests.
     * @param mixed $shapeType
     */
    private static function admitShape($shapeType,?array $hullVertices,?int $ratioPpm,?int $limitKpa,Dimensions $dimensions,?Length $nestingHeight):?array
    {
        switch ($shapeType) {
            case ShapeType::CONVEX_HULL:
                $foreign = ['compression_ratio'=>$ratioPpm,'max_compression_pressure_kpa'=>$limitKpa];
                break;
            case ShapeType::COMPRESSIBLE:
                $foreign = ['hull_vertices'=>$hullVertices];
                break;
            case ShapeType::RIGID_CUBOID:
                $foreign = ['hull_vertices'=>$hullVertices,'compression_ratio'=>$ratioPpm,'max_compression_pressure_kpa'=>$limitKpa];
                break;
        }
        foreach($foreign as $name=>$value){
            if($value!==null)throw new InvalidArgumentException("{$name} is not part of a {$shapeType->value} item");
        }
        // Both rewrite occupied height. Choosing an order silently would give four engines
        // four contracts, so the interaction is refused until a task defines it.
        if($nestingHeight!==null&&$shapeType!==ShapeType::RIGID_CUBOID)
            throw new InvalidArgumentException("nesting_height with shape_type {$shapeType->value} is not supported yet");
        if($shapeType===ShapeType::CONVEX_HULL){
            if($hullVertices===null)throw new InvalidArgumentException('a convex_hull item requires hull_vertices');
            $hullVertices=HullShape::validate($hullVertices);
            [$low,$high]=HullShape::boundingExtent($hullVertices);
            $declared=[$dimensions->length->ticks,$dimensions->width->ticks,$dimensions->height->ticks];
            for($axis=0;$axis<3;$axis++){
                // `dimensions` stays the broad phase and the candidate-generation envelope, so
                // a hull poking out of it would be collision-tested against space the solver
                // never reserved.
                if($high[$axis]-$low[$axis]>$declared[$axis])
                    throw new InvalidArgumentException('hull_vertices span does not fit inside dimensions');
            }
            return $hullVertices;
        }
        if($shapeType===ShapeType::COMPRESSIBLE){
            if($ratioPpm===null||$limitKpa===null)
                throw new InvalidArgumentException('a compressible item requires both compression_ratio and max_compression_pressure_kpa');
            if($ratioPpm<0||$ratioPpm>Compression::PPM)
                throw new InvalidArgumentException('compression_ratio must be between zero and one');
            if($limitKpa<0)
                throw new InvalidArgumentException('max_compression_pressure_kpa cannot be negative');
        }
        return $hullVertices;
    }

    /**
     * Whether what rests on this item can change a verdict.
     *
     * The three original reasons are about the item refusing load. The fourth is about the
     * item *yielding* to it: a compressible item needs the cumulative mass above it before
     * its occupied height -- or its crush limit -- means anything.
     */
    public function isStackSensitive():bool
    {
        return !$this->stackable||$this->maxTopLoad!==null||$this->maxStackedItems!==null||$this->maxCompressionPressureKpa!==null;
    }

    /** @return list<ItemInstance> */ public function instances():array{$out=[];for($i=1;$i<=$this->quantity;$i++)$out[]=new ItemInstance($this,$i);return $out;}
}
