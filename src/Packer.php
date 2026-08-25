<?php
declare(strict_types=1);

namespace Packvium;

use Packvium\Algorithm\{Deadline, SolverOrchestrator};
use Packvium\Support\StableSorter;
use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container, Item, PackingRequest, UnratedWeightException};
use Packvium\Extension\ExtensionRegistry;
use Packvium\Objective\{LandedCostSolutionScorer, ObjectiveRegistry, SolutionScorer, UnknownObjectiveException};
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
        // Both cost objectives price the same billed weight, so both need the divisor.
        // There is no library-chosen default: a wrong guess would silently misprice
        // every shipment. `ShippingCostSolutionScorer::fromConfig` still refuses too,
        // as defence in depth; refusing here spares the caller a full solve first.
        if (($this->config->objective === 'shipping_cost' || $this->config->objective === 'lowest_landed_cost')
            && $this->config->dimensionalWeightDivisor === null
        ) {
            throw new UnknownObjectiveException(
                'the ' . $this->config->objective . ' objective requires configuration.dimensional_weight_divisor',
            );
        }
        // Rating some containers and not others would rank a priced packing against an
        // unpriced one as though the unpriced were free. A missing tariff is a static
        // property of the request, so it is refused before any solver runs -- unlike a
        // billed weight past the last bracket, which depends on how the search filled
        // the box and loses a candidate instead. The scorer's own refusal stays
        // as defence in depth for callers scoring a solution they assembled themselves.
        if ($this->config->objective === 'lowest_landed_cost') {
            foreach ($request->containers as $container) {
                if ($container->rateTable === null) {
                    throw new UnknownObjectiveException(
                        'the lowest_landed_cost objective requires a rate_table on every container; '
                        . "'{$container->id}' has none",
                    );
                }
            }
        }
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

        $ranked = StableSorter::sortBy($ranked, static fn($result): array => [
            $result->status === PackingStatus::InvalidResult ? 1 : 0,
            $result->score,
            $result->algorithm->solver,
        ]);
        $valid = array_values(array_filter(
            $ranked,
            static fn($result): bool => $result->status !== PackingStatus::InvalidResult,
        ));
        $selected = $valid !== [] ? $valid : $ranked;
        $best = $selected[0];
        // The search ranks an unpriceable packing worst so that any priceable alternative
        // beats it; reaching here with one still winning means no alternative existed.
        // Returning it would quote a number the carrier never published, so the run is
        // refused -- the same refusal the other three engines give, in the same words. The
        // refusal is deliberately here and not in the scorer: throwing while *comparing*
        // candidates would abort runs that have a perfectly shippable answer.
        $unpriceable = LandedCostSolutionScorer::unpriceableContainer($best->containers, $this->config);
        if ($unpriceable !== null) {
            [$containerId, $grams, $bound] = $unpriceable;
            throw new UnratedWeightException(
                "container '{$containerId}' bills at {$grams} g, above its rate table's "
                . "last bracket ({$bound} g); the shipment has no published price"
            );
        }
        // The sentinel is a search device, never an answer -- and that must hold for the
        // alternatives list too: a runner-up the tariff cannot price would quote the same
        // unpublished number the winner was just guarded against. Filter the ranked
        // runner-ups first, then take the top-k slice ( review).
        $alternatives = array_slice(
            array_values(array_filter(
                array_slice($selected, 1),
                fn(PackingResult $result): bool =>
                    LandedCostSolutionScorer::unpriceableContainer($result->containers, $this->config) === null,
            )),
            0,
            max(0, $this->config->topK - 1),
        );
        return new PackingResult(
            $best->status,
            $best->containers,
            $best->unpacked,
            $best->algorithm,
            $best->score,
            $best->warnings,
            $alternatives,
            $best->feasibility,
            $best->termination,
            $best->optimality,
            $best->objective,
        );
    }
}
