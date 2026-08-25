<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Domain\ItemInstance;
final class SingleContainerSolution
{
    /**
     * @readonly
     * @var \Packvium\Algorithm\ContainerState
     */
    public $state;
    /**
     * @var list<ItemInstance>
     * @readonly
     */
    public $unpacked;
    /**
     * @var bool
     * @readonly
     */
    public $exhaustive = false;
    /**
     * @readonly
     * @var bool
     */
    public $timeLimitReached = false;
    /**
     * @var bool
     * @readonly
     */
    public $dominantLattice = false;
    /**
     * @param list<ItemInstance> $unpacked
     * @param bool $exhaustive True only when the search space was explored to the end.
     * @param bool $dominantLattice True only when `GridSolver` actually built this container
     *   via its regular-lattice arithmetic. `GridSolver::packOne` silently delegates to
     *   `ExtremePointSolver` when a specific container disqualifies the lattice (obstacles,
     *   tags, axles, stack density, ground-contact rules, mixed item types when the caller
     *   names "grid" explicitly via `config->solvers`) -- the delegated result is an
     *   ordinary beam-search answer, not the provably-dominant one 's portfolio
     *   short-circuit assumes.
     */
    public function __construct(ContainerState $state, array $unpacked, bool $exhaustive=false, bool $timeLimitReached=false, bool $dominantLattice=false)
    {
        $this->state = $state;
        $this->unpacked = $unpacked;
        $this->exhaustive = $exhaustive;
        $this->timeLimitReached = $timeLimitReached;
        $this->dominantLattice = $dominantLattice;
    }
}
