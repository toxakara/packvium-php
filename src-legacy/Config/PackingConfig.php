<?php
declare(strict_types=1);
namespace Packvium\Config;
use InvalidArgumentException;
use Packvium\Algorithm\EffortBudget;
use Packvium\Unit\Length;
final class PackingConfig
{
    /**
     * @readonly
     * @var string
     */
    public $profile = SolverProfile::Balanced;
    /**
     * @readonly
     * @var int
     */
    public $timeLimitMs = 1000;
    /**
     * @readonly
     * @var int
     */
    public $topK = 3;
    /**
     * @readonly
     * @var int
     */
    public $seed = 42;
    /**
     * @readonly
     * @var int|null
     */
    public $maxContainers;
    /**
     * @readonly
     * @var float
     */
    public $minimumSupportRatio = 0.0;
    /**
     * @readonly
     * @var int
     */
    public $exactItemLimit = 7;
    /**
     * @readonly
     * @var int
     */
    public $multiStartOrders = 8;
    /**
     * @readonly
     * @var bool
     */
    public $validateResult = true;
    /**
     * @readonly
     * @var int
     */
    public $maxCandidatesPerItem = 1;
    /**
     * @readonly
     * @var int
     */
    public $maxCandidatePoints = 4096;
    /**
     * @var list<string>
     * @readonly
     */
    public $solvers = [];
    /**
     * @readonly
     * @var string
     */
    public $objective = 'default';
    /**
     * @readonly
     * @var \Packvium\Algorithm\EffortBudget|null
     */
    public $effortBudget;
    /**
     * @readonly
     * @var int|null
     */
    public $dimensionalWeightDivisor;
    /**
     * @readonly
     * @var string
     */
    public $dimensionalWeightLengthUnit = 'in';
    /**
     * @readonly
     * @var string
     */
    public $dimensionalWeightWeightUnit = 'lb';
    /**
     * @readonly
     * @var bool
     */
    public $requirePlacementCoordinates = true;
    /**
     * @readonly
     * @var int
     */
    public $parallelStarts = 1;
    /**
     * @readonly
     * @var int
     */
    public $containerPlanBeamWidth = 1;
    /**
     * @readonly
     * @var int
     */
    public $containerPlanNodeLimit = 1;
    /**
     * @readonly
     * @var mixed[]
     */
    public $accessDirections = [];
    /**
     * @readonly
     * @var \Packvium\Unit\Length
     */
    public $clearance;
    /** @param list<string> $solvers */
    public function __construct(string $profile=SolverProfile::Balanced,int $timeLimitMs=1000,int $topK=3,int $seed=42,?int $maxContainers=null,?Length $clearance=null,float $minimumSupportRatio=0.0,int $exactItemLimit=7,int $multiStartOrders=8,bool $validateResult=true,int $maxCandidatesPerItem=1,int $maxCandidatePoints=4096,array $solvers=[],string $objective='default',?EffortBudget $effortBudget=null,?int $dimensionalWeightDivisor=null,string $dimensionalWeightLengthUnit='in',string $dimensionalWeightWeightUnit='lb',bool $requirePlacementCoordinates=true,int $parallelStarts=1,int $containerPlanBeamWidth=1,int $containerPlanNodeLimit=1,array $accessDirections=[]){$this->profile = $profile;
    $this->timeLimitMs = $timeLimitMs;
    $this->topK = $topK;
    $this->seed = $seed;
    $this->maxContainers = $maxContainers;
    $this->minimumSupportRatio = $minimumSupportRatio;
    $this->exactItemLimit = $exactItemLimit;
    $this->multiStartOrders = $multiStartOrders;
    $this->validateResult = $validateResult;
    $this->maxCandidatesPerItem = $maxCandidatesPerItem;
    $this->maxCandidatePoints = $maxCandidatePoints;
    $this->solvers = $solvers;
    $this->objective = $objective;
    $this->effortBudget = $effortBudget;
    $this->dimensionalWeightDivisor = $dimensionalWeightDivisor;
    $this->dimensionalWeightLengthUnit = $dimensionalWeightLengthUnit;
    $this->dimensionalWeightWeightUnit = $dimensionalWeightWeightUnit;
    $this->requirePlacementCoordinates = $requirePlacementCoordinates;
    $this->parallelStarts = $parallelStarts;
    $this->containerPlanBeamWidth = $containerPlanBeamWidth;
    $this->containerPlanNodeLimit = $containerPlanNodeLimit;
    $this->accessDirections = $accessDirections;
    if($timeLimitMs<=0||$topK<=0||$exactItemLimit<=0||$multiStartOrders<=0||$maxCandidatesPerItem<=0||$parallelStarts<=0||$containerPlanBeamWidth<=0||$containerPlanNodeLimit<=0)throw new InvalidArgumentException('Positive configuration values required');if($maxCandidatePoints<16)throw new InvalidArgumentException('maxCandidatePoints must be at least 16');if($minimumSupportRatio<0||$minimumSupportRatio>1)throw new InvalidArgumentException('Minimum support ratio must be between 0 and 1');if($dimensionalWeightDivisor!==null&&$dimensionalWeightDivisor<=0)throw new InvalidArgumentException('dimensionalWeightDivisor must be positive');$this->clearance=$clearance??new Length(0);}
    public static function fast(int $timeLimitMs=200,?Length $clearance=null,bool $requirePlacementCoordinates=true,bool $validateResult=true):self{return new self(SolverProfile::Fast, $timeLimitMs, 1, 42, null, $clearance, 0.0, 7, 1, $validateResult, 1, 4096, [], 'default', null, null, 'in', 'lb', $requirePlacementCoordinates);}
    public static function balanced(int $timeLimitMs=1000,int $topK=3,?Length $clearance=null):self{return new self(SolverProfile::Balanced,$timeLimitMs,$topK,42,null,$clearance);}
    public static function quality(int $timeLimitMs=5000,int $topK=5,?Length $clearance=null):self{return new self(SolverProfile::Quality, $timeLimitMs, $topK, 42, null, $clearance, 0.0, 7, 24, true, 16, 4096, [], 'default', null, null, 'in', 'lb', true, 1, 16, 100000);}
    public static function exactSmall(int $timeLimitMs=10000):self{return new self(SolverProfile::ExactSmall,$timeLimitMs);}
}
