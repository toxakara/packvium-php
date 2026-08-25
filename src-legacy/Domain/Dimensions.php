<?php
declare(strict_types=1);
namespace Packvium\Domain;
use InvalidArgumentException;
use Packvium\Support\BigInt;
use Packvium\Unit\Length;
use Packvium\Unit\Rounding;
use Packvium\Unit\Weight;
final class Dimensions
{
    /**
     * @readonly
     * @var \Packvium\Unit\Length
     */
    public $length;
    /**
     * @readonly
     * @var \Packvium\Unit\Length
     */
    public $width;
    /**
     * @readonly
     * @var \Packvium\Unit\Length
     */
    public $height;
    public function __construct(Length $length,Length $width,Length $height){$this->length = $length;
    $this->width = $width;
    $this->height = $height;
    if(min($length->ticks,$width->ticks,$height->ticks)<=0)throw new InvalidArgumentException('All dimensions must be positive');}
    /**
     * @param int|string $l
     * @param int|string $w
     * @param int|string $h
     */
    public static function of($l,$w,$h,string $unit='mm',string $rounding=Rounding::Nearest):self{return new self(Length::of($l,$unit,$rounding),Length::of($w,$unit,$rounding),Length::of($h,$unit,$rounding));}
    /**
     * @param int|string $l
     * @param int|string $w
     * @param int|string $h
     */
    public static function mm($l,$w,$h,string $rounding=Rounding::Nearest):self{return self::of($l,$w,$h,'mm',$rounding);}
    /**
     * @param int|string $l
     * @param int|string $w
     * @param int|string $h
     */
    public static function inches($l,$w,$h,string $rounding=Rounding::Nearest):self{return self::of($l,$w,$h,'in',$rounding);}
    /** @param array{length:mixed,width:mixed,height:mixed} $data */ public static function fromArray(array $data,string $unit='mm',string $rounding=Rounding::Nearest):self{return new self(Length::parse($data['length'],$unit,$rounding),Length::parse($data['width'],$unit,$rounding),Length::parse($data['height'],$unit,$rounding));}
    public function rotated(string $r):self{switch ($r) {
        case Rotation::LWH:
            return new self($this->length,$this->width,$this->height);
        case Rotation::LHW:
            return new self($this->length,$this->height,$this->width);
        case Rotation::WLH:
            return new self($this->width,$this->length,$this->height);
        case Rotation::WHL:
            return new self($this->width,$this->height,$this->length);
        case Rotation::HLW:
            return new self($this->height,$this->length,$this->width);
        case Rotation::HWL:
            return new self($this->height,$this->width,$this->length);
    }}
    /** @param list<Rotation> $allowed @return list<array{0:Rotation,1:self}> */ public function uniqueRotations(array $allowed):array{$seen=[];$out=[];foreach($allowed as $r){$d=$this->rotated($r);$k=$d->length->ticks.':'.$d->width->ticks.':'.$d->height->ticks;if(!isset($seen[$k])){$seen[$k]=true;$out[]=[$r,$d];}}return $out;}
    public function fitsInside(self $o):bool{return $this->length->ticks<=$o->length->ticks&&$this->width->ticks<=$o->width->ticks&&$this->height->ticks<=$o->height->ticks;}
    public function expand(Length $c):self{$x=$c->ticks*2;return new self(new Length($this->length->ticks+$x),new Length($this->width->ticks+$x),new Length($this->height->ticks+$x));}
    public function volumeScore():float{return (float)$this->length->ticks*(float)$this->width->ticks*(float)$this->height->ticks;}
    public function volumeString():string{return BigInt::multiply(BigInt::multiply($this->length->ticks,$this->width->ticks),$this->height->ticks);}
    public function baseAreaScore():float{return (float)$this->length->ticks*(float)$this->width->ticks;}
    public function baseAreaTicks():int{return $this->length->ticks*$this->width->ticks;}
    /**
     * Exact volume as fixed-width integer chunks.
     *
     * A cubic-tick volume overflows a 64-bit integer, and comparing volumes as floats
     * silently loses ordering. Chunked, plain array comparison reproduces the exact
     * integer ordering Python and Rust use. @return list<int>
     */
    public function volumeKey():array{return BigInt::chunks($this->volumeString());}
    /** @return list<int> volumeKey negated, so ascending array order means largest first. */
    public function descendingVolumeKey():array{return array_map(static function (int $c): int {
        return -$c;
    },$this->volumeKey());}
    /** @return list<int> */
    public function descendingBaseAreaKey():array{return [-$this->baseAreaTicks()];}
    public function maxEdge():int{return max($this->length->ticks,$this->width->ticks,$this->height->ticks);}
    public function toArray(string $unit='mm'):array{return ['length'=>$this->length->toArray($unit),'width'=>$this->width->toArray($unit),'height'=>$this->height->toArray($unit)];}

    /**
     * The carrier-style volumetric weight of a box: (L * W * H in $lengthUnit) / divisor.
     *
     * $divisor is a carrier's published constant (commonly 139 or 166 for inches/lb,
     * 5000 or 6000 for cm/kg) -- it already encodes the length/weight convention, so
     * the caller names both explicitly rather than the library guessing one. BigInt
     * throughout: a cubed unit multiplier overflows a 64-bit integer well before the
     * final answer does, and this number is meant to be reproduced by hand against a
     * carrier's own calculator.
     */
    public function dimensionalWeight(int $divisor,string $lengthUnit='in',string $weightUnit='lb'):Weight
    {
        if($divisor<=0)throw new InvalidArgumentException('divisor must be positive');
        $lengthTicksPerUnit=(string)Length::multiplier($lengthUnit);
        $weightTicksPerUnit=(string)Weight::multiplier($weightUnit);
        $numerator=BigInt::multiply($this->volumeString(),$weightTicksPerUnit);
        $denominator=BigInt::multiply(BigInt::multiply($lengthTicksPerUnit,$lengthTicksPerUnit),BigInt::multiply($lengthTicksPerUnit,(string)$divisor));
        return new Weight((int)BigInt::divide($numerator,$denominator));
    }
}
