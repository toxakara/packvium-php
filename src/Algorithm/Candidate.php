<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Domain\{Dimensions,Point,Rotation};
final readonly class Candidate{/** @param list<int|float|string> $score */public function __construct(public Point $point,public Point $position,public Rotation $rotation,public Dimensions $dimensions,public Dimensions $envelopeDimensions,public array $score=[]){}}
