<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Config\PackingConfig;
use Packvium\Constraint\{ConstraintSet,PlacementConstraint};
use Packvium\Domain\{Container,ItemInstance};
use Packvium\Extension\{CandidateScorer,DefaultCandidateScorer};
use Packvium\Support\StableSorter;
final class LayerSolver implements SingleContainerSolver
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
    public function name():string{return 'layer';}
    // Every layer start re-sorts by a total key ending in item id, discarding whatever
    // order it is handed, so repeating it per ordering only burns portfolio budget.
    public function orderInsensitive():bool{return true;}
    public function packOne(Container $container,int $sequence,array $items,PackingConfig $config,SearchStats $stats,Deadline $deadline):SingleContainerSolution
    {
        $ordered=StableSorter::sortBy($items,static function (ItemInstance $i) use ($config): array {
            return array_merge(ItemOrdering::lead($i,$config), [-$i->dimensions()->baseAreaTicks()], $i->dimensions()->descendingVolumeKey(), [-$i->weight()->ticks, $i->id()]);
        });
        return BeamPacker::pack($container,$sequence,$ordered,$config,ConstraintSet::defaults($config->minimumSupportRatio,$this->constraints,$config->accessDirections),$stats,$deadline,$this->scorer??new DefaultCandidateScorer());
    }
}
