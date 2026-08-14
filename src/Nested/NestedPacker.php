<?php
declare(strict_types=1);
namespace Packvium\Nested;
use Packvium\Config\PackingConfig;
use Packvium\Packer;
final class NestedPacker{public function pack(array $items,array $levels):NestedPackingResult{$current=$items;$results=[];foreach($levels as $level){$result=(new Packer($level->config??PackingConfig::balanced()))->pack($current,$level->containers);$results[]=$result;if(!$result->complete())break;$current=array_map(fn($c)=>$c->asItem(),$result->containers);}return new NestedPackingResult($results);}}
