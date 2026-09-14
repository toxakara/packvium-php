<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Execution\ExecutionPlanException;
use Packvium\Execution\Plan;

/**
 * The execution-plan adapter in PHP.
 *
 * docs/EXECUTION-PLAN.md is the contract and `packvium.execution` is the reference. This
 * suite holds the same properties the Python one does, because the bar is byte-identical
 * output and a property proved on only one side of that pair proves half of it.
 *
 * The cross-language equality itself is asserted by the conformance harness rather than
 * here: a PHP test cannot run the Python adapter, and a committed expected string would
 * only prove that PHP still agrees with a string, not that the two implementations agree
 * with each other.
 *
 * The alternatives assertions run on a constructed result -- not because nothing emits
 * them, but because `conformance/golden/` stores the canonical *projection*, which drops
 * the field entirely. 165 of the 399 fixtures do produce runners-up on a live solve
 *; none of that survives into a golden, so a constructed result is what exercises
 * these rules here.
 */
final class ExecutionPlanTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function scalar(int $ticks): array
    {
        // Both spellings, as a real result carries them: `value` is a rendering and
        // `ticks` is the number. Supplying only one could not catch a reference built on
        // the wrong one.
        return ['ticks' => $ticks, 'value' => (string) \intdiv($ticks, 16000), 'unit' => 'mm'];
    }

    /** @return array<string,mixed> */
    private static function placement(string $itemType, int $x, int $y, int $z): array
    {
        return [
            'item_id' => $itemType . '#' . $x . $y . $z,
            'item_type' => $itemType,
            'orientation' => 'LWH',
            'position' => ['x' => self::scalar($x), 'y' => self::scalar($y), 'z' => self::scalar($z)],
            'dimensions' => [
                'length' => self::scalar(1600000),
                'width' => self::scalar(1600000),
                'height' => self::scalar(1600000),
            ],
            'support_ratio' => 1.0,
            'top_load' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function result(array $overrides = []): array
    {
        return $overrides + [
            'status' => 'feasible',
            'objective' => 'default',
            'score' => [0, 1, 0, 0, 1000000],
            'feasibility' => ['code' => 'feasible'],
            'optimality' => ['code' => 'not_proven'],
            'containers' => [[
                'id' => 'box#1',
                'container_type' => 'box',
                'volume_utilization' => 0.5,
                'placements' => [
                    self::placement('cube', 0, 0, 0),
                    self::placement('cube', 1600000, 0, 0),
                ],
            ]],
            'unpacked_items' => [],
            'alternatives' => [],
        ];
    }

    public static function testWithoutAnInjectedOrderThePlanSaysSo(): void
    {
        $container = Plan::build([], self::result())['containers'][0];
        self::assertSame('unavailable', $container['order']);
        // Every placement is still listed, so nothing is hidden -- but no step numbers,
        // because array position is an artifact of candidate iteration.
        self::assertCount(2, $container['steps']);
        foreach ($container['steps'] as $step) {
            self::assertFalse(\array_key_exists('sequence', $step));
        }
    }

    public static function testAnInjectedOrderIsUsedVerbatim(): void
    {
        $container = Plan::build([], self::result(), [0 => [1, 0]])['containers'][0];
        self::assertSame('loading', $container['order']);
        self::assertSame([1, 2], \array_column($container['steps'], 'sequence'));
        // Step 1 is the placement the caller put first, not the one the result listed first.
        self::assertSame(1600000, $container['steps'][0]['placement']['position_ticks']['x']);
    }

    public static function testAnOrderThatIsNotAPermutationIsRefused(): void
    {
        try {
            Plan::build([], self::result(), [0 => [0, 0]]);
        } catch (ExecutionPlanException $error) {
            self::assertTrue(\str_contains($error->getMessage(), 'permutation'));
            return;
        }
        self::fail('a loading order that repeats an index was accepted');
    }

    public static function testThePlacementReferenceDoesNotUseItemId(): void
    {
        // `item_id` exists and conformance/canonical.py drops it as an instance count;
        // referencing it would make a plan two correct engines disagree about.
        $reference = Plan::placementReference(0, self::placement('cube', 0, 0, 0));
        self::assertSame(
            ['container_index', 'item_type', 'orientation', 'position_ticks'],
            \array_keys($reference)
        );
    }

    public static function testThePlacementReferenceReadsTicksAndNotTheRenderedValue(): void
    {
        $placement = self::placement('cube', 12345, 0, 0);
        $placement['position']['x']['value'] = 'wrong';
        self::assertSame(12345, Plan::placementReference(0, $placement)['position_ticks']['x']);
    }

    public static function testAPlacementItCannotReferenceIsRefused(): void
    {
        $placement = self::placement('cube', 0, 0, 0);
        unset($placement['orientation']);
        try {
            Plan::placementReference(0, $placement);
        } catch (ExecutionPlanException $error) {
            self::assertTrue(\str_contains($error->getMessage(), 'missing a field'));
            return;
        }
        self::fail('a placement with no orientation was referenced anyway');
    }

    public static function testTheLossIsTheFirstDifferingIndexAndNeverABlend(): void
    {
        $plan = Plan::build([], self::result([
            'alternatives' => [['status' => 'feasible', 'score' => [0, 2, 0, 0, 900000]]],
        ]));
        self::assertSame(
            ['index' => 1, 'winner' => 1, 'alternative' => 2, 'difference' => 1],
            $plan['alternatives'][0]['facts']['first_difference']
        );
        $facts = $plan['alternatives'][0]['facts'];
        self::assertFalse(\array_key_exists('total', $facts));
        self::assertFalse(\array_key_exists('weighted', $facts));
    }

    public static function testTheSentenceNamesAnAxisAndClaimsNoCause(): void
    {
        $plan = Plan::build([], self::result([
            'alternatives' => [['status' => 'feasible', 'score' => [0, 2, 0, 0, 900000]]],
        ]));
        $presentation = $plan['alternatives'][0]['presentation'];
        self::assertTrue(\str_contains($presentation['summary'], 'axis 1'));
        self::assertSame(['score', 'alternatives[].score'], $presentation['cites']);
        foreach (['because', 'due to', 'caused'] as $causal) {
            self::assertFalse(\str_contains(\strtolower($presentation['summary']), $causal));
        }
    }

    public static function testAnIdenticalScoreIsReportedAsUndetermined(): void
    {
        $plan = Plan::build([], self::result([
            'alternatives' => [['status' => 'feasible', 'score' => [0, 1, 0, 0, 1000000]]],
        ]));
        self::assertSame(null, $plan['alternatives'][0]['facts']['first_difference']);
        self::assertTrue(\str_contains(
            $plan['alternatives'][0]['presentation']['summary'], 'does not record why'
        ));
    }

    public static function testScoreVectorsOfDifferentLengthAreRefused(): void
    {
        try {
            Plan::build([], self::result(['alternatives' => [['score' => [0, 1]]]]));
        } catch (ExecutionPlanException $error) {
            self::assertTrue(\str_contains($error->getMessage(), 'different length'));
            return;
        }
        self::fail('score vectors of different length were compared anyway');
    }

    public static function testAResultWithNoAlternativesIsWellFormed(): void
    {
        // The common case, and today the only one.
        $plan = Plan::build([], self::result());
        self::assertSame([], $plan['alternatives']);
        self::assertSame(Plan::FORMAT, $plan['format']);
    }

    public static function testAnUnpackedItemKeepsItsProofLevelUnsoftened(): void
    {
        $plan = Plan::build([], self::result(['unpacked_items' => [[
            'item_id' => 'ladder#1', 'item_type' => 'ladder', 'reason' => 'no_container_fits',
            'details' => ['longest dimension exceeds every container'],
            'proof' => ['level' => 'observed', 'observations' => [['code' => 'too_long']]],
        ]]]));
        $entry = $plan['unplaced'][0];
        self::assertSame('observed', $entry['facts']['proof_level']);
        // The level appears in the sentence too: a reader must not be told "cannot fit"
        // when the engine only observed that it did not.
        self::assertTrue(\str_contains($entry['presentation']['summary'], 'observed'));
        self::assertSame(
            ['unpacked_items[].reason', 'unpacked_items[].proof.level'],
            $entry['presentation']['cites']
        );
    }

    public static function testEveryPresentationBlockCitesAtLeastOneField(): void
    {
        $plan = Plan::build([], self::result([
            'alternatives' => [['status' => 'feasible', 'score' => [0, 2, 0, 0, 900000]]],
            'unpacked_items' => [[
                'item_id' => 'x#1', 'item_type' => 'x', 'reason' => 'no_container_fits',
                'details' => [], 'proof' => ['level' => 'proven', 'observations' => [['code' => 'c']]],
            ]],
        ]));
        $blocks = \array_merge($plan['alternatives'], $plan['unplaced']);
        self::assertCount(2, $blocks);
        foreach ($blocks as $block) {
            self::assertTrue($block['presentation']['cites'] !== []);
        }
    }

    public static function testTheSameInputsProduceTheSameBytes(): void
    {
        $first = Plan::canonicalJson(Plan::build([], self::result()));
        $second = Plan::canonicalJson(Plan::build([], self::result()));
        self::assertSame($first, $second);
        // Sorted keys and no incidental whitespace, so Python can be diffed against this.
        self::assertTrue(\str_starts_with($first, '{"alternatives":'));
        self::assertFalse(\str_contains($first, ', '));
    }

    public static function testCanonicalJsonSortsMapsButNeverReordersASequence(): void
    {
        // Steps are ordered on purpose; sorting them would be a different plan.
        $json = Plan::canonicalJson(Plan::build([], self::result(), [0 => [1, 0]]));
        $roundTrip = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([1, 2], \array_column($roundTrip['containers'][0]['steps'], 'sequence'));
        self::assertSame(
            1600000,
            $roundTrip['containers'][0]['steps'][0]['placement']['position_ticks']['x']
        );
    }

    public static function testAResultWithoutAStatusIsNotAValidatedResult(): void
    {
        try {
            Plan::build([], ['containers' => []]);
        } catch (ExecutionPlanException $error) {
            self::assertTrue(\str_contains($error->getMessage(), 'validated result'));
            return;
        }
        self::fail('a result with no status produced a plan');
    }

    public static function testTheAdapterImportsNoSolverAndNoValidator(): void
    {
        // The dependency direction from docs/EXECUTION-PLAN.md, as the cheapest test of it.
        $source = (string) \file_get_contents(__DIR__ . '/../src/Execution/Plan.php');
        foreach (['Packvium\\Algorithm', 'Packvium\\Validation', 'Packvium\\Packer'] as $forbidden) {
            self::assertFalse(\str_contains($source, 'use ' . $forbidden));
        }
    }
}
