<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

/** A container type as the request declares it, read independently of the engine's `Container`. */
final class ContainerSpec
{
    /** @var mixed */
    public $id;
    /** @var array{0:int|string,1:int|string,2:int|string} */
    public $inner;
    /** @var array{0:int|string,1:int|string,2:int|string}|null */
    public $outer;
    /** @var int|string */
    public $costMinor;
    /** @var int|string|null */
    public $maxPayload;
    /** @var int|null */
    public $maxItems;
    /** @var int|null */
    public $quantity;
    /** @var list<Cuboid> */
    public $obstacles;
    /** @var array<string,true> */
    public $tags;
    /** @var list<array{0:string,1:int|string}> sorted by tag, the order the reference walks it in */
    public $tagLimits;
    /** @var Ratio */
    public $voidFillReserveRatio;
    /** @var int|string */
    public $tareWeight;
    /** @var list<array{0:int|string,1:int|string|null}>|null each axle's position and load limit */
    public $axles;
    /** @var int|string|null */
    public $maxStackDensity;
    /** @var mixed */
    public $rateTable;
    /** @var list<string> */
    public $accessDirections;
    /** @var bool */
    public $flatBag;
    /** @var bool */
    public $reportsAchievedExtent;
    /** @var MachineSpec|null */
    public $madeToMeasure;

    /** @return int|string */
    public function volume()
    {
        return Exact::mul(Exact::mul($this->inner[0], $this->inner[1]), $this->inner[2]);
    }
}
