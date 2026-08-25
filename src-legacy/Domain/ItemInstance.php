<?php
declare(strict_types=1);
namespace Packvium\Domain;
use Packvium\Unit\Weight;
final class ItemInstance{/**
 * @readonly
 * @var \Packvium\Domain\Item
 */
public $item;
/**
 * @readonly
 * @var int
 */
public $sequence;
public function __construct(Item $item, int $sequence)
{
    $this->item = $item;
    $this->sequence = $sequence;
}public function id():string{return $this->item->id.'#'.$this->sequence;}public function dimensions():Dimensions{return $this->item->dimensions;}public function weight():Weight{return $this->item->weight;}}
