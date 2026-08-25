<?php
declare(strict_types=1);
namespace Packvium\Domain;
use InvalidArgumentException;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;
final class Item
{
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
    public function __construct(string $id,Dimensions $dimensions,?Weight $weight=null,int $quantity=1,array $allowedRotations=[Rotation::LWH,Rotation::LHW,Rotation::WLH,Rotation::WHL,Rotation::HLW,Rotation::HWL],bool $keepUpright=false,bool $stackable=true,bool $mustBeOnFloor=false,?Weight $maxTopLoad=null,float $minimumSupportRatio=0.0,?string $group=null,array $tags=[],array $incompatibleTags=[],int $priority=0,array $metadata=[],?int $maxStackedItems=null,array $eligibleContainerTags=[],?string $groundContactRule=null,?Length $nestingHeight=null,?int $stopIndex=null,?int $value=null)
    {
        if($id==='')throw new InvalidArgumentException('Item id is required');if($quantity<=0)throw new InvalidArgumentException('Item quantity must be positive');if($minimumSupportRatio<0||$minimumSupportRatio>1)throw new InvalidArgumentException('Minimum support ratio must be between 0 and 1');if($maxStackedItems!==null&&$maxStackedItems<1)throw new InvalidArgumentException('max_stacked_items must be at least 1');if($groundContactRule!==null&&!in_array($groundContactRule,self::GROUND_CONTACT_RULES,true))throw new InvalidArgumentException('ground_contact_rule must be one of '.implode(', ',self::GROUND_CONTACT_RULES));
        if($nestingHeight!==null&&!($nestingHeight->ticks>=0&&$nestingHeight->ticks<$dimensions->height->ticks))throw new InvalidArgumentException("nesting_height must be at least zero and strictly less than the item's own height");
        if($stopIndex!==null&&$stopIndex<0)throw new InvalidArgumentException('stop_index must be non-negative');
        if($value!==null&&$value<0)throw new InvalidArgumentException('value must be non-negative');
        if($keepUpright)$allowedRotations=array_values(array_filter($allowedRotations,static function (string $r) {
            return in_array($r,Rotation::upright(),true);
        }));if($allowedRotations===[])throw new InvalidArgumentException('At least one rotation must be allowed');
        $this->id=$id;$this->dimensions=$dimensions;$this->weight=$weight??new Weight(0);$this->quantity=$quantity;$this->allowedRotations=$allowedRotations;$this->keepUpright=$keepUpright;$this->stackable=$stackable;$this->mustBeOnFloor=$mustBeOnFloor;$this->maxTopLoad=$maxTopLoad;$this->minimumSupportRatio=$minimumSupportRatio;$this->groundContactRule=$groundContactRule;$this->group=$group;$this->tags=array_values(array_unique($tags));$this->incompatibleTags=array_values(array_unique($incompatibleTags));$this->priority=$priority;$this->metadata=$metadata;$this->maxStackedItems=$maxStackedItems;$this->eligibleContainerTags=array_values(array_unique($eligibleContainerTags));$this->nestingHeight=$nestingHeight;$this->stopIndex=$stopIndex;$this->value=$value;
    }
    /**
     * @param \Packvium\Unit\Weight|int|string|mixed[] $weight
     * @param \Packvium\Unit\Weight|int|string|mixed[]|null $maxTopLoad
     */
    public static function create(string $id,Dimensions $dimensions,$weight=0,int $quantity=1,array $allowedRotations=[Rotation::LWH,Rotation::LHW,Rotation::WLH,Rotation::WHL,Rotation::HLW,Rotation::HWL],bool $keepUpright=false,bool $stackable=true,bool $mustBeOnFloor=false,$maxTopLoad=null,float $minimumSupportRatio=0.0,?string $group=null,array $tags=[],array $incompatibleTags=[],int $priority=0,array $metadata=[],?int $maxStackedItems=null,array $eligibleContainerTags=[],?string $groundContactRule=null,?Length $nestingHeight=null,?int $stopIndex=null,?int $value=null):self{return new self($id,$dimensions,Weight::parse($weight),$quantity,$allowedRotations,$keepUpright,$stackable,$mustBeOnFloor,$maxTopLoad===null?null:Weight::parse($maxTopLoad),$minimumSupportRatio,$group,$tags,$incompatibleTags,$priority,$metadata,$maxStackedItems,$eligibleContainerTags,$groundContactRule,$nestingHeight,$stopIndex,$value);}
    /** @return list<ItemInstance> */ public function instances():array{$out=[];for($i=1;$i<=$this->quantity;$i++)$out[]=new ItemInstance($this,$i);return $out;}
}
