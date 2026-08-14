<?php
declare(strict_types=1);
namespace Packvium\Objective;
final readonly class ObjectiveScore{/** @param list<int|float|string> $components */public function __construct(public array $components){}public function compare(self $other):int{return $this->components<=>$other->components;}}
