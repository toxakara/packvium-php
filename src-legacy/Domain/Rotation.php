<?php
declare(strict_types=1);
namespace Packvium\Domain;
use InvalidArgumentException;
final class Rotation
{
    public const LWH='LWH', LHW='LHW', WLH='WLH', WHL='WHL', HLW='HLW', HWL='HWL';
    public const PL_NW_NH='+L-W-H', NL_PW_NH='-L+W-H', NL_NW_PH='-L-W+H', PL_NH_PW='+L-H+W', PL_PH_NW='+L+H-W', NL_NH_NW='-L-H-W', PW_NL_PH='+W-L+H', PW_PL_NH='+W+L-H', NW_NL_NH='-W-L-H', PW_NH_NL='+W-H-L', NW_PH_NL='-W+H-L', NW_NH_PL='-W-H+L', PH_NL_NW='+H-L-W', NH_PL_NW='-H+L-W', NH_NL_PW='-H-L+W', PH_NW_PL='+H-W+L', PH_PW_NL='+H+W-L', NH_NW_NL='-H-W-L';
    public static function cases():array{return [self::LWH,self::LHW,self::WLH,self::WHL,self::HLW,self::HWL,self::PL_NW_NH,self::NL_PW_NH,self::NL_NW_PH,self::PL_NH_PW,self::PL_PH_NW,self::NL_NH_NW,self::PW_NL_PH,self::PW_PL_NH,self::NW_NL_NH,self::PW_NH_NL,self::NW_PH_NL,self::NW_NH_PL,self::PH_NL_NW,self::NH_PL_NW,self::NH_NL_PW,self::PH_NW_PL,self::PH_PW_NL,self::NH_NW_NL];}
    public static function all():array{return [self::LWH,self::LHW,self::WLH,self::WHL,self::HLW,self::HWL];}
    public static function upright():array{return [self::LWH,self::WLH];}
    public static function every():array{return self::cases();}
    public static function from(string $value):string{if(!in_array($value,self::cases(),true))throw new InvalidArgumentException('Unknown rotation '.$value);return $value;}
}
