<?php
declare(strict_types=1);
namespace Packvium\Extension;
use Packvium\Algorithm\ContainerState;
use Packvium\Domain\{Dimensions,Point};
/**
 * Ranks feasible placements. Lower sorts first.
 *
 * Receives the origin and envelope rather than a Candidate so the finder does not have
 * to build a throwaway object for every point/rotation pair it scores.
 */
interface CandidateScorer{/** @return list<int> */public function score(ContainerState $state,Point $point,Dimensions $envelope):array;}
