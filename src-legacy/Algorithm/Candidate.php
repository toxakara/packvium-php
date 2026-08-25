<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Domain\{Dimensions,Point,Rotation};
final class Candidate{/**
 * @readonly
 * @var \Packvium\Domain\Point
 */
public $point;
/**
 * @readonly
 * @var \Packvium\Domain\Point
 */
public $position;
/**
 * @readonly
 * @var string
 */
public $rotation;
/**
 * @readonly
 * @var \Packvium\Domain\Dimensions
 */
public $dimensions;
/**
 * @readonly
 * @var \Packvium\Domain\Dimensions
 */
public $envelopeDimensions;
/**
 * @var list<int|float|string>
 * @readonly
 */
public $score = [];
/** @param list<int|float|string> $score */public function __construct(Point $point, Point $position, string $rotation, Dimensions $dimensions, Dimensions $envelopeDimensions, array $score=[])
{
    $this->point = $point;
    $this->position = $position;
    $this->rotation = $rotation;
    $this->dimensions = $dimensions;
    $this->envelopeDimensions = $envelopeDimensions;
    $this->score = $score;
}}
