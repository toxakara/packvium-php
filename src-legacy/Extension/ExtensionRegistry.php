<?php
declare(strict_types=1);
namespace Packvium\Extension;
use Packvium\Algorithm\SingleContainerSolver;
use Packvium\Constraint\PlacementConstraint;
final class ExtensionRegistry
{/**
 * @var list<PlacementConstraint>
 * @readonly
 */
public $placementConstraints = [];
/**
 * @readonly
 * @var mixed[]
 */
public $itemOrderStrategies = [];
/**
 * @readonly
 * @var mixed[]
 */
public $solvers = [];
/**
 * @readonly
 * @var \Packvium\Extension\CandidateScorer|null
 */
public $candidateScorer;
/**
 * @readonly
 * @var \Packvium\Extension\ContainerSelector|null
 */
public $containerSelector;
/** @param list<PlacementConstraint> $placementConstraints @param list<ItemOrderStrategy> $itemOrderStrategies @param list<SingleContainerSolver> $solvers */public function __construct(array $placementConstraints=[], array $itemOrderStrategies=[], array $solvers=[], ?CandidateScorer $candidateScorer=null, ?ContainerSelector $containerSelector=null)
{
    $this->placementConstraints = $placementConstraints;
    $this->itemOrderStrategies = $itemOrderStrategies;
    $this->solvers = $solvers;
    $this->candidateScorer = $candidateScorer;
    $this->containerSelector = $containerSelector;
}}
