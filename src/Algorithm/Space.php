<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Domain\{Dimensions,Point};
final readonly class Space{public function __construct(public Point $origin,public Dimensions $dimensions){}}
