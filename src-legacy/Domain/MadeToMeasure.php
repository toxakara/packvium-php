<?php
declare(strict_types=1);
namespace Packvium\Domain;
use Packvium\Support\ObjectRefusal;
use Packvium\Support\BigInt;
/**
 * A made-to-measure container's machine (docs/MADE-TO-MEASURE-CONTAINERS.md "1.6
 * implementation contract").
 *
 * The container's declared inner dimensions are the largest box the machine can cut; this adds
 * the smallest size and the cut step per axis, in ticks, and the material and board prices. A
 * trial packs the round's items into up to four solving envelopes (`solvingEnvelopes`) and keeps
 * the first that places every item; the committed box is the achieved extent rounded up onto the
 * machine grid (`Container::fabricate`). Areas, volumes and products are BigInt decimal strings,
 * the same exact integers Python and Rust compute, so every engine derives the same rungs, box
 * and price.
 */
final class MadeToMeasure
{
    /**
     * @var array{0: int, 1: int, 2: int}
     * @readonly
     */
    public $minimum;
    /**
     * @var array{0: int, 1: int, 2: int}
     * @readonly
     */
    public $increment;
    /**
     * @readonly
     * @var int
     */
    public $costPerSquareMetreMinor = 0;
    /**
     * @readonly
     * @var int
     */
    public $tarePerSquareMetre = 0;
    /**
     * @var array{0: int, 1: int, 2: int}
     * @readonly
     */
    public $outerAllowance = [0,0,0];
    /** `docs/UNITS-AND-NUMERICS.md`: one length tick is 1/16 000 000 m, so 1 m^2 is this many square ticks. */
    public const SQUARE_METRE_TICKS2='256000000000000';
    public const MAX_EXACT_INTEGER='9007199254740991';
    /** Each compact rung's side as a fraction of the integer cube root of the round's volume. */
    public const RUNG_FACTORS=[[5,4],[3,2],[2,1]];

    /**
     * @param array{0:int,1:int,2:int} $minimum
     * @param array{0:int,1:int,2:int} $increment
     * @param array{0:int,1:int,2:int} $outerAllowance
     */
    public function __construct(array $minimum,array $increment,int $costPerSquareMetreMinor=0,int $tarePerSquareMetre=0,array $outerAllowance=[0,0,0])
    {
        $this->minimum = $minimum;
        $this->increment = $increment;
        $this->costPerSquareMetreMinor = $costPerSquareMetreMinor;
        $this->tarePerSquareMetre = $tarePerSquareMetre;
        $this->outerAllowance = $outerAllowance;
        if(min($minimum)<=0)throw new ObjectRefusal('invalid_value','made_to_measure minimum must be positive');
        if(min($increment)<=0)throw new ObjectRefusal('invalid_value','made_to_measure increment must be positive');
        if($costPerSquareMetreMinor<0||$tarePerSquareMetre<0)throw new ObjectRefusal('below_minimum','made_to_measure prices cannot be negative');
        if(min($outerAllowance)<0)throw new ObjectRefusal('negative_measure','made_to_measure outer_allowance cannot be negative');
    }

    /**
     * Refuse a machine whose grid does not land on its own maximum. O(1). Every reachable size
     * is then unambiguous, and rounding an achieved extent inside the maximum up onto the grid
     * can never pass it (D3, D4).
     *
     * @param array{0:int,1:int,2:int} $maximum
     */
    public function admits(array $maximum):void
    {
        foreach(['length','width','height'] as $axis=>$name){
            if($this->minimum[$axis]>$maximum[$axis])throw new ObjectRefusal('invalid_value',"made_to_measure minimum {$name} exceeds inner_dimensions");
            if(($maximum[$axis]-$this->minimum[$axis])%$this->increment[$axis]!==0)throw new ObjectRefusal('invalid_value',"made_to_measure increment {$name} does not divide inner_dimensions minus minimum");
        }
    }

    /** The smallest cuttable size on `$axis` that is at least `$value`. */
    public function grid(int $axis,int $value):int
    {
        $minimum=$this->minimum[$axis];$step=$this->increment[$axis];
        return $minimum+intdiv(max($value,$minimum)-$minimum+$step-1,$step)*$step;
    }

    /** `grid`, capped at the machine maximum: sizes a solving envelope, never a box. */
    public function clamp(int $axis,int $value,int $maximum):int{return min($maximum,$this->grid($axis,$value));}

    /** The inner surface of a closed box, 2(LW + LH + WH), in square ticks. @param array{0:int,1:int,2:int} $sides */
    public static function surfaceArea(array $sides):string
    {
        [$l,$w,$h]=$sides;
        return BigInt::multiply('2',BigInt::add(BigInt::add(BigInt::multiply($l,$w),BigInt::multiply($l,$h)),BigInt::multiply($w,$h)));
    }

    /**
     * ceil(rate * area / 1 m^2), as `p*q + ceil(p*r / T^2)` with `q, r = divmod(area, T^2)` --
     * the split Rust needs to stay inside i128, kept here so the engines share one formula.
     */
    public static function perSquareMetre(int $rate,string $area):string
    {
        $whole=BigInt::divide($area,self::SQUARE_METRE_TICKS2);
        $fraction=BigInt::subtract($area,BigInt::multiply($whole,self::SQUARE_METRE_TICKS2));
        $numerator=BigInt::multiply($rate,$fraction);
        $part=BigInt::divide($numerator,self::SQUARE_METRE_TICKS2);
        if(BigInt::compare(BigInt::multiply($part,self::SQUARE_METRE_TICKS2),$numerator)<0)$part=BigInt::add($part,'1');
        return BigInt::add(BigInt::multiply($rate,$whole),$part);
    }

    /**
     * A box's price: the container's own fee plus its material at `$priceMinor` per square
     * metre, rounded up. Cost grows with every side, so the machine maximum bounds them all.
     *
     * @param array{0:int,1:int,2:int} $sides
     */
    public static function boxCostFor(int $priceMinor,int $feeMinor,array $sides):string
    {
        return BigInt::add((string)$feeMinor,self::perSquareMetre($priceMinor,self::surfaceArea($sides)));
    }

    /** @param array{0:int,1:int,2:int} $sides */
    public function boxCostMinor(int $feeMinor,array $sides):int{return (int)self::boxCostFor($this->costPerSquareMetreMinor,$feeMinor,$sides);}

    /** The fabricated box's weight: the declared tare plus its board, rounded up. @param array{0:int,1:int,2:int} $sides */
    public function boxTareTicks(int $declaredTare,array $sides):int
    {
        return (int)BigInt::add((string)$declaredTare,self::perSquareMetre($this->tarePerSquareMetre,self::surfaceArea($sides)));
    }

    /** The smallest non-negative c with c^3 >= value: doubling, then bisection, O(log value) steps. */
    public static function icbrtCeil(string $value):string
    {
        $cube=static function (string $x): string {
            return BigInt::multiply(BigInt::multiply($x,$x),$x);
        };
        $high='1';
        while(BigInt::compare($cube($high),$value)<0)$high=BigInt::multiply($high,'2');
        $low='0';
        while(BigInt::compare($low,$high)<0){
            $middle=BigInt::divide(BigInt::add($low,$high),'2');
            if(BigInt::compare($cube($middle),$value)>=0)$high=$middle;else $low=BigInt::add($middle,'1');
        }
        return $low;
    }

    /**
     * The rungs one trial tries, ascending on every axis, the machine maximum last.
     *
     * `$edges` are each remaining instance's envelope edges (nominal plus twice the clearance).
     * O(r) over the r instances plus the cube root; the list depends only on their multiset.
     *
     * @param array{0:int,1:int,2:int} $maximum @param list<array{0:int,1:int,2:int}> $edges
     * @return list<array{0:int,1:int,2:int}>
     */
    public function solvingEnvelopes(array $maximum,array $edges):array
    {
        $volume='0';$floors=[0,0,0];
        foreach($edges as $instance){
            $volume=BigInt::add($volume,BigInt::multiply(BigInt::multiply($instance[0],$instance[1]),$instance[2]));
            $ranked=$instance;rsort($ranked);
            for($rank=0;$rank<3;$rank++)$floors[$rank]=max($floors[$rank],$ranked[$rank]);
        }
        $side=self::icbrtCeil($volume);
        $rungs=[];
        foreach(self::RUNG_FACTORS as [$numerator,$denominator]){
            $scaled=(int)BigInt::divide(BigInt::add(BigInt::multiply($side,$numerator),(string)($denominator-1)),(string)$denominator);
            $rung=[];
            for($axis=0;$axis<3;$axis++)$rung[]=$this->clamp($axis,max($scaled,$floors[$axis]),$maximum[$axis]);
            if(BigInt::compare(BigInt::multiply(BigInt::multiply($rung[0],$rung[1]),$rung[2]),$volume)<0||in_array($rung,$rungs,true))continue;
            $rungs[]=$rung;
        }
        if(!in_array($maximum,$rungs,true))$rungs[]=$maximum;
        return $rungs;
    }

    /**
     * Each instance's envelope edges, the input `solvingEnvelopes` sizes rungs from.
     *
     * @param list<ItemInstance> $items @return list<array{0:int,1:int,2:int}>
     */
    public static function instanceEdges(array $items,int $clearanceTicks):array
    {
        $grow=2*$clearanceTicks;
        return array_map(static function(ItemInstance $item)use($grow):array{$d=$item->dimensions();return [$d->length->ticks+$grow,$d->width->ticks+$grow,$d->height->ticks+$grow];},$items);
    }
}
