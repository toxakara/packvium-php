<?php
declare(strict_types=1);

namespace Packvium\Result;

use Packvium\Constraint\{AxleLoad, LoadCalculator, VolumeReserve};
use Packvium\Domain\{PackedContainer, UnpackedItem};

final readonly class PackingResult
{
    public ResultFact $feasibility;
    public ResultFact $termination;
    public ResultFact $optimality;

    /**
     * @param list<PackedContainer> $containers
     * @param list<UnpackedItem> $unpacked
     * @param list<int|float|string> $score
     * @param list<string> $warnings
     * @param list<self> $alternatives
     */
    public function __construct(
        public PackingStatus $status,
        public array $containers,
        public array $unpacked,
        public AlgorithmReport $algorithm,
        public array $score,
        public array $warnings = [],
        public array $alternatives = [],
        ?ResultFact $feasibility = null,
        ?ResultFact $termination = null,
        ?ResultFact $optimality = null,
        public string $objective = 'default',
    ) {
        [$derivedFeasibility, $derivedTermination, $derivedOptimality] = self::deriveFacts(
            $status,
            $unpacked === [],
            $algorithm->timeLimitReached,
        );
        $this->feasibility = $feasibility ?? $derivedFeasibility;
        $this->termination = $termination ?? TerminationSummary::aggregate([
            new StartRecord(
                $algorithm->solver !== '' ? $algorithm->solver : 'unknown',
                true,
                !$algorithm->timeLimitReached,
                $algorithm->timeLimitReached,
                true,
                $algorithm->timeLimitReached,
            ),
        ], $status === PackingStatus::InvalidResult);
        $this->optimality = $optimality ?? $derivedOptimality;
    }

    public function complete(): bool
    {
        return $this->unpacked === [];
    }

    public function packedItemCount(): int
    {
        return array_sum(array_map(fn($container) => $container->placementCount(), $this->containers));
    }

    /** @return array{0:ResultFact,1:ResultFact,2:ResultFact} */
    private static function deriveFacts(PackingStatus $status, bool $complete, bool $timedOut): array
    {
        $feasibility = $status === PackingStatus::Infeasible
            ? 'infeasible'
            : ($complete && $status !== PackingStatus::InvalidResult ? 'feasible' : 'unknown');
        $termination = $status === PackingStatus::InvalidResult
            ? 'error'
            : ($timedOut ? 'time_limit' : 'complete');
        $optimality = match (true) {
            $status === PackingStatus::Optimal => 'proven_optimal',
            $status === PackingStatus::Infeasible => 'proven_infeasible',
            !$complete && $status !== PackingStatus::InvalidResult => 'best_found',
            default => 'not_proven',
        };
        return [
            new ResultFact($feasibility),
            new ResultFact($termination),
            new ResultFact($optimality),
        ];
    }

    public function toArray(
        string $lengthUnit = 'mm',
        string $weightUnit = 'g',
        bool $includeAlternatives = true,
    ): array {
        $containers = [];
        foreach ($this->containers as $container) {
            $placements = [];
            foreach ($container->placements as $placement) {
                $placements[] = [
                    'item_id' => $placement->instance->id(),
                    'item_type' => $placement->instance->item->id,
                    'position' => $placement->position->toArray($lengthUnit),
                    'dimensions' => $placement->dimensions->toArray($lengthUnit),
                    'orientation' => $placement->rotation->value,
                    'support_ratio' => sprintf('%.6f', $placement->supportRatio),
                    'top_load' => $placement->topLoad->toArray($weightUnit),
                ];
            }
            $serialized = [
                'id' => $container->id(),
                'container_type' => $container->container->id,
                'inner_dimensions' => $container->container->innerDimensions->toArray($lengthUnit),
                'outer_dimensions' => ($container->container->outerDimensions
                    ?? $container->container->innerDimensions)->toArray($lengthUnit),
                'payload_weight' => $container->payloadWeight()->toArray($weightUnit),
                'gross_weight' => $container->grossWeight()->toArray($weightUnit),
                'used_volume_ticks3' => $container->usedVolumeString(),
                'volume_utilization' => sprintf('%.6f', $container->utilization()),
                'centre_of_mass_offset_ppm' => $container->centreOfMassOffsetPpm(),
                'void_fill_reserve_ticks3' => VolumeReserve::reserved($container->container),
                'placements' => $placements,
            ];
            if ($container->latticeSummary !== null) {
                // Compact form: when `configuration.require_placement_coordinates`
                // was false and GridSolver's lattice fast path applied, `placements` stays
                // empty and an added `lattice_summary` key carries the same information in
                // O(1)/O(r) instead of one entry per instance. Omitted entirely (not even a
                // `null` key) otherwise, so a default request's output is byte-for-byte
                // unchanged -- a strict, opt-in addition, not a shape change.
                $summary = $container->latticeSummary;
                $serialized['lattice_summary'] = [
                    'item_type' => $summary->itemType,
                    'orientation' => $summary->rotation->value,
                    'physical_dimensions' => $summary->physical->toArray($lengthUnit),
                    'envelope_dimensions' => $summary->envelope->toArray($lengthUnit),
                    'nx' => $summary->nx,
                    'ny' => $summary->ny,
                    'layers_used' => $summary->layersUsed(),
                    'layer_step' => (new \Packvium\Unit\Length($summary->layerStep))->toArray($lengthUnit),
                    'count' => $summary->count,
                ];
            }
            if ($container->container->axles !== null) {
                $reaction = AxleLoad::reactions(
                    $container->container->axles,
                    LoadCalculator::units($container->placements),
                    $container->container->tareWeight->ticks,
                    $container->container->innerDimensions->length->ticks,
                );
                $serialized['axle_reactions'] = [
                    'basis' => 'gross',
                    'denominator' => $reaction['denominator'],
                    'front_numerator' => $reaction['front_numerator'],
                    'rear_numerator' => $reaction['rear_numerator'],
                ];
            }
            $containers[] = $serialized;
        }

        $unpacked = [];
        foreach ($this->unpacked as $item) {
            $unpacked[] = [
                'item_id' => $item->instance->id(),
                'item_type' => $item->instance->item->id,
                'reason' => $item->reason,
                'details' => $item->details,
                'proof' => $item->proof->toArray(),
            ];
        }

        return [
            'status' => $this->status->value,
            'feasibility' => $this->feasibility->toArray(),
            'termination' => $this->termination->toArray(),
            'optimality' => $this->optimality->toArray(),
            'complete' => $this->complete(),
            'objective' => $this->objective,
            'algorithm' => [
                'profile' => $this->algorithm->profile,
                'solver' => $this->algorithm->solver,
                'duration_ms' => $this->algorithm->durationMs,
                'seed' => $this->algorithm->seed,
                'time_limit_reached' => $this->algorithm->timeLimitReached,
                'effort_limit_reached' => $this->algorithm->effortLimitReached,
                'candidates_evaluated' => $this->algorithm->candidatesEvaluated,
                'placements_attempted' => $this->algorithm->placementsAttempted,
                'metrics' => $this->algorithm->metrics->toArray(),
            ],
            'summary' => [
                'container_count' => count($this->containers),
                'packed_item_count' => $this->packedItemCount(),
                'unpacked_item_count' => count($this->unpacked),
            ],
            'score' => $this->score,
            'containers' => $containers,
            'unpacked_items' => $unpacked,
            'catalog_versions_used' => [],
            'warnings' => $this->warnings,
            'alternatives' => $includeAlternatives
                ? array_map(fn(self $alternative) => $alternative->toArray($lengthUnit, $weightUnit, false), $this->alternatives)
                : [],
        ];
    }
}
