<?php
declare(strict_types=1);
namespace Packvium\Domain;
use InvalidArgumentException;
use Packvium\Support\BigInt;
use Packvium\Unit\Length;
use Packvium\Unit\Rounding;
use Packvium\Unit\Weight;
final readonly class Dimensions
{
    public function __construct(public Length $length,public Length $width,public Length $height){if(min($length->ticks,$width->ticks,$height->ticks)<=0)throw new InvalidArgumentException('All dimensions must be positive');}
    public static function of(int|string $l,int|string $w,int|string $h,string $unit='mm',Rounding $rounding=Rounding::Nearest):self{return new self(Length::of($l,$unit,$rounding),Length::of($w,$unit,$rounding),Length::of($h,$unit,$rounding));}
    public static function mm(int|string $l,int|string $w,int|string $h,Rounding $rounding=Rounding::Nearest):self{return self::of($l,$w,$h,'mm',$rounding);}
    public static function inches(int|string $l,int|string $w,int|string $h,Rounding $rounding=Rounding::Nearest):self{return self::of($l,$w,$h,'in',$rounding);}
    /** @param array{length:mixed,width:mixed,height:mixed} $data */ public static function fromArray(array $data,string $unit='mm',Rounding $rounding=Rounding::Nearest):self{return new self(Length::parse($data['length'],$unit,$rounding),Length::parse($data['width'],$unit,$rounding),Length::parse($data['height'],$unit,$rounding));}
    public function rotated(Rotation $r):self{return match($r){Rotation::LWH=>new self($this->length,$this->width,$this->height),Rotation::LHW=>new self($this->length,$this->height,$this->width),Rotation::WLH=>new self($this->width,$this->length,$this->height),Rotation::WHL=>new self($this->width,$this->height,$this->length),Rotation::HLW=>new self($this->height,$this->length,$this->width),Rotation::HWL=>new self($this->height,$this->width,$this->length)};}
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
    public function descendingVolumeKey():array{return array_map(static fn(int $c):int=>-$c,$this->volumeKey());}
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
