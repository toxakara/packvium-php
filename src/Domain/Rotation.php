<?php
declare(strict_types=1);
namespace Packvium\Domain;
enum Rotation:string
{
    case LWH='LWH';case LHW='LHW';case WLH='WLH';case WHL='WHL';case HLW='HLW';case HWL='HWL';
    /** @return list<self> */ public static function all():array{return self::cases();}
    /** @return list<self> */ public static function upright():array{return [self::LWH,self::WLH];}
}
