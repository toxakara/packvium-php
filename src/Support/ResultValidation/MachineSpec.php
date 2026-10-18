<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

/** A made-to-measure container's machine, in ticks, minor units and weight ticks. */
final class MachineSpec
{
    /** @var array{0:int|string,1:int|string,2:int|string} */
    public $minimum;
    /** @var array{0:int|string,1:int|string,2:int|string} */
    public $increment;
    /** @var int */
    public $pricePerSquareMetreMinor;
    /** @var int|string */
    public $tarePerSquareMetre;
    /** @var array{0:int|string,1:int|string,2:int|string} */
    public $allowance;
}
