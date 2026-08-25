<?php
declare(strict_types=1);
namespace Packvium\Objective;
final class ObjectiveScore{/**
 * @var list<int|float|string>
 * @readonly
 */
public $components;
/** @param list<int|float|string> $components */public function __construct(array $components)
{
    $this->components = $components;
}public function compare(self $other):int{return $this->components<=>$other->components;}}
