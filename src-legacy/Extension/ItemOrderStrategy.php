<?php
declare(strict_types=1);
namespace Packvium\Extension;
use Packvium\Domain\ItemInstance;
interface ItemOrderStrategy{public function name():string;/** @param list<ItemInstance> $items @return list<ItemInstance> */public function order(array $items,int $seed):array;}
