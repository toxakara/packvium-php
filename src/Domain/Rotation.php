<?php
declare(strict_types=1);
namespace Packvium\Domain;
/**
 * The 24 proper rotations in canonical table order (`Orientations`, 1.10): the six legacy codes,
 * which are the whole vocabulary of a box, then the 18 signed codes only a `convex_hull` may name.
 * Case order is the tie-break order; codes are never compared as strings.
 */
enum Rotation:string
{
    case LWH='LWH';case LHW='LHW';case WLH='WLH';case WHL='WHL';case HLW='HLW';case HWL='HWL';
    case PL_NW_NH='+L-W-H';case NL_PW_NH='-L+W-H';case NL_NW_PH='-L-W+H';
    case PL_NH_PW='+L-H+W';case PL_PH_NW='+L+H-W';case NL_NH_NW='-L-H-W';
    case PW_NL_PH='+W-L+H';case PW_PL_NH='+W+L-H';case NW_NL_NH='-W-L-H';
    case PW_NH_NL='+W-H-L';case NW_PH_NL='-W+H-L';case NW_NH_PL='-W-H+L';
    case PH_NL_NW='+H-L-W';case NH_PL_NW='-H+L-W';case NH_NL_PW='-H-L+W';
    case PH_NW_PL='+H-W+L';case PH_PW_NL='+H+W-L';case NH_NW_NL='-H-W-L';
    /** The six legacy codes: an item's default list, and every box a cuboid can make. @return list<self> */ public static function all():array{return [self::LWH,self::LHW,self::WLH,self::WHL,self::HLW,self::HWL];}
    /** The legacy codes `keep_upright` leaves of the default list. @return list<self> */ public static function upright():array{return [self::LWH,self::WLH];}
    /** All 24 proper rotations in table order; the 18 past `all()` need a hull. @return list<self> */ public static function every():array{return self::cases();}
}
