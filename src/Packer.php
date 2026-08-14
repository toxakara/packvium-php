<?php
declare(strict_types=1);

namespace Packvium;

use Packvium\Algorithm\{Deadline, SolverOrchestrator};
use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container, Item, PackingRequest};
use Packvium\Extension\ExtensionRegistry;
use Packvium\Objective\{ObjectiveRegistry, SolutionScorer};
use Packvium\Result\{
    AlgorithmReport,
    PackingResult,
    PackingStatus,
    TerminationSummary
};
use Packvium\Validation\IndependentSolutionValidator;

final class Packer
{
    public function __construct(
        private readonly PackingConfig $config = new PackingConfig(),
        private readonly ExtensionRegistry $extensions = new ExtensionRegistry(),
        private readonly ?SolutionScorer $solutionScorer = null,
        private readonly ?\Closure $clock = null,
    ) {}

    /** @param list<Item> $items @param list<Container> $containers */
    public function pack(array $items, array $containers): PackingResult
    {
        $request = new PackingRequest($items, $containers);
        $deadline = Deadline::ofMilliseconds($this->config->timeLimitMs, $this->clock);
        $orchestrator = new SolverOrchestrator(
            $this->extensions->placementConstraints,
            $this->extensions->itemOrderStrategies,
            $this->extensions->solvers,
            $this->extensions->candidateScorer,
            $this->extensions->containerSelector,
        );
        $portfolio = $orchestrator->solvePortfolio(
            $request->instances(),
            $request->containers,
            $this->config,
            $deadline,
        );
        $ranked = [];
        $validator = new IndependentSolutionValidator();
        $scorer = $this->solutionScorer ?? ObjectiveRegistry::resolve($this->config->objective, $this->config);

        foreach ($portfolio->solutions as $raw) {
            $score = $scorer->score($raw)->components;
            $status = ($raw->unpacked !== [] && $raw->timeLimitReached)
                ? PackingStatus::TimeLimit
                : ($raw->unpacked !== []
                    ? PackingStatus::BestFound
                    : ($raw->exhaustive ? PackingStatus::Optimal : PackingStatus::Feasible));
            $warnings = [];
            if ($this->config->validateResult) {
                // Independent validation re-derives every guarantee from per-item
                // placements. A container built by GridSolver's compact fast path
                // carries a latticeSummary instead; expand it into the
                // identical placements the O(n) path would have built just for this
                // check -- $raw->containers itself, and therefore the returned
                // result, stays compact.
                $validationContainers = array_map(
                    static fn($c) => $c->latticeSummary !== null
                        ? new \Packvium\Domain\PackedContainer($c->container, $c->sequence, $c->expandPlacements())
                        : $c,
                    $raw->containers,
                );
                $report = $validator->validate(
                    $request,
                    $validationContainers,
                    $this->config->minimumSupportRatio,
                    $this->config->clearance,
                    $raw->unpacked,
                );
                if (!$report->valid) {
                    $status = PackingStatus::InvalidResult;
                    foreach ($report->issues as $issue) {
                        $warnings[] = $issue->code . ': ' . $issue->detail;
                    }
                }
            }
            $starts = [];
            $winnerMarked = false;
            foreach ($portfolio->starts as $record) {
                $selectedRecord = !$winnerMarked && $record->id === $raw->solverName;
                $winnerMarked = $winnerMarked || $selectedRecord;
                $starts[] = $record->withSelected($selectedRecord);
            }
            $termination = TerminationSummary::aggregate(
                $starts,
                $status === PackingStatus::InvalidResult,
            );
            if($raw->effortLimitReached&&!$raw->timeLimitReached){
                $termination=new \Packvium\Result\ResultFact('effort_limit',$termination->attributes);
            }
            $aggregateTimedOut = $termination->attributes['global_deadline_reached']
                || $raw->timeLimitReached;
            $ranked[] = new PackingResult(
                $status,
                $raw->containers,
                $raw->unpacked,
                new AlgorithmReport(
                    $this->config->profile->value,
                    $raw->solverName,
                    $deadline->elapsedMs(),
                    $this->config->seed,
                    $aggregateTimedOut,
                    $raw->effortLimitReached,
                    $raw->stats->candidatesEvaluated,
                    $raw->stats->placementsAttempted,
                    $raw->stats->metrics(),
                ),
                $score,
                $warnings,
                termination: $termination,
                objective: $this->config->objective,
            );
        }

        usort(
            $ranked,
            static fn($left, $right): int => [
                $left->status === PackingStatus::InvalidResult ? 1 : 0,
                $left->score,
                $left->algorithm->solver,
            ] <=> [
                $right->status === PackingStatus::InvalidResult ? 1 : 0,
                $right->score,
                $right->algorithm->solver,
            ],
        );
        $valid = array_values(array_filter(
            $ranked,
            static fn($result): bool => $result->status !== PackingStatus::InvalidResult,
        ));
        $selected = $valid !== [] ? $valid : $ranked;
        $best = $selected[0];
        return new PackingResult(
            $best->status,
            $best->containers,
            $best->unpacked,
            $best->algorithm,
            $best->score,
            $best->warnings,
            array_slice($selected, 1, max(0, $this->config->topK - 1)),
            $best->feasibility,
            $best->termination,
            $best->optimality,
            $best->objective,
        );
    }
}
