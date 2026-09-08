<?php
declare(strict_types=1);
namespace Packvium\Domain;
use InvalidArgumentException;
final class ShapeType
{
    public const RIGID_CUBOID='rigid_cuboid', CONVEX_HULL='convex_hull', COMPRESSIBLE='compressible';
    public static function cases():array{return [self::RIGID_CUBOID,self::CONVEX_HULL,self::COMPRESSIBLE];}
    public static function from(string $value):string{if(!in_array($value,self::cases(),true))throw new InvalidArgumentException('Unknown shape type '.$value);return $value;}
}
