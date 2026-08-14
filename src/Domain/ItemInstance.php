<?php
declare(strict_types=1);
namespace Packvium\Domain;
use Packvium\Unit\Weight;
final readonly class ItemInstance{public function __construct(public Item $item,public int $sequence){}public function id():string{return $this->item->id.'#'.$this->sequence;}public function dimensions():Dimensions{return $this->item->dimensions;}public function weight():Weight{return $this->item->weight;}}
