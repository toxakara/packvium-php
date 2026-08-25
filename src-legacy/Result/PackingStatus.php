<?php
declare(strict_types=1);
namespace Packvium\Result;
use InvalidArgumentException;
final class PackingStatus
{
    public const Optimal='optimal', Feasible='feasible', BestFound='best_found', TimeLimit='time_limit', Infeasible='infeasible', InvalidResult='invalid_result';
    public static function cases():array{return [self::Optimal,self::Feasible,self::BestFound,self::TimeLimit,self::Infeasible,self::InvalidResult];}
    public static function from(string $value):string{if(!in_array($value,self::cases(),true))throw new InvalidArgumentException('Unknown packing status '.$value);return $value;}
}
