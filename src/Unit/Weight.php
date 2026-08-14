<?php
declare(strict_types=1);
namespace Packvium\Unit;
use InvalidArgumentException;
use Packvium\Support\RationalParser;
final readonly class Weight
{
    public const TICKS_PER_MG=8000; public const TICKS_PER_G=8000000; public const TICKS_PER_KG=8000000000; public const TICKS_PER_OZ=226796185; public const TICKS_PER_LB=3628738960;
    public function __construct(public int $ticks){if($ticks<0)throw new InvalidArgumentException('Weight cannot be negative');}
    public static function of(int|string $value,string $unit='g',Rounding $rounding=Rounding::Nearest):self{return new self(RationalParser::scaled($value,self::multiplier($unit),$rounding));}
    public static function parse(self|int|string|array $value,string $defaultUnit='g',Rounding $rounding=Rounding::Nearest):self
    {if($value instanceof self)return $value;if(is_array($value))return self::of((string)$value['value'],(string)($value['unit']??$defaultUnit),$rounding);if(is_string($value)&&preg_match('/^(.+?)\s*(mg|kg|g|oz|lbs?|ticks?)$/i',trim($value),$m))return self::of(trim($m[1]),$m[2],$rounding);return self::of($value,$defaultUnit,$rounding);}
    /** Exact decimal string. Float division loses digits once ticks exceed 2^53. */
    public function decimal(string $unit='g',int $places=8):string
    { return RationalParser::decimalString($this->ticks,self::multiplier($unit),$places); }
    /** @return array{ticks:int,value:string,unit:string} */ public function toArray(string $unit='g'):array{return ['ticks'=>$this->ticks,'value'=>$this->decimal($unit),'unit'=>$unit];}
    public static function multiplier(string $unit):int{return match(strtolower(trim($unit))){'tick','ticks'=>1,'mg'=>self::TICKS_PER_MG,'g'=>self::TICKS_PER_G,'kg'=>self::TICKS_PER_KG,'oz'=>self::TICKS_PER_OZ,'lb','lbs'=>self::TICKS_PER_LB,default=>throw new InvalidArgumentException("Unsupported weight unit: {$unit}")};}
}
