<?php
declare(strict_types=1);
namespace Packvium\Config;
use InvalidArgumentException;
use Packvium\Algorithm\EffortBudget;
use Packvium\Unit\Length;
final readonly class PackingConfig
{
    public Length $clearance;
    /** @param list<string> $solvers */
    public function __construct(public SolverProfile $profile=SolverProfile::Balanced,public int $timeLimitMs=1000,public int $topK=3,public int $seed=42,public ?int $maxContainers=null,?Length $clearance=null,public float $minimumSupportRatio=0.0,public int $exactItemLimit=7,public int $multiStartOrders=8,public bool $validateResult=true,public int $maxCandidatesPerItem=1,public int $maxCandidatePoints=4096,public array $solvers=[],public string $objective='default',public ?EffortBudget $effortBudget=null,public ?int $dimensionalWeightDivisor=null,public string $dimensionalWeightLengthUnit='in',public string $dimensionalWeightWeightUnit='lb',public bool $requirePlacementCoordinates=true,public int $parallelStarts=1,public int $containerPlanBeamWidth=1,public int $containerPlanNodeLimit=1,public array $accessDirections=[]){if($timeLimitMs<=0||$topK<=0||$exactItemLimit<=0||$multiStartOrders<=0||$maxCandidatesPerItem<=0||$parallelStarts<=0||$containerPlanBeamWidth<=0||$containerPlanNodeLimit<=0)throw new InvalidArgumentException('Positive configuration values required');if($maxCandidatePoints<16)throw new InvalidArgumentException('maxCandidatePoints must be at least 16');if($minimumSupportRatio<0||$minimumSupportRatio>1)throw new InvalidArgumentException('Minimum support ratio must be between 0 and 1');if($dimensionalWeightDivisor!==null&&$dimensionalWeightDivisor<=0)throw new InvalidArgumentException('dimensionalWeightDivisor must be positive');$this->clearance=$clearance??new Length(0);}
    public static function fast(int $timeLimitMs=200,?Length $clearance=null,bool $requirePlacementCoordinates=true,bool $validateResult=true):self{return new self(SolverProfile::Fast,$timeLimitMs,1,42,null,$clearance,0.0,7,1,$validateResult,1,requirePlacementCoordinates:$requirePlacementCoordinates);}
    public static function balanced(int $timeLimitMs=1000,int $topK=3,?Length $clearance=null):self{return new self(SolverProfile::Balanced,$timeLimitMs,$topK,42,null,$clearance);}
    public static function quality(int $timeLimitMs=5000,int $topK=5,?Length $clearance=null):self{return new self(SolverProfile::Quality,$timeLimitMs,$topK,42,null,$clearance,0.0,7,24,true,16,containerPlanBeamWidth:16,containerPlanNodeLimit:100_000);}
    public static function exactSmall(int $timeLimitMs=10000):self{return new self(SolverProfile::ExactSmall,$timeLimitMs);}
}
