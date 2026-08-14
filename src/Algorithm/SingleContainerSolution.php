<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Domain\ItemInstance;
final readonly class SingleContainerSolution
{
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
    public function __construct(public ContainerState $state,public array $unpacked,public bool $exhaustive=false,public bool $timeLimitReached=false,public bool $dominantLattice=false){}
}
