<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Config\PackingConfig;
use Packvium\Constraint\{ConstraintSet,PlacementConstraint};
use Packvium\Domain\Container;
use Packvium\Extension\{CandidateScorer,DefaultCandidateScorer};
final class ExtremePointSolver implements SingleContainerSolver
{
    /**
     * @var list<PlacementConstraint>
     */
    private $constraints = [];
    /**
     * @var \Packvium\Extension\CandidateScorer|null
     */
    private $scorer;
    /** @param list<PlacementConstraint> $constraints */
    public function __construct(array $constraints=[], ?CandidateScorer $scorer=null)
    {
        $this->constraints = $constraints;
        $this->scorer = $scorer;
    }
    public function name():string{return 'extreme_points';}
    public function packOne(Container $container,int $sequence,array $items,PackingConfig $config,SearchStats $stats,Deadline $deadline):SingleContainerSolution
    {
        return BeamPacker::pack($container,$sequence,$items,$config,ConstraintSet::defaults($config->minimumSupportRatio,$this->constraints,$config->accessDirections),$stats,$deadline,$this->scorer??new DefaultCandidateScorer());
    }
}
