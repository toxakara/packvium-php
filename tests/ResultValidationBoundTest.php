<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Serialization\ArrayCodec;
use Packvium\Support\ResultValidation\Bound;
use Packvium\Support\ResultValidation\Exact;
use Packvium\Validation\ResultIssue;

use function Packvium\Validation\validate;

/**
 * The PHP port's copy of the optimality bound, held to the oracle's numbers on every case
 * and refusal edge of the shared scene, and the four checks it feeds, each broken once. Mirrors
 * `packvium-python/tests/test_result_validation.py`; agreement on the whole corpus is
 * `conformance/result_validation/run.py`'s job.
 */
final class ResultValidationBoundTest extends TestCase
{
    /** One cube in a box for two: every position but the height is attained. */
    private const REQUEST = [
        'items' => [['id' => 'cube', 'quantity' => 1, 'dimensions' => ['length' => '10', 'width' => '10', 'height' => '10']]],
        'containers' => [['id' => 'box', 'inner_dimensions' => ['length' => '20', 'width' => '10', 'height' => '10']]],
    ];

    private const GAP = [
        'code' => 'not_proven',
        'lower_bound' => [0, 1, 0, 500000, 500000],
        'gap_key' => 4,
        'absolute_gap' => 500000,
        'relative_gap' => ['numerator' => 500000, 'denominator' => 500000],
    ];

    /** @param mixed $value @return int|string */
    private static function integer($value)
    {
        return Exact::normalize(\is_int($value) ? $value : (string)$value);
    }

    /** @param list<array<string,mixed>> $instances @return list<array{0:int|string,1:int|string}> */
    private static function runs(array $instances, string $field): array
    {
        return Bound::runs(\array_map(static function (array $instance) use ($field): array {
            return [self::integer($instance[$field]), 1];
        }, $instances));
    }

    public static function testTheBoundMatchesEveryCaseAndEdgeOfTheSharedScene(): void
    {
        $path = \dirname(__DIR__, 2) . '/conformance/scene/objective-bounds.json';
        if (!\is_file($path)) {
            self::skip('the shared cross-language scene fixture is not part of this package');
        }
        $scene = \json_decode((string)\file_get_contents($path), true);
        $compared = 0;
        foreach (\array_merge($scene['cases'], $scene['edges']) as $case) {
            $containers = \array_map(static function (array $container): array {
                return [
                    'inner_volume' => self::integer($container['inner']),
                    'base_area' => self::integer($container['base_area']),
                    'height' => self::integer($container['height']),
                    'usable' => self::integer($container['usable']),
                    'cost_minor' => self::integer($container['cost_minor']),
                    'payload' => $container['payload'],
                    'max_items' => $container['max_items'],
                    'quantity' => $container['quantity'],
                ];
            }, $case['containers']);
            $shrinks = \in_array(true, \array_column($case['instances'], 'shrinks'), true);
            $computed = Bound::lowerBound(self::runs($case['instances'], 'volume'), self::runs($case['instances'], 'weight'), $shrinks, $containers);
            self::assertSame($case['bounds'], $computed, 'bound diverges from the oracle on ' . ($case['name'] ?? $case['fixture']));
            ++$compared;
        }
        self::assertGreaterThan(400, $compared);
    }

    public static function testANegativeOrUnlimitedCapacityAndZeroVolumesAreReadAsTheReferenceReadsThem(): void
    {
        $container = [
            'inner_volume' => 1000, 'base_area' => 100, 'height' => 10, 'usable' => -1, 'cost_minor' => 3,
            'payload' => 5, 'max_items' => null, 'quantity' => 1,
        ];
        self::assertSame([2, 0, 0, 0, 0], Bound::lowerBound([[0, 2]], [[1, 2]], false, [$container]));
        $container['quantity'] = null;
        self::assertSame([0, 1, 3, 1000000, 0], Bound::lowerBound([[0, 2]], [[1, 2]], false, [$container]));
        self::assertSame([1, 0, 0, 0, 0], Bound::lowerBound([[1, 1]], [[0, 1]], false, []));
    }

    /** @param array<string,mixed> $optimality @return list<string> */
    private static function codes(array $optimality, ?callable $change = null): array
    {
        $result = ArrayCodec::pack(self::REQUEST);
        $result['optimality'] = $optimality;
        if ($change !== null) {
            $result = $change($result);
        }
        return \array_map(static function (ResultIssue $issue): string { return $issue->code; }, validate(self::REQUEST, $result));
    }

    public static function testAStatedGapAndItsAbsenceAreBothValid(): void
    {
        self::assertSame([], self::codes(self::GAP));
        self::assertSame([], self::codes(['code' => 'not_proven']));
    }

    public static function testABoundWhereNoneIsStatedOrOfTheWrongShapeIsAMismatch(): void
    {
        $details = static function (array $request, array $result): array {
            return \array_map(static function (ResultIssue $issue): string { return $issue->detail; }, validate($request, $result));
        };
        $result = ArrayCodec::pack(self::REQUEST);
        $result['optimality'] = ['code' => 'not_proven', 'lower_bound' => []];
        self::assertSame(['lower_bound must be a list of 1 to 5 non-negative integers'], $details(self::REQUEST, $result));

        // Payload capacity 8e24 weight ticks times a million boxes passes the 10^30 ceiling.
        $huge = self::REQUEST;
        $huge['containers'][0]['max_payload'] = ['value' => '1000000000000000', 'unit' => 'kg'];
        $huge['containers'][0]['quantity'] = 1000000;
        // The same packing answers it; only the inventory changed.
        $packed = ArrayCodec::pack(self::REQUEST);
        $packed['optimality']['lower_bound'] = [0];
        self::assertSame(['lower_bound is reported where no bound is stated: the request is past a declared ceiling'], $details($huge, $packed));

        $invalid = ArrayCodec::pack(self::REQUEST);
        $invalid['status'] = 'invalid_result';
        $invalid['optimality'] = ['code' => 'not_proven', 'lower_bound' => [0]];
        self::assertContains(
            "lower_bound is reported where no bound is stated: status is 'invalid_result'",
            $details(self::REQUEST, $invalid)
        );
    }

    public static function testEachCheckFiresOnItsOwnMutation(): void
    {
        $wrongBound = self::GAP;
        $wrongBound['lower_bound'][1] = 2;
        self::assertSame(['optimality_bound_mismatch'], self::codes($wrongBound));
        $wrongGap = self::GAP;
        $wrongGap['absolute_gap'] = 1;
        self::assertSame(['optimality_gap_mismatch'], self::codes($wrongGap));
        self::assertSame(['score_mismatch', 'score_below_bound', 'optimality_bound_mismatch'], self::codes(self::GAP, static function (array $result): array {
            $result['score'][3] = 0;
            return $result;
        }));
        $claimed = self::GAP;
        $claimed['code'] = 'proven_optimal';
        self::assertSame(['result_fact_mismatch', 'unproven_optimality_claim'], self::codes($claimed, static function (array $result): array {
            $result['status'] = 'optimal';
            return $result;
        }));
    }
    public static function testUnrepresentableGapsAreWithheldAndRejectedWhenForged(): void
    {
        foreach ([0, 1] as $cheapCost) {
            $dimensions = ['length' => '10', 'width' => '10', 'height' => '10'];
            $request = [
                'items' => [['id' => 'i', 'quantity' => 2, 'dimensions' => $dimensions]],
                'containers' => [
                    ['id' => 'cheap', 'inner_dimensions' => ['length' => '1', 'width' => '1', 'height' => '1'], 'cost_minor' => $cheapCost, 'max_items' => 1],
                    ['id' => 'big', 'inner_dimensions' => $dimensions, 'cost_minor' => 9007199254740991, 'max_items' => 1],
                ],
            ];
            $result = ArrayCodec::pack($request);
            self::assertSame(['code' => 'not_proven'], $result['optimality']);
            self::assertSame([], validate($request, $result));
            $absolute = 18014398509481982 - 2 * $cheapCost;
            $result['optimality'] = ['code' => 'not_proven', 'lower_bound' => [0, 2, 2 * $cheapCost], 'gap_key' => 2, 'absolute_gap' => $absolute];
            if ($cheapCost !== 0) {
                $result['optimality']['relative_gap'] = ['numerator' => $absolute, 'denominator' => 2 * $cheapCost];
            }
            self::assertTrue(\in_array('optimality_gap_mismatch', \array_map(static fn(ResultIssue $issue): string => $issue->code, validate($request, $result)), true));
        }
    }

    public static function testTheGapCeilingIsInclusive(): void
    {
        foreach ([0, 1] as $least) {
            $bound = [0, 1, $least, 0, 0];
            $statement = \Packvium\Algorithm\ObjectiveBounds::statement([0, 1, $least + 9007199254740991, 0, 0], $bound);
            self::assertSame(9007199254740991, $statement['absolute_gap']);
            self::assertSame(null, \Packvium\Algorithm\ObjectiveBounds::statement([0, 1, $least + 9007199254740992, 0, 0], $bound));
        }
    }

}
