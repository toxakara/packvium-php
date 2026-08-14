<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Config\PackingConfig;
use Packvium\Constraint\{ConstraintSet,PlacementConstraint};
use Packvium\Domain\Container;
use Packvium\Extension\{CandidateScorer,DefaultCandidateScorer};
final class ExtremePointSolver implements SingleContainerSolver
{
    /** @param list<PlacementConstraint> $constraints */
    public function __construct(private array $constraints=[],private ?CandidateScorer $scorer=null){}
    public function name():string{return 'extreme_points';}
    public function packOne(Container $container,int $sequence,array $items,PackingConfig $config,SearchStats $stats,Deadline $deadline):SingleContainerSolution
    {
        return BeamPacker::pack($container,$sequence,$items,$config,ConstraintSet::defaults($config->minimumSupportRatio,$this->constraints),$stats,$deadline,$this->scorer??new DefaultCandidateScorer());
    }
}
