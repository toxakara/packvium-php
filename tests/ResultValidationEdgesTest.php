<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Serialization\ArrayCodec;
use Packvium\Support\ResultValidation\Cuboid;
use Packvium\Support\ResultValidation\Entry;
use Packvium\Support\ResultValidation\Exact;
use Packvium\Support\ResultValidation\Json;
use Packvium\Support\ResultValidation\Ordered;
use Packvium\Support\ResultValidation\PyFormat;
use Packvium\Support\ResultValidation\Ratio;
use Packvium\Support\ResultValidation\Spec;
use Packvium\Support\ResultValidation\Stacking;
use Packvium\Validation\MalformedResult;

use function Packvium\Validation\validate;

/**
 * The reference validator's port on inputs the engines never produce: hand-edited requests and
 * results that only a third-party caller would send. Every issue list here is the one
 * `packvium.validate` returns for the same pair; where the reference fails with a Python error
 * rather than `MalformedResult`, the port's refusal is a `shape` one (see `MalformedResult`).
 */
final class ResultValidationEdgesTest extends TestCase
{
    private const MM = 16000;

    private const REQUEST = [
        'items' => [['id' => 'cube', 'quantity' => 2, 'weight' => '1500', 'dimensions' => ['length' => '10', 'width' => '10', 'height' => '10']]],
        'containers' => [['id' => 'box', 'inner_dimensions' => ['length' => '20', 'width' => '10', 'height' => '10']]],
    ];

    private const OVERSIZED = [
        'items' => [['id' => 'big', 'dimensions' => ['length' => '50', 'width' => '10', 'height' => '10']]],
        'containers' => [['id' => 'box', 'inner_dimensions' => ['length' => '20', 'width' => '10', 'height' => '10']]],
    ];

    private const AS_OF = 1704067200000;

    // ------------------------------------------------------------------ accounting

    public static function testAPolicyRuleWhoseAppliesToIsNotAnObjectIsRefused(): void
    {
        $request = self::REQUEST;
        $request['policy'] = ['as_of' => 1, 'rules' => [[
            'id' => 'r', 'version' => 1, 'applies_to' => ['x'],
            'require_container_tag' => ['item_tag' => 'a', 'container_tag' => 'b'],
        ]]];
        self::assertRefused(MalformedResult::SHAPE, 'applies_to must be an object', $request, ArrayCodec::pack(self::REQUEST));
    }

    public static function testAPolicyRejectionIsRefutedOnceAContainerCarriesTheRequiredTag(): void
    {
        $request = [
            'units' => ['length' => 'mm'],
            'policy' => ['as_of' => self::AS_OF, 'rules' => [[
                'id' => 'cold-chain', 'version' => 7, 'effective_at' => self::AS_OF, 'priority' => 10,
                'require_container_tag' => ['item_tag' => 'food', 'container_tag' => 'reefer'],
            ]]],
            'items' => [['id' => 'carton', 'quantity' => 1, 'tags' => ['food'], 'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100']]],
            'containers' => [['id' => 'pallet', 'quantity' => 4, 'inner_dimensions' => ['length' => '300', 'width' => '300', 'height' => '300']]],
        ];
        $result = ArrayCodec::pack($request);
        self::assertSame('policy_rule', $result['unpacked_items'][0]['reason']);
        self::assertSame([], validate($request, $result));

        $request['containers'][0]['tags'] = ['reefer'];
        self::assertIssues(
            ['invalid_proven_reason: carton#1: no participating policy rule rules it out of every container'],
            $request, $result,
        );
    }

    public static function testAnInfeasibleStatusWithEveryRejectionProvenIsValid(): void
    {
        $result = ArrayCodec::pack(self::OVERSIZED);
        self::assertSame('proven', $result['unpacked_items'][0]['proof']['level']);
        $result['status'] = 'infeasible';
        $result['feasibility'] = ['code' => 'infeasible'];
        $result['optimality'] = ['code' => 'proven_infeasible'];
        self::assertSame([], validate(self::OVERSIZED, $result));
    }

    // ------------------------------------------------------------------ container specs

    public static function testASingleAxleIsRefusedRatherThanReadAsAFrontAndRearPair(): void
    {
        $request = self::REQUEST;
        $request['containers'][0]['axles'] = [['position' => '5', 'max_load' => '100000']];
        self::assertRefused(MalformedResult::SHAPE, 'box#1: axles must name exactly a front and a rear axle', $request, ArrayCodec::pack(self::REQUEST));
    }

    public static function testTagLimitsThatAreNotAnObjectAreRefused(): void
    {
        $request = self::REQUEST;
        $request['containers'][0]['tag_limits'] = ['x'];
        self::assertRefused(MalformedResult::SHAPE, 'tag_limits must be an object', $request, ArrayCodec::pack(self::REQUEST));
    }

    public static function testAPressureLimitWithoutACompressionRatioIsRefused(): void
    {
        $request = self::REQUEST;
        $request['items'][0]['max_compression_pressure_kpa'] = 10;
        self::assertRefused(MalformedResult::SHAPE, 'cube: max_compression_pressure_kpa without compression_ratio', $request, ArrayCodec::pack(self::REQUEST));
    }

    public static function testAContainerWithNoVolumeAddsNothingToTheUnusedVolumeTerm(): void
    {
        $request = self::REQUEST;
        $request['containers'][] = ['id' => 'flat', 'inner_dimensions' => ['length' => '20', 'width' => '10', 'height' => '0']];
        $result = ArrayCodec::pack(self::REQUEST);
        $result['containers'][0]['container_type'] = 'flat';
        self::assertIssues([
            'outside_container: cube#1 in box#1',
            'outside_container: cube#2 in box#1',
            'void_fill_reserve_exceeded: box#1: used 8192000000000000 > usable 0',
            'score_mismatch: reported [0, 1, 0, 0, 1000000], recomputed [0, 1, 0, 0, 0]',
        ], $request, $result);
    }

    // ------------------------------------------------------------------ objective

    public static function testAnObjectiveThatIsNotAStringIsUnknown(): void
    {
        $request = self::REQUEST;
        $request['configuration'] = ['objective' => 5];
        self::assertRefused(MalformedResult::CONTRACT, 'unknown requested objective 5', $request, ArrayCodec::pack(self::REQUEST));
    }

    public static function testARateTableWithFewerPricesThanBracketsPricesOnlyThePairedBrackets(): void
    {
        [$request, $result] = self::landedCost(['weight_brackets_g' => [1, 2], 'prices_minor' => [100]]);
        self::assertRefused(
            MalformedResult::CONTRACT,
            "container 'box' bills at 3000 g, above its rate table's last bracket (2 g); the shipment has no published price",
            $request, $result,
        );
    }

    public static function testAnEmptyRateTableIsRefused(): void
    {
        [$request, $result] = self::landedCost(['weight_brackets_g' => [], 'prices_minor' => []]);
        self::assertRefused(MalformedResult::SHAPE, 'an empty rate table', $request, $result);
    }

    // ------------------------------------------------------------------ stacking

    public static function testAFloatingItemUnderAVanishingSupportRatioHasNoSupportHull(): void
    {
        // 1e-7 rounds to 0 ppm, so the required area is nothing and the centroid rule decides.
        $request = self::REQUEST;
        $request['configuration'] = ['minimum_support_ratio' => 0.0000001];
        $result = ArrayCodec::pack(self::REQUEST);
        $result['containers'][0]['placements'][1]['position']['z'] = ['ticks' => 5 * self::MM];
        $result['containers'][0]['placements'][1] = Support::restatedId($result['containers'][0]['placements'][1], 'box#1');
        self::assertIssues([
            'outside_container: cube#2 in box#1',
            'centre_of_gravity_unsupported: cube#2: centroid outside its support hull',
            'score_mismatch: reported [0, 1, 0, 0, 1000000], recomputed [0, 1, 0, 0, 1500000]',
        ], $request, $result);
    }

    public static function testANestedItemKeepsItsOtherSupportersInIndexOrderAfterItsPredecessor(): void
    {
        $lower = self::entry('cup#1', 'cup', 0, 0, 100);
        $upper = self::entry('cup#2', 'cup', 0, 20, 100);
        $block = self::entry('block#1', 'block', 0, 0, 20);
        [$supporters, $directAbove] = Stacking::supportGraph([$lower, $upper, $block]);
        $base = 50 * self::MM * 50 * self::MM;
        self::assertSame([[], [[0, $base], [2, $base]], []], $supporters);
        self::assertSame([[1 => true], [], [1 => true]], $directAbove);
    }

    public static function testOnlyTheSameTypeOnTheSameFootprintNests(): void
    {
        $lower = self::entry('cup#1', 'cup', 0, 0, 100);
        self::assertTrue(Stacking::validNesting($lower, self::entry('cup#2', 'cup', 0, 20, 100)));
        self::assertFalse(Stacking::validNesting($lower, self::entry('mug#1', 'mug', 0, 20, 100)), 'another type with the same nesting height');
        self::assertFalse(Stacking::validNesting($lower, self::entry('cup#3', 'cup', 10, 20, 100)), 'the same type, shifted along x');
    }

    public static function testAHullWhoseEnvelopeDisagreesWithItsDimensionsOccupiesTheWholeEnvelope(): void
    {
        $wedge = self::specs()->get('wedge');
        $fitting = new Cuboid(0, 0, 0, 10 * self::MM, 10 * self::MM, 10 * self::MM);
        self::assertSame(
            [[0, 0, 0], [10 * self::MM, 0, 0], [0, 10 * self::MM, 0], [0, 0, 10 * self::MM]],
            Stacking::placedHull($fitting, ['orientation' => 'LWH'], $wedge),
        );
        $wide = new Cuboid(0, 0, 0, 12 * self::MM, 10 * self::MM, 10 * self::MM);
        self::assertSame($wide->vertices(), Stacking::placedHull($wide, ['orientation' => 'LWH'], $wedge));
    }

    public static function testAnItemWithoutAPressureLimitKeepsItsHeightUnderAnyLoad(): void
    {
        self::assertSame(100, Stacking::compressedHeight(100, 1, self::specs()->get('block'), 5_000_000));
    }

    // ------------------------------------------------------------------ primitives

    public static function testListsOfDifferentLengthsAreNeverEqual(): void
    {
        self::assertFalse(Json::equals([1, 2], [1]));
        self::assertFalse(Json::equals(['a' => 1], ['a' => 1, 'b' => 2]));
        self::assertTrue(Json::equals([1, 2], [1, 2]));
    }

    /** Python's `float(Fraction)` where the float estimate of the quotient's magnitude is off by one bit. */
    public static function testTheNearestDoubleSurvivesAMisjudgedMagnitude(): void
    {
        // log2 rounds 2^60 - 1 up to 60, and rounding the 53-bit quotient carries into 2^53.
        self::assertSame(2.0 ** 60, Ratio::integer((1 << 60) - 1)->toFloat());
        // log2(7 * 2^62) - log2(7) comes out just below 62.
        self::assertSame(2.0 ** 62, Ratio::of(Exact::add(Exact::mul(7, Exact::powerOfTwo(62)), 1), 7)->toFloat());
    }

    public static function testTextThatIsNotUtf8IsEscapedByteByByte(): void
    {
        self::assertSame("'a\\x80b'", PyFormat::stringRepr("a\x80b"));
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string,mixed> $rateTable @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private static function landedCost(array $rateTable): array
    {
        $request = self::REQUEST;
        $request['configuration'] = ['objective' => 'lowest_landed_cost', 'dimensional_weight_divisor' => 5000];
        $request['containers'][0]['rate_table'] = $rateTable;
        $result = ArrayCodec::pack(self::REQUEST);
        $result['objective'] = 'lowest_landed_cost';
        return [$request, $result];
    }

    private static function specs(): Ordered
    {
        $corner = static fn(string $x, string $y, string $z): array => ['x' => $x, 'y' => $y, 'z' => $z];
        return Spec::items(['items' => [
            ['id' => 'cup', 'nesting_height' => '80', 'dimensions' => ['length' => '50', 'width' => '50', 'height' => '100']],
            ['id' => 'mug', 'nesting_height' => '80', 'dimensions' => ['length' => '50', 'width' => '50', 'height' => '100']],
            ['id' => 'block', 'dimensions' => ['length' => '50', 'width' => '50', 'height' => '20']],
            ['id' => 'wedge', 'shape_type' => 'convex_hull', 'dimensions' => ['length' => '10', 'width' => '10', 'height' => '10'],
                'hull_vertices' => [$corner('0', '0', '0'), $corner('10', '0', '0'), $corner('0', '10', '0'), $corner('0', '0', '10')]],
        ]], 'mm');
    }

    /** A 50 x 50 mm footprint at `x`, `z` millimetres, `height` millimetres tall. */
    private static function entry(string $id, string $type, int $x, int $z, int $height): Entry
    {
        $envelope = new Cuboid($x * self::MM, 0, $z * self::MM, 50 * self::MM, 50 * self::MM, $height * self::MM);
        return new Entry($id, $envelope, ['item_type' => $type], self::specs()->get($type));
    }

    /** @param list<string> $expected @param array<string,mixed> $request @param array<string,mixed> $result */
    private static function assertIssues(array $expected, array $request, array $result): void
    {
        self::assertSame($expected, array_map('strval', validate($request, $result)));
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $result */
    private static function assertRefused(string $kind, string $message, array $request, array $result): void
    {
        try {
            validate($request, $result);
        } catch (MalformedResult $refusal) {
            self::assertSame($kind, $refusal->kind);
            self::assertSame($message, $refusal->getMessage());
            return;
        }
        self::assertTrue(false, "expected a {$kind} refusal: {$message}");
    }
}
