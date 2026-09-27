<?php
declare(strict_types=1);
namespace Packvium\Domain;
use InvalidArgumentException;
final class PackingRequest
{
    /**
     * @var list<Item>
     * @readonly
     */
    public $items;
    /**
     * @readonly
     * @var mixed[]
     */
    public $containers;
    /**
     * @readonly
     * @var mixed[]
     */
    public $fixedPlacements = [];
    /** @param list<Item> $items @param list<Container> $containers @param list<FixedPlacement> $fixedPlacements */ public function __construct(array $items,array $containers,array $fixedPlacements=[]){$this->items = $items;
    $this->containers = $containers;
    $this->fixedPlacements = $fixedPlacements;
    if($items===[])throw new InvalidArgumentException('At least one item is required');if($containers===[])throw new InvalidArgumentException('At least one container is required');self::unique(array_map(function (Item $i) {
        return $i->id;
    },$items),'item');self::unique(array_map(function (Container $c) {
        return $c->id;
    },$containers),'container');}
    /** @return list<ItemInstance> */ public function instances():array{$out=[];foreach($this->items as $i)array_push($out,...$i->instances());return $out;}
    private static function unique(array $ids,string $kind):void{if(count($ids)!==count(array_unique($ids)))throw new InvalidArgumentException(ucfirst($kind).' ids must be unique');}
}
