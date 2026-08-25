<?php
declare(strict_types=1);
namespace Packvium\Objective;
use Packvium\Algorithm\RawSolution;
interface SolutionScorer{public function score(RawSolution $solution):ObjectiveScore;}
