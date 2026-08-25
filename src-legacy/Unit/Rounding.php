<?php
declare(strict_types=1);
namespace Packvium\Unit;
use InvalidArgumentException;
final class Rounding
{
    public const Floor='floor', Ceil='ceil', Nearest='nearest';
    public static function cases():array{return [self::Floor,self::Ceil,self::Nearest];}
    public static function from(string $value):string{if(!in_array($value,self::cases(),true))throw new InvalidArgumentException('Unknown rounding mode '.$value);return $value;}
}
