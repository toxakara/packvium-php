<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Domain\ReasonProof;
use Packvium\Serialization\ArrayCodec;
use Packvium\Unit\Length;
use Packvium\Result\{
    AlgorithmReport,
    PackingResult,
    PackingStatus,
    ResultFact,
    StartRecord,
    TerminationSummary
};
use ValueError;

/**
 * The array API — the wire contract every language binding shares.
 *
 * `ArrayCodec` is what the CLI and the conformance runner go through, so its shape is
 * a compatibility surface: a missing key or a value that stops being a string is a
 * breaking change for somebody. The expected keys here are the same ones the Python
 * suite asserts.
 */
final class SerializationTest extends TestCase
{
    /** @return array<string,string> */
    private static function mm(int $length, int $width, int $height): array
    {
        return ['length' => (string)$length, 'width' => (string)$width, 'height' => (string)$height];
    }

    /** @param array<string,mixed> $rest */
    private static function request(array $items, array $containers, array $rest = []): array
    {
        return array_merge(['units' => ['length' => 'mm'], 'items' => $items, 'containers' => $containers], $rest);
    }

    /** @param array<string,mixed> $rest */
    private static function cube(string $id, int $size = 100, array $rest = []): array
    {
        return array_merge(['id' => $id, 'dimensions' => self::mm($size, $size, $size)], $rest);
    }

    /** @param array<string,mixed> $rest */
    private static function box(string $id, int $l = 200, int $w = 200, int $h = 200, array $rest = []): array
    {
        return array_merge(['id' => $id, 'inner_dimensions' => self::mm($l, $w, $h)], $rest);
    }

    // ------------------------------------------------------------------------ shape

    public static function testTheResultIsPlainData(): void
    {
        // No custom types leak out: every caller can hand this straight to json_encode.
        $result = ArrayCodec::pack(self::request([self::cube('a', 50)], [self::box('c')]));
        self::assertEquals($result, json_decode(json_encode($result), true));
    }

    public static function testTheDocumentedTopLevelKeysAreAllPresent(): void
    {
        $result = ArrayCodec::pack(self::request([self::cube('a', 50)], [self::box('c')]));
        $keys = array_keys($result);
        sort($keys);
        self::assertSame(
            ['algorithm', 'alternatives', 'catalog_versions_used', 'complete', 'containers', 'feasibility', 'objective', 'optimality', 'score', 'status', 'summary', 'termination', 'unpacked_items', 'warnings'],
            $keys,
        );
    }

    public static function testCatalogVersionsArePinnedAndDuplicatesAreRejected(): void
    {
        $references = [
            ['catalog_id'=>'items','version'=>7,'effective_at'=>10,'resolved_at'=>20],
            ['catalog_id'=>'cartons','version'=>3,'effective_at'=>11,'resolved_at'=>20],
        ];
        $payload = self::request([self::cube('a', 50)], [self::box('c')], [
            'catalog_versions_used' => $references,
        ]);
        self::assertSame($references, ArrayCodec::pack($payload)['catalog_versions_used']);
        $payload['catalog_versions_used'][] = $references[0];
        self::assertThrows(\InvalidArgumentException::class, static fn() => ArrayCodec::pack($payload));
    }

    public static function testTheThreeResultAxesAreIndependentOfTheLegacyStatus(): void
    {
        $result=(new PackingResult(
            PackingStatus::Feasible,
            [],
            [],
            new AlgorithmReport('quality','test',1,42,true),
            [0],
        ))->toArray();
        self::assertSame('feasible',$result['status']);
        self::assertSame(['code'=>'feasible'],$result['feasibility']);
        self::assertSame('time_limit',$result['termination']['code']);
        self::assertTrue($result['termination']['winning_start_truncated']);
        self::assertSame(['code'=>'not_proven'],$result['optimality']);
    }

    public static function testAnUnknownFutureFactCodeRoundTripsVerbatim(): void
    {
        self::assertSame(
            ['code'=>'node_limit','limit'=>10_000],
            ResultFact::fromArray(['code'=>'node_limit','limit'=>10_000])->toArray(),
        );
    }

    public static function testAFinishedWinnerIsDistinctFromATruncatedLoser(): void
    {
        $fact = TerminationSummary::aggregate([
            new StartRecord('winner', true, true, false, true),
            new StartRecord('loser', true, false, true),
        ])->toArray();
        self::assertSame('complete', $fact['code']);
        self::assertTrue($fact['any_start_truncated']);
        self::assertFalse($fact['all_required_starts_completed']);
        self::assertFalse($fact['winning_start_truncated']);
        self::assertFalse($fact['global_deadline_reached']);
        self::assertCount(2, $fact['starts']);
    }

    public static function testATruncatedWinnerAffectsTheReturnedAnswer(): void
    {
        $fact = TerminationSummary::aggregate([
            new StartRecord('winner', true, false, true, true),
            new StartRecord('loser', true, true, false),
        ])->toArray();
        self::assertSame('time_limit', $fact['code']);
        self::assertTrue($fact['any_start_truncated']);
        self::assertFalse($fact['all_required_starts_completed']);
        self::assertTrue($fact['winning_start_truncated']);
        self::assertFalse($fact['global_deadline_reached']);
    }

    public static function testANormalPackReportsVerifiablePerStartTermination(): void
    {
        $result = ArrayCodec::pack(self::request([self::cube('a', 50)], [self::box('c')]));
        $termination = $result['termination'];
        $selected = array_values(array_filter(
            $termination['starts'],
            static fn(array $start): bool => $start['selected'],
        ));
        self::assertCount(1, $selected);
        self::assertSame(
            (bool)array_filter($termination['starts'], static fn(array $start): bool => $start['truncated']),
            $termination['any_start_truncated'],
        );
        self::assertSame(
            !array_filter($termination['starts'], static fn(array $start): bool => !$start['completed']),
            $termination['all_required_starts_completed'],
        );
        self::assertSame($selected[0]['truncated'], $termination['winning_start_truncated']);
        self::assertSame(
            (bool)array_filter(
                $termination['starts'],
                static fn(array $start): bool => $start['global_deadline_reached'],
            ),
            $termination['global_deadline_reached'],
        );
    }

    public static function testAPlacementReportsEverythingACallerNeedsToReproduceIt(): void
    {
        $result = ArrayCodec::pack(self::request([self::cube('a', 50)], [self::box('c')]));
        $placement = $result['containers'][0]['placements'][0];
        $keys = array_keys($placement);
        sort($keys);

        self::assertSame(['dimensions', 'item_id', 'item_type', 'orientation', 'position', 'support_ratio', 'top_load'], $keys);
        self::assertSame('a#1', $placement['item_id']);
        self::assertSame('a', $placement['item_type']);
        self::assertContains($placement['orientation'], ['LWH', 'LHW', 'WLH', 'WHL', 'HLW', 'HWL']);
    }

    public static function testAContainerReportsItsOwnTotals(): void
    {
        $result = ArrayCodec::pack(self::request(
            [self::cube('a', 50, ['quantity' => 2, 'weight' => '1 kg'])], [self::box('c')]));
        $packed = $result['containers'][0];

        self::assertSame('c#1', $packed['id']);
        self::assertSame('c', $packed['container_type']);
        self::assertSame(16_000_000_000, $packed['payload_weight']['ticks']);
        self::assertSame('1024000000000000000', $packed['used_volume_ticks3']);
    }

    public static function testAContainerReportsItsCentreOfMassOffset(): void
    {
        $result = ArrayCodec::pack(self::request(
            [self::cube('a', 50, ['quantity' => 2, 'weight' => '1 kg'])], [self::box('c')]));
        $offset = $result['containers'][0]['centre_of_mass_offset_ppm'];
        self::assertTrue(is_int($offset) && $offset >= 0 && $offset <= 1_000_000);
    }

    public static function testTheScoreIsAListOfExactIntegers(): void
    {
        // Serialized as integers, never strings and never floats — a double cannot hold
        // a container volume in cubic ticks exactly.
        $score = ArrayCodec::pack(self::request([self::cube('a', 50)], [self::box('c')]))['score'];
        self::assertCount(5, $score);
        foreach ($score as $key) {
            self::assertTrue(is_int($key));
        }
    }

    // ------------------------------------------------------------------------ units

    public static function testDimensionsMayMixNotationsWithinOneRequest(): void
    {
        $result = ArrayCodec::pack(self::request(
            [['id' => 'a', 'quantity' => 2,
              'dimensions' => ['length' => ['value' => '4', 'unit' => 'in'], 'width' => '100', 'height' => '100']]],
            [self::box('b', 210, 100, 100)],
        ));
        self::assertTrue($result['complete']);
    }

    public static function testTheDefaultLengthUnitIsMillimetres(): void
    {
        $without = ArrayCodec::pack(['items' => [self::cube('a', 50)], 'containers' => [self::box('c')]]);
        $withUnits = ArrayCodec::pack(self::request([self::cube('a', 50)], [self::box('c')]));
        self::assertSame($withUnits['containers'][0]['inner_dimensions'], $without['containers'][0]['inner_dimensions']);
    }

    public static function testTheRequestUnitAppliesToEveryMeasurement(): void
    {
        $result = ArrayCodec::pack([
            'units' => ['length' => 'in'],
            'items' => [self::cube('a', 1)],
            'containers' => [self::box('c', 10, 10, 10)],
        ]);
        self::assertSame(10 * 406_400, $result['containers'][0]['inner_dimensions']['length']['ticks']);
    }

    public static function testOutputUnitsAreChosenIndependentlyOfTheInput(): void
    {
        $result = ArrayCodec::pack(self::request(
            [self::cube('a', 50, ['weight' => '1 kg'])], [self::box('c')],
            ['output' => ['length_unit' => 'in', 'weight_unit' => 'kg']],
        ));
        self::assertSame('in', $result['containers'][0]['inner_dimensions']['length']['unit']);
        self::assertSame(['ticks' => 8_000_000_000, 'value' => '1', 'unit' => 'kg'],
            $result['containers'][0]['payload_weight']);
    }

    // ---------------------------------------------------------------- configuration

    public static function testTheSolverProfileAndSeedArePlumbedThrough(): void
    {
        $result = ArrayCodec::pack(self::request([self::cube('a', 50)], [self::box('c')],
            ['configuration' => ['solver_profile' => 'quality', 'seed' => 7]]));
        self::assertSame('quality', $result['algorithm']['profile']);
        self::assertSame(7, $result['algorithm']['seed']);
    }

    public static function testAnUnknownProfileIsRejectedRatherThanSilentlyDefaulted(): void
    {
        self::assertThrows(ValueError::class, static fn() => ArrayCodec::pack(
            self::request([self::cube('a', 50)], [self::box('c')], ['configuration' => ['solver_profile' => 'magic']])));
    }

    public static function testExplicitSolversArePlumbedThroughAndDriveTheAnswer(): void
    {
        $result = ArrayCodec::pack(self::request([self::cube('a', 40, ['quantity' => 4])], [self::box('c')],
            ['configuration' => ['solvers' => ['grid']]]));
        self::assertTrue(str_starts_with($result['algorithm']['solver'], 'grid'));
    }

    public static function testExplicitSolverOrderIsPreservedInStartRecords(): void
    {
        $result = ArrayCodec::pack(self::request(
            [self::cube('a', 40)],
            [self::box('c')],
            ['configuration' => ['solvers' => ['grid', 'layer', 'extreme_points']]],
        ));
        $names = array_map(
            static fn(array $start): string => explode(':', $start['id'])[0],
            $result['termination']['starts'],
        );
        self::assertSame(['grid', 'layer', 'extreme_points'], $names);
    }

    public static function testAnUnknownSolverNameIsRejectedRatherThanSilentlyDefaulted(): void
    {
        self::assertThrows(\Packvium\Algorithm\UnknownSolverException::class, static fn() => ArrayCodec::pack(
            self::request([self::cube('a', 50)], [self::box('c')], ['configuration' => ['solvers' => ['brute_force']]])));
    }

    public static function testAnEffortBudgetIsPlumbedThroughAndActuallyBoundsTheSearch(): void
    {
        $result = ArrayCodec::pack(self::request([self::cube('cube', 20, ['quantity' => 20])], [self::box('c', 200, 200, 200)],
            ['configuration' => ['solvers' => ['grid'], 'time_limit_ms' => 60_000, 'effort_budget' => ['max_search_nodes' => 5]]]));
        $placed = array_sum(array_map(static fn($c) => count($c['placements']), $result['containers']));
        self::assertSame(5, $placed);
        self::assertSame('effort_limit', $result['termination']['code']);
        self::assertFalse($result['algorithm']['time_limit_reached']);
        self::assertTrue($result['algorithm']['effort_limit_reached']);
    }

    public static function testAxlesArePlumbedThroughAndPreventAnOverload(): void
    {
        // 800 kg centred over a 1000mm span with axles at 100mm/900mm puts 400 kg on
        // each -- a 399 kg front limit rejects the single-item placement outright, so
        // the item can never be packed at all.
        $result = ArrayCodec::pack(self::request(
            [['id' => 'heavy', 'dimensions' => self::mm(1000, 100, 100), 'weight' => '800 kg']],
            [self::box('truck', 1000, 100, 100, ['axles' => [
                ['position' => ['value' => '100', 'unit' => 'mm'], 'max_load' => ['value' => '399', 'unit' => 'kg']],
                ['position' => ['value' => '900', 'unit' => 'mm'], 'max_load' => ['value' => '500', 'unit' => 'kg']],
            ]])],
            ['configuration' => ['solvers' => ['extreme_points']]],
        ));
        self::assertNotSame('invalid_result', $result['status']);
        self::assertSame([], $result['containers']);
        self::assertSame(['heavy#1'], array_map(static fn($u) => $u['item_id'], $result['unpacked_items']));
    }

    public static function testGrossAxleReactionsIncludeTareAndSerializeAsExactFractions(): void
    {
        $result = ArrayCodec::pack(self::request(
            [['id' => 'empty', 'dimensions' => self::mm(10, 10, 10)]],
            [self::box('truck', 20, 20, 20, [
                'tare_weight' => '100 kg',
                'axles' => [
                    ['position' => '5', 'max_load' => '50 kg'],
                    ['position' => '15', 'max_load' => '50 kg'],
                ],
            ])],
        ));
        $reaction = $result['containers'][0]['axle_reactions'];
        self::assertSame('gross', $reaction['basis']);
        self::assertSame($reaction['front_numerator'], $reaction['rear_numerator']);
    }

    public static function testNestingHeightIsPlumbedThroughAndFitsAnExtraLayer(): void
    {
        // A 100mm item nesting 40mm into the one below advances the stack by 60mm
        // per layer instead of 100mm, fitting a third into a 220mm-tall container
        //.
        $result = ArrayCodec::pack(self::request(
            [['id' => 'crate', 'dimensions' => self::mm(100, 100, 100), 'quantity' => 10, 'nesting_height' => ['value' => '40', 'unit' => 'mm']]],
            [self::box('c', 100, 100, 220)],
            ['configuration' => ['solvers' => ['grid'], 'max_containers' => 1]],
        ));
        $placed = array_sum(array_map(static fn($c) => count($c['placements']), $result['containers']));
        self::assertSame(3, $placed);
    }

    public static function testTheContainerBudgetIsPlumbedThrough(): void
    {
        $result = ArrayCodec::pack(self::request(
            [self::cube('a', 90, ['quantity' => 4])], [self::box('c', 100, 100, 100, ['quantity' => 10])],
            ['configuration' => ['max_containers' => 2]],
        ));
        self::assertSame(2, $result['summary']['container_count']);
    }

    public static function testTheAlternativesCountIsPlumbedThrough(): void
    {
        $result = ArrayCodec::pack(self::request(
            [self::cube('a', 40, ['quantity' => 6])], [self::box('c', 150, 150, 150, ['quantity' => 2])],
            ['configuration' => ['solver_profile' => 'quality', 'time_limit_ms' => 2000, 'alternatives' => 2]],
        ));
        self::assertLessThanOrEqual(1, count($result['alternatives']));
    }

    public static function testClearanceIsPlumbedThroughInTheRequestUnit(): void
    {
        $result = ArrayCodec::pack(self::request(
            [self::cube('a', 40, ['quantity' => 2])], [self::box('c')],
            ['configuration' => ['clearance' => '2']],
        ));
        $positions = array_map(
            static fn(array $p): int => $p['position']['x']['ticks'],
            $result['containers'][0]['placements'],
        );
        self::assertContains(2 * 16_000, $positions, 'the first placement sits one clearance inside its envelope');
    }

    public static function testTheCandidatePointBudgetIsSupportedByThePortableApi(): void
    {
        $result = ArrayCodec::pack(self::request(
            [self::cube('a', 40, ['quantity' => 2])], [self::box('c')],
            ['configuration' => ['max_candidate_points' => 16]],
        ));
        self::assertTrue($result['complete']);
    }

    public static function testTheCandidatePointBudgetRejectsValuesBelowTheContractMinimum(): void
    {
        self::assertThrows(\InvalidArgumentException::class, static fn() => ArrayCodec::pack(self::request(
            [self::cube('a', 40)], [self::box('c')],
            ['configuration' => ['max_candidate_points' => 15]],
        )));
    }

    // --------------------------------------------------------------------- contents

    public static function testObstaclesSurviveTheRoundTrip(): void
    {
        $result = ArrayCodec::pack(self::request([self::cube('a', 40)], [self::box('b', 100, 100, 100, [
            'obstacles' => [[
                'id' => 'post',
                'origin' => ['x' => '0', 'y' => '0', 'z' => '0'],
                'dimensions' => self::mm(50, 50, 100),
            ]],
        ])]));
        self::assertTrue($result['complete']);
    }

    public static function testANonRectangularObstacleIsExpressedAsAUnionOfBoxes(): void
    {
        // A tapered container corner: approximated by two boxes, not a
        // single rectangle, and an item is correctly routed around both, not just
        // the first one it happened to be constructed with.
        $result = ArrayCodec::pack(self::request([self::cube('filler', 20)], [self::box('tapered', 100, 100, 100, [
            'obstacles' => [[
                'id' => 'taper',
                'origin' => ['x' => '0', 'y' => '0', 'z' => '0'],
                'dimensions' => self::mm(40, 100, 100),
                'additional_boxes' => [[
                    'origin' => ['x' => '60', 'y' => '0', 'z' => '0'],
                    'dimensions' => self::mm(40, 100, 100),
                ]],
            ]],
        ])], ['configuration' => ['solvers' => ['extreme_points']]]));
        self::assertTrue($result['complete']);
        [$placement] = $result['containers'][0]['placements'];
        $x = $placement['position']['x']['ticks'];
        self::assertTrue($x >= Length::mm(40)->ticks && $x <= Length::mm(60)->ticks, "x={$x} outside the gap");
    }

    public static function testAnUnplaceableItemIsReportedWithItsReason(): void
    {
        $result = ArrayCodec::pack(self::request([self::cube('a', 200)], [self::box('b', 100, 100, 100)]));
        self::assertSame([
            'item_id' => 'a#1', 'item_type' => 'a',
            'reason' => 'no_compatible_container_dimensions', 'details' => [],
            'proof' => [
                'level' => 'proven',
                'observations' => [[
                    'code' => 'no_compatible_container_dimensions',
                    'count' => 1,
                    'details' => [],
                ]],
            ],
        ], $result['unpacked_items'][0]);
    }

    public static function testReasonProofLevelsAreStable(): void
    {
        $expected = [
            'no_compatible_container_dimensions' => 'proven',
            'payload_exceeded' => 'proven',
            'no_feasible_placement' => 'observed',
            'search_exhausted' => 'observed',
            'group_cannot_fit_together' => 'inferred',
            'time_limit' => 'unknown_due_to_limit',
        ];
        foreach ($expected as $reason => $level) {
            $proof = ReasonProof::forReason($reason);
            self::assertSame($level, $proof->level);
            self::assertSame($reason, $proof->observations[0]->code);
        }
    }

    public static function testItemRulesSurviveTheRoundTrip(): void
    {
        $result = ArrayCodec::pack(self::request(
            [self::cube('floor', 40, ['quantity' => 4, 'must_be_on_floor' => true]),
             self::cube('kit', 30, ['quantity' => 2, 'group' => 'kit'])],
            [self::box('c', 100, 100, 100, ['quantity' => 4])],
        ));
        foreach ($result['containers'] as $container) {
            foreach ($container['placements'] as $placement) {
                if ($placement['item_type'] === 'floor') {
                    self::assertSame(0, $placement['position']['z']['ticks']);
                }
            }
        }
    }

    public static function testAllowedRotationsSurviveTheRoundTrip(): void
    {
        $result = ArrayCodec::pack(self::request(
            [['id' => 'a', 'dimensions' => self::mm(120, 40, 60), 'allowed_rotations' => ['LWH']]],
            [self::box('c', 60, 120, 40)],
        ));
        self::assertFalse($result['complete']);
    }

    public static function testAlternativesDoNotNestIndefinitely(): void
    {
        // Each alternative is rendered without its own alternatives, so the payload
        // stays bounded no matter how many starts ran.
        $result = ArrayCodec::pack(self::request(
            [self::cube('a', 40, ['quantity' => 6])], [self::box('c', 150, 150, 150, ['quantity' => 2])],
            ['configuration' => ['solver_profile' => 'quality', 'time_limit_ms' => 2000]],
        ));
        foreach ($result['alternatives'] as $alternative) {
            self::assertSame([], $alternative['alternatives']);
        }
    }
}
