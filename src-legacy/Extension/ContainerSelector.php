<?php
declare(strict_types=1);
namespace Packvium\Extension;
use Packvium\Algorithm\SingleContainerSolution;
use Packvium\Domain\Container;
interface ContainerSelector{/** @return list<int|float|string> */public function score(Container $container,SingleContainerSolution $solution):array;}
