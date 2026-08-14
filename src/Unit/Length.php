<?php
declare(strict_types=1);
namespace Packvium\Unit;
use InvalidArgumentException;
use Packvium\Support\RationalParser;
final readonly class Length
{
    public const TICKS_PER_MM=16000; public const TICKS_PER_INCH=406400;
    public function __construct(public int $ticks){if($ticks<0)throw new InvalidArgumentException('Length cannot be negative');}
    public static function of(int|string $value,string $unit='mm',Rounding $rounding=Rounding::Nearest):self
    { return new self(RationalParser::scaled($value,self::multiplier($unit),$rounding)); }
    public static function mm(int|string $value,Rounding $rounding=Rounding::Nearest):self{return self::of($value,'mm',$rounding);}
    public static function inches(int|string $value,Rounding $rounding=Rounding::Nearest):self{return self::of($value,'in',$rounding);}
    public static function parse(self|int|string|array $value,string $defaultUnit='mm',Rounding $rounding=Rounding::Nearest):self
    {
        if($value instanceof self)return $value;
        if(is_array($value))return self::of((string)$value['value'],(string)($value['unit']??$defaultUnit),$rounding);
        if(is_string($value)&&preg_match('/^(.+?)\s*(mm|cm|m|in|inch|inches|ft|ticks?)$/i',trim($value),$m))return self::of(trim($m[1]),$m[2],$rounding);
        return self::of($value,$defaultUnit,$rounding);
    }
    /** Exact decimal string. Float division loses digits once ticks exceed 2^53. */
    public function decimal(string $unit='mm',int $places=8):string
    { return RationalParser::decimalString($this->ticks,self::multiplier($unit),$places); }
    /** @return array{ticks:int,value:string,unit:string} */
    public function toArray(string $unit='mm'):array{return ['ticks'=>$this->ticks,'value'=>$this->decimal($unit),'unit'=>$unit];}
    public static function multiplier(string $unit):int{return match(strtolower(trim($unit))){'tick','ticks'=>1,'mm','millimeter','millimeters'=>self::TICKS_PER_MM,'cm'=>self::TICKS_PER_MM*10,'m'=>self::TICKS_PER_MM*1000,'in','inch','inches'=>self::TICKS_PER_INCH,'ft'=>self::TICKS_PER_INCH*12,default=>throw new InvalidArgumentException("Unsupported length unit: {$unit}")};}
}
