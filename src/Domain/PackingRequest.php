<?php
declare(strict_types=1);
namespace Packvium\Domain;
use InvalidArgumentException;
final readonly class PackingRequest
{
    /** @param list<Item> $items @param list<Container> $containers */ public function __construct(public array $items,public array $containers){if($items===[])throw new InvalidArgumentException('At least one item is required');if($containers===[])throw new InvalidArgumentException('At least one container is required');self::unique(array_map(fn(Item $i)=>$i->id,$items),'item');self::unique(array_map(fn(Container $c)=>$c->id,$containers),'container');}
    /** @return list<ItemInstance> */ public function instances():array{$out=[];foreach($this->items as $i)array_push($out,...$i->instances());return $out;}
    private static function unique(array $ids,string $kind):void{if(count($ids)!==count(array_unique($ids)))throw new InvalidArgumentException(ucfirst($kind).' ids must be unique');}
}
