<?php
declare(strict_types=1);
namespace Packvium\Unit;
use InvalidArgumentException;
use Packvium\Support\RationalParser;
final class Length
{
    /**
     * @readonly
     * @var int
     */
    public $ticks;
    public const TICKS_PER_MM=16000; public const TICKS_PER_INCH=406400;
    public function __construct(int $ticks){$this->ticks = $ticks;
    if($ticks<0)throw new InvalidArgumentException('Length cannot be negative');}
    /**
     * @param int|string $value
     */
    public static function of($value,string $unit='mm',string $rounding=Rounding::Nearest):self
    { return new self(RationalParser::scaled($value,self::multiplier($unit),$rounding)); }
    /**
     * @param int|string $value
     */
    public static function mm($value,string $rounding=Rounding::Nearest):self{return self::of($value,'mm',$rounding);}
    /**
     * @param int|string $value
     */
    public static function inches($value,string $rounding=Rounding::Nearest):self{return self::of($value,'in',$rounding);}
    /**
     * @param $this|int|string|mixed[] $value
     */
    public static function parse($value,string $defaultUnit='mm',string $rounding=Rounding::Nearest):self
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
    public static function multiplier(string $unit):int{switch (strtolower(trim($unit))) {
        case 'tick':
        case 'ticks':
            return 1;
        case 'mm':
        case 'millimeter':
        case 'millimeters':
            return self::TICKS_PER_MM;
        case 'cm':
            return self::TICKS_PER_MM*10;
        case 'm':
            return self::TICKS_PER_MM*1000;
        case 'in':
        case 'inch':
        case 'inches':
            return self::TICKS_PER_INCH;
        case 'ft':
            return self::TICKS_PER_INCH*12;
        default:
            throw new InvalidArgumentException("Unsupported length unit: {$unit}");
    }}
}
