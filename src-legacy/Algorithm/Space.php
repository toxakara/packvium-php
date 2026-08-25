<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Domain\{Dimensions,Point};
final class Space{/**
 * @readonly
 * @var \Packvium\Domain\Point
 */
public $origin;
/**
 * @readonly
 * @var \Packvium\Domain\Dimensions
 */
public $dimensions;
public function __construct(Point $origin, Dimensions $dimensions)
{
    $this->origin = $origin;
    $this->dimensions = $dimensions;
}}
