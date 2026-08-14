<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container,ItemInstance};
interface SingleContainerSolver{public function name():string;/** @param list<ItemInstance> $items */public function packOne(Container $container,int $sequence,array $items,PackingConfig $config,SearchStats $stats,Deadline $deadline):SingleContainerSolution;}
