<?php
declare(strict_types=1);

namespace Packvium\Result;

use Packvium\Constraint\{AxleLoad, LoadCalculator, VolumeReserve};
use Packvium\Domain\{Dimensions, PackedContainer, Point, UnpackedItem};
use Packvium\Unit\Length;

final class PackingResult
{
    /**
     * @readonly
     * @var string
     */
    public $status;
    /**
     * @var list<PackedContainer>
     * @readonly
     */
    public $containers;
    /**
     * @var list<UnpackedItem>
     * @readonly
     */
    public $unpacked;
    /**
     * @readonly
     * @var \Packvium\Result\AlgorithmReport
     */
    public $algorithm;
    /**
     * @var list<int|float|string>
     * @readonly
     */
    public $score;
    /**
     * @var list<string>
     * @readonly
     */
    public $warnings = [];
    /**
     * @var list<self>
     * @readonly
     */
    public $alternatives = [];
    /**
     * @readonly
     * @var string
     */
    public $objective = 'default';
    /**
     * @readonly
     * @var string|null
     */
    public $currency;
    /** The serialization cache before any value is shared through it. */
    private const NOTHING_SHARED = ['length' => [], 'weight' => [], 'dimensions' => []];

    /**
     * @readonly
     * @var \Packvium\Result\ResultFact
     */
    public $feasibility;
    /**
     * @readonly
     * @var \Packvium\Result\ResultFact
     */
    public $termination;
    /**
     * @readonly
     * @var \Packvium\Result\ResultFact
     */
    public $optimality;

    /**
     * @param list<PackedContainer> $containers
     * @param list<UnpackedItem> $unpacked
     * @param list<int|float|string> $score
     * @param list<string> $warnings
     * @param list<self> $alternatives
     */
    public function __construct(
        string $status,
        array $containers,
        array $unpacked,
        AlgorithmReport $algorithm,
        array $score,
        array $warnings = [],
        array $alternatives = [],
        ?ResultFact $feasibility = null,
        ?ResultFact $termination = null,
        ?ResultFact $optimality = null,
        string $objective = 'default',
        ?string $currency = null
    ) {
        $this->status = $status;
        $this->containers = $containers;
        $this->unpacked = $unpacked;
        $this->algorithm = $algorithm;
        $this->score = $score;
        $this->warnings = $warnings;
        $this->alternatives = $alternatives;
        $this->objective = $objective;
        // The request's ISO 4217 code, echoed only when the request declares one, by the
        // answer and by every alternative alike (1.10).
        $this->currency = $currency;
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
        return array_sum(array_map(function ($container) {
            return $container->placementCount();
        }, $this->containers));
    }

    /** @return array{0:ResultFact,1:ResultFact,2:ResultFact} */
    private static function deriveFacts(string $status, bool $complete, bool $timedOut): array
    {
        $feasibility = $status === PackingStatus::Infeasible
            ? 'infeasible'
            : ($complete && $status !== PackingStatus::InvalidResult ? 'feasible' : 'unknown');
        $termination = $status === PackingStatus::InvalidResult
            ? 'error'
            : ($timedOut ? 'time_limit' : 'complete');
        switch (true) {
            case $status === PackingStatus::Optimal:
                $optimality = 'proven_optimal';
                break;
            case $status === PackingStatus::Infeasible:
                $optimality = 'proven_infeasible';
                break;
            case !$complete && $status !== PackingStatus::InvalidResult:
                $optimality = 'best_found';
                break;
            default:
                $optimality = 'not_proven';
                break;
        }
        return [
            new ResultFact($feasibility),
            new ResultFact($termination),
            new ResultFact($optimality),
        ];
    }

    public function toArray(
        string $lengthUnit = 'mm',
        string $weightUnit = 'g',
        bool $includeAlternatives = true
    ): array {
        // Equal quantities share one array: placements repeat a type's dimensions, a lattice
        // coordinate and a zero top load, and a shared array serializes to the same bytes.
        $shared = self::NOTHING_SHARED;
        return $this->serialized($lengthUnit, $weightUnit, $includeAlternatives, false, $shared);
    }

    /**
     * `toArray`, except that each container's `placements` (the answer's and every
     * alternative's) is a one-pass `\Generator` of the same placement arrays, for
     * `PrettyJsonStream` to write as it goes.
     *
     * The placements are most of a result's array, and holding all of them next to the result
     * objects they come from is the peak of a `bin/packvium` run on `fast`; streamed, no more
     * than one placement's array is alive at a time. Each generator holds its own container.
     */
    public function toStreamedArray(string $lengthUnit = 'mm', string $weightUnit = 'g'): array
    {
        $shared = self::NOTHING_SHARED;
        return $this->serialized($lengthUnit, $weightUnit, true, true, $shared);
    }

    /** @param array{length:array<int,array>,weight:array<int,array>,dimensions:array<int,array<int,array<int,array>>>} $shared */
    private function serialized(string $lengthUnit, string $weightUnit, bool $includeAlternatives, bool $streamPlacements, array &$shared): array
    {
        $containers = [];
        foreach ($this->containers as $container) {
            $containerId = $container->id();
            $placements = self::placements($container, $containerId, $lengthUnit, $weightUnit, $streamPlacements, $shared);
            $serialized = [
                'id' => $containerId,
                'container_type' => $container->container->id,
                'inner_dimensions' => $container->container->declaredInner()->toArray($lengthUnit),
                'outer_dimensions' => ($container->container->outerDimensions
                    ?? $container->container->declaredInner())->toArray($lengthUnit),
                'payload_weight' => $container->payloadWeight()->toArray($weightUnit),
                'gross_weight' => $container->grossWeight()->toArray($weightUnit),
                'used_volume_ticks3' => $container->usedVolumeString(),
                'volume_utilization' => sprintf('%.6f', $container->utilization()),
                'centre_of_mass_offset_ppm' => $container->centreOfMassOffsetPpm(),
                'void_fill_reserve_ticks3' => VolumeReserve::reserved($container->container),
                'placements' => $streamPlacements ? $placements : \iterator_to_array($placements, false),
            ];
            // Present exactly when the container conforms to its contents or is a
            // pallet with an overhang limit, so a rigid container's bytes are unchanged.
            $achieved = $container->achievedDimensions();
            if ($achieved !== null) {
                $serialized['achieved_dimensions'] = $achieved->toArray($lengthUnit);
            }
            // A packed made-to-measure container is the fabricated box, so its own price and
            // tare are the box's; absent for every other container.
            if ($container->container->madeToMeasure !== null) {
                $serialized['made_to_measure'] = [
                    'cost_minor' => $container->container->costMinor,
                    'tare_weight' => $container->container->tareWeight->toArray($weightUnit),
                ];
            }
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
                    'orientation' => $summary->rotation,
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

        $alternatives = [];
        if ($includeAlternatives) {
            foreach ($this->alternatives as $alternative) {
                $alternatives[] = $alternative->serialized($lengthUnit, $weightUnit, false, $streamPlacements, $shared);
            }
        }

        $result = [
            'status' => $this->status,
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
        ];
        // Absent unless the request declared one, so every other request keeps its bytes.
        if ($this->currency !== null) {
            $result['currency'] = $this->currency;
        }
        $result['warnings'] = $this->warnings;
        $result['alternatives'] = $alternatives;
        return $result;
    }

    /**
     * One container's placements, serialized in order.
     *
     * Streamed, each array is written and dropped before the next is built, so a cached value
     * would only be kept alive, never shared: the cache is let go after every placement.
     *
     * @param array{length:array<int,array>,weight:array<int,array>,dimensions:array<int,array<int,array<int,array>>>} $shared
     * @return \Generator<int,array<string,mixed>>
     */
    private static function placements(PackedContainer $container, string $containerId, string $lengthUnit, string $weightUnit, bool $streamed, array &$shared): \Generator
    {
        foreach ($container->placements as $placement) {
            $serialized = [
                'placement_id' => $placement->idIn($containerId),
                'item_id' => $placement->instance->id(),
                'item_type' => $placement->instance->item->id,
                'position' => self::point($placement->position, $lengthUnit, $shared),
                'dimensions' => self::dimensions($placement->dimensions, $lengthUnit, $shared),
                'orientation' => $placement->rotation,
                'support_ratio' => sprintf('%.6f', $placement->supportRatio),
                'top_load' => $shared['weight'][$placement->topLoad->ticks] = $shared['weight'][$placement->topLoad->ticks] ?? $placement->topLoad->toArray($weightUnit),
            ];
            // Absent unless true, so a request without fixed placements keeps its bytes.
            if ($placement->fixed) {
                $serialized['fixed'] = true;
            }
            yield $serialized;
            if ($streamed) {
                $shared = self::NOTHING_SHARED;
            }
        }
    }

    /** `Point::toArray`, from shared length arrays. */
    private static function point(Point $point, string $unit, array &$shared): array
    {
        return [
            'x' => $shared['length'][$point->x] = $shared['length'][$point->x] ?? (new Length($point->x))->toArray($unit),
            'y' => $shared['length'][$point->y] = $shared['length'][$point->y] ?? (new Length($point->y))->toArray($unit),
            'z' => $shared['length'][$point->z] = $shared['length'][$point->z] ?? (new Length($point->z))->toArray($unit),
        ];
    }

    /** `Dimensions::toArray`, one shared array per distinct size. */
    private static function dimensions(Dimensions $dimensions, string $unit, array &$shared): array
    {
        return $shared['dimensions'][$dimensions->length->ticks][$dimensions->width->ticks][$dimensions->height->ticks] = $shared['dimensions'][$dimensions->length->ticks][$dimensions->width->ticks][$dimensions->height->ticks] ?? $dimensions->toArray($unit);
    }
}
