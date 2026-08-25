<?php
declare(strict_types=1);
namespace Packvium\Domain;
use InvalidArgumentException;
final class Rotation
{
    public const LWH='LWH', LHW='LHW', WLH='WLH', WHL='WHL', HLW='HLW', HWL='HWL';
    public static function cases():array{return [self::LWH,self::LHW,self::WLH,self::WHL,self::HLW,self::HWL];}
    public static function all():array{return self::cases();}
    public static function upright():array{return [self::LWH,self::WLH];}
    public static function from(string $value):string{if(!in_array($value,self::cases(),true))throw new InvalidArgumentException('Unknown rotation '.$value);return $value;}
}
