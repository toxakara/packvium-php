<?php
declare(strict_types=1);
namespace Packvium\Config;
use InvalidArgumentException;
final class SolverProfile
{
    public const Fast='fast', Balanced='balanced', Quality='quality', ExactSmall='exact_small';
    public static function cases():array{return [self::Fast,self::Balanced,self::Quality,self::ExactSmall];}
    public static function from(string $value):string{if(!in_array($value,self::cases(),true))throw new InvalidArgumentException('Unknown solver profile '.$value);return $value;}
}
