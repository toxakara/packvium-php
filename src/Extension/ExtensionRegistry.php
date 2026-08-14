<?php
declare(strict_types=1);
namespace Packvium\Extension;
use Packvium\Algorithm\SingleContainerSolver;
use Packvium\Constraint\PlacementConstraint;
final readonly class ExtensionRegistry
{/** @param list<PlacementConstraint> $placementConstraints @param list<ItemOrderStrategy> $itemOrderStrategies @param list<SingleContainerSolver> $solvers */public function __construct(public array $placementConstraints=[],public array $itemOrderStrategies=[],public array $solvers=[],public ?CandidateScorer $candidateScorer=null,public ?ContainerSelector $containerSelector=null){}}
