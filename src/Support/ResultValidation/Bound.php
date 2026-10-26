<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

use Packvium\Validation\ResultIssue;

/**
 * The optimality bound and gap of docs/OPTIMALITY-GAP.md § 6.2, recomputed from the request
 *: `optimality.lower_bound` is the recomputed prefix, the gap agrees with it and the
 * score, no score falls below it, and `optimal` / `proven_optimal` are claimed only by attainment.
 *
 * The reference's own copy of docs/OPTIMALITY-CERTIFICATES.md's formulas and its one refusal
 * predicate, shared with nothing in the PHP engine (`Algorithm\ObjectiveBounds` is what it checks).
 * Exact at any size through `Exact`. O(t log t + c log c) for `t` item types and `c` container
 * types, O(t + c) memory, once per result.
 */
final class Bound
{
    /** Where each covered objective reads `(L0, L1, L2, L3, L4)` in its own score order. */
    private const ORDERS = ['default' => [0, 1, 2, 3, 4], 'lowest_cost' => [0, 2, 1, 3, 4]];
    private const MAX_BOUND_SUM = '1000000000000000000000000000000';
    private const MAX_BOUND_VALUE = 9007199254740991;
    private const SCORE_SCALE = 1000000;
    private const GAP_FIELDS = ['gap_key', 'absolute_gap', 'relative_gap'];

    /**
     * The reference's `_check_optimality_bound`. Absence of the field is never an issue.
     *
     * @param mixed $request @param mixed $result @param Ordered<ItemSpec> $items
     * @param Ordered<ContainerSpec> $containers @param list<int|string> $score @param list<ResultIssue> $issues
     */
    public static function check($request, $result, Ordered $items, Ordered $containers, array $score, array &$issues): void
    {
        $fact = Json::at($result, 'optimality');
        [$bound, $withheld] = self::stated($request, Json::at($result, 'status'), $items, $containers);
        $departure = $bound === null ? null : self::departure($score, $bound);
        $below = $departure !== null && $departure[1] < 0;
        if ($below) {
            $key = $departure[0];
            $issues[] = new ResultIssue('score_below_bound', "score[{$key}] is {$score[$key]}, below its lower bound {$bound[$key]}");
        }
        $emitted = Json::get($fact, 'lower_bound');
        if ($emitted !== null && $bound !== null && $departure !== null
            && Exact::compare(Exact::sub($score[$departure[0]], $bound[$departure[0]]), self::MAX_BOUND_VALUE) > 0) {
            $issues[] = new ResultIssue('optimality_gap_mismatch', 'the gap exceeds the portable result ceiling');
        }
        $counts = $emitted === null ? null : self::wellFormed($emitted);
        if ($emitted === null) {
            if (self::reportsGap($fact)) {
                $issues[] = new ResultIssue('optimality_gap_mismatch', 'a gap is reported without lower_bound');
            }
        } elseif ($counts === null) {
            $issues[] = new ResultIssue('optimality_bound_mismatch', 'lower_bound must be a list of 1 to 5 non-negative integers');
        } elseif ($bound === null) {
            $issues[] = new ResultIssue('optimality_bound_mismatch', "lower_bound is reported where no bound is stated: {$withheld}");
        } elseif ($below) {
            $issues[] = new ResultIssue('optimality_bound_mismatch', 'lower_bound is reported for a score below the recomputed bound');
        } else {
            self::checkReported($counts, $score, $bound, $departure, $fact, $issues);
        }
        $attained = $bound !== null && $departure === null;
        self::checkClaims($result, $fact, $attained, $counts !== null && self::same($counts, $score), $issues);
    }

    /**
     * `[L0, L1, L2, L3, L4]`, or null where the one refusal predicate withholds the bound. Public so
     * the shared scene can hold it to the oracle.
     *
     * @param list<array{0:int|string,1:int|string}> $volumes value runs sorted ascending
     * @param list<array{0:int|string,1:int|string}> $weights
     * @param list<array<string,int|string|null>> $containers
     * @return list<int|string>|null
     */
    public static function lowerBound(array $volumes, array $weights, bool $shrinks, array $containers): ?array
    {
        $quantities = self::column($containers, 'quantity');
        $guarded = [
            self::sumRuns($volumes),
            self::sumRuns($weights),
            self::capacityTotal(self::column($containers, 'usable'), $quantities),
            self::capacityTotal(self::column($containers, 'payload'), $quantities),
            self::capacityTotal(self::column($containers, 'max_items'), $quantities),
        ];
        foreach ($guarded as $total) {
            if ($total !== null && Exact::compare($total, self::MAX_BOUND_SUM) > 0) {
                return null;
            }
        }
        $count = self::runCount($volumes);
        $placeable = 0;
        if ($containers !== []) {
            $placeable = $count;
            if (!$shrinks) {
                $placeable = self::min($placeable, self::fit($volumes, $guarded[2]));
            }
            $placeable = self::min($placeable, self::fit($weights, $guarded[3]));
            if ($guarded[4] !== null) {
                $placeable = self::min($placeable, $guarded[4]);
            }
        }
        $opened = self::containerBound($volumes, $weights, $shrinks, $placeable, $containers);
        $bound = [
            Exact::sub($count, $placeable),
            $opened,
            self::costBound($containers, $opened),
            self::unusedBound($volumes, $shrinks, $placeable, $containers, $opened),
            self::heightBound($volumes, $shrinks, $placeable, $containers, $opened),
        ];
        foreach ($bound as $value) {
            if (Exact::compare($value, self::MAX_BOUND_VALUE) > 0) {
                return null;
            }
        }
        return $bound;
    }

    /**
     * `[value, count]` pairs sorted by value: one per item type, however large its quantity.
     *
     * @param list<array{0:int|string,1:int|string}> $pairs @return list<array{0:int|string,1:int|string}>
     */
    public static function runs(array $pairs): array
    {
        \usort($pairs, static function (array $left, array $right): int { return Exact::compare($left[0], $right[0]); });
        return $pairs;
    }

    /**
     * The bound in the objective's score order, or null with the reason none is stated (§ 1.5).
     *
     * @param mixed $request @param mixed $status @param Ordered<ItemSpec> $items @param Ordered<ContainerSpec> $containers
     * @return array{0:list<int|string>|null,1:string}
     */
    private static function stated($request, $status, Ordered $items, Ordered $containers): array
    {
        $name = Json::get(Json::get($request, 'configuration', []), 'objective', 'default');
        if (!\is_string($name) || !\array_key_exists($name, self::ORDERS)) {
            return [null, 'objective ' . PyFormat::repr($name) . ' has no bound'];
        }
        foreach ($containers->values() as $spec) {
            if ($spec->madeToMeasure !== null) {
                return [null, 'a container is made to measure'];
            }
        }
        if (Json::isText($status, 'infeasible')) {
            return [null, "status is 'infeasible'"];
        }
        if (Json::isText($status, 'invalid_result')) {
            return [null, "status is 'invalid_result'"];
        }
        [$volumes, $weights, $shrinks, $types] = self::inputs($items, $containers);
        $bound = self::lowerBound($volumes, $weights, $shrinks, $types);
        if ($bound === null) {
            return [null, 'the request is past a declared ceiling'];
        }
        return [\array_map(static function (int $position) use ($bound) { return $bound[$position]; }, self::ORDERS[$name]), ''];
    }

    /**
     * The request as the bound reads it: every instance, fixed ones included, and every type.
     *
     * @param Ordered<ItemSpec> $items @param Ordered<ContainerSpec> $containers @return array{0:list<array{0:int|string,1:int|string}>,1:list<array{0:int|string,1:int|string}>,2:bool,3:list<array<string,int|string|null>>}
     */
    private static function inputs(Ordered $items, Ordered $containers): array
    {
        $volumes = [];
        $weights = [];
        $shrinks = false;
        foreach ($items->values() as $spec) {
            $quantity = Json::int($spec->quantity, 'quantity');
            $volumes[] = [Exact::mul(Exact::mul($spec->dimensions[0], $spec->dimensions[1]), $spec->dimensions[2]), $quantity];
            $weights[] = [$spec->weight, $quantity];
            $shrinks = $shrinks || ($quantity > 0 && ($spec->nestingHeight !== null
                || Json::isText($spec->shapeType, 'convex_hull') || Json::isText($spec->shapeType, 'compressible')));
        }
        $types = [];
        foreach ($containers->values() as $spec) {
            $volume = $spec->volume();
            $scaled = ContainerCheck::ratioPpm($spec->voidFillReserveRatio);
            $reserved = Exact::add(
                Exact::mul(Exact::floorDiv($volume, self::SCORE_SCALE), $scaled),
                Exact::floorDiv(Exact::mul(Exact::mod($volume, self::SCORE_SCALE), $scaled), self::SCORE_SCALE)
            );
            $types[] = [
                'inner_volume' => $volume,
                'base_area' => Exact::mul($spec->inner[0], $spec->inner[1]),
                'height' => $spec->inner[2],
                'usable' => Exact::sub($volume, $reserved),
                'cost_minor' => $spec->costMinor,
                'payload' => $spec->maxPayload,
                'max_items' => $spec->maxItems,
                'quantity' => $spec->quantity,
            ];
        }
        return [self::runs($volumes), self::runs($weights), $shrinks, $types];
    }

    /** @param list<array<string,int|string|null>> $containers @return list<int|string|null> */
    private static function column(array $containers, string $field): array
    {
        return \array_map(static function (array $container) use ($field) { return $container[$field]; }, $containers);
    }

    /** @param list<array{0:int|string,1:int|string}> $runs @return int|string */
    private static function sumRuns(array $runs)
    {
        $total = 0;
        foreach ($runs as [$value, $count]) {
            $total = Exact::add($total, Exact::mul($value, $count));
        }
        return $total;
    }

    /** @param list<array{0:int|string,1:int|string}> $runs @return int|string */
    private static function runCount(array $runs)
    {
        $total = 0;
        foreach ($runs as [, $count]) {
            $total = Exact::add($total, $count);
        }
        return $total;
    }

    /** @param int|string $left @param int|string $right @return int|string */
    private static function min($left, $right)
    {
        return Exact::compare($left, $right) <= 0 ? $left : $right;
    }

    /** @param list<int|string> $values @return int|string */
    private static function largest(array $values)
    {
        $largest = $values[0];
        foreach ($values as $value) {
            $largest = Exact::max($largest, $value);
        }
        return $largest;
    }

    /**
     * How many of the smallest values sum to at most `capacity`; all of them when unlimited.
     *
     * @param list<array{0:int|string,1:int|string}> $runs @param int|string|null $capacity @return int|string
     */
    private static function fit(array $runs, $capacity)
    {
        if ($capacity === null) {
            return self::runCount($runs);
        }
        if (Exact::sign($capacity) < 0) {
            return 0;
        }
        $taken = 0;
        $used = 0;
        foreach ($runs as [$value, $count]) {
            $room = Exact::sign($value) === 0 ? $count : self::min($count, Exact::floorDiv(Exact::sub($capacity, $used), $value));
            $taken = Exact::add($taken, $room);
            $used = Exact::add($used, Exact::mul($value, $room));
            if (Exact::compare($room, $count) < 0) {
                break;
            }
        }
        return $taken;
    }

    /** The sum of the `count` smallest values. @param list<array{0:int|string,1:int|string}> $runs @param int|string $count @return int|string */
    private static function smallest(array $runs, $count)
    {
        $total = 0;
        foreach ($runs as [$value, $available]) {
            if (Exact::sign($count) <= 0) {
                break;
            }
            $taken = self::min($available, $count);
            $total = Exact::add($total, Exact::mul($value, $taken));
            $count = Exact::sub($count, $taken);
        }
        return $total;
    }

    /**
     * `Σ value × quantity`, or null when unbounded; a type that holds nothing adds 0.
     *
     * @param list<int|string|null> $values @param list<int|string|null> $quantities @return int|string|null
     */
    private static function capacityTotal(array $values, array $quantities)
    {
        $total = 0;
        foreach ($values as $index => $value) {
            $quantity = $quantities[$index];
            if ($value === null) {
                return null;
            }
            if ($quantity === null) {
                if (Exact::sign($value) > 0) {
                    return null;
                }
                continue;
            }
            $total = Exact::add($total, Exact::mul($value, $quantity));
        }
        return $total;
    }

    /**
     * `L1`, given `L0`.
     *
     * @param list<array{0:int|string,1:int|string}> $volumes @param list<array{0:int|string,1:int|string}> $weights
     * @param int|string $placed @param list<array<string,int|string|null>> $containers @return int|string
     */
    private static function containerBound(array $volumes, array $weights, bool $shrinks, $placed, array $containers)
    {
        if (Exact::sign($placed) <= 0 || $containers === []) {
            return 0;
        }
        $bound = 1;
        $largestVolume = self::largest(self::column($containers, 'usable'));
        if (!$shrinks && Exact::sign($largestVolume) > 0) {
            $bound = Exact::max($bound, Exact::ceilDiv(self::smallest($volumes, $placed), $largestVolume));
        }
        $payloads = self::column($containers, 'payload');
        if (!\in_array(null, $payloads, true)) {
            $largestPayload = self::largest($payloads);
            if (Exact::sign($largestPayload) > 0) {
                $bound = Exact::max($bound, Exact::ceilDiv(self::smallest($weights, $placed), $largestPayload));
            }
        }
        $slots = self::column($containers, 'max_items');
        if (!\in_array(null, $slots, true)) {
            $largestSlots = self::largest($slots);
            if (Exact::sign($largestSlots) > 0) {
                $bound = Exact::max($bound, Exact::ceilDiv($placed, $largestSlots));
            }
        }
        return $bound;
    }

    /**
     * `L2`: the `opened` cheapest containers the inventory holds, greedily by cost (stable).
     *
     * @param list<array<string,int|string|null>> $containers @param int|string $opened @return int|string
     */
    private static function costBound(array $containers, $opened)
    {
        $order = \array_keys($containers);
        \usort($order, static function (int $left, int $right) use ($containers): int {
            return Exact::compare($containers[$left]['cost_minor'], $containers[$right]['cost_minor']) ?: $left <=> $right;
        });
        $total = 0;
        foreach ($order as $index) {
            if (Exact::sign($opened) <= 0) {
                break;
            }
            $quantity = $containers[$index]['quantity'];
            $taken = $quantity === null ? $opened : self::min($quantity, $opened);
            $total = Exact::add($total, Exact::mul($containers[$index]['cost_minor'], $taken));
            $opened = Exact::sub($opened, $taken);
        }
        return $total;
    }

    /**
     * `L3`.
     *
     * @param list<array{0:int|string,1:int|string}> $volumes @param int|string $placed
     * @param list<array<string,int|string|null>> $containers @param int|string $opened @return int|string
     */
    private static function unusedBound(array $volumes, bool $shrinks, $placed, array $containers, $opened)
    {
        $inner = self::positive(self::column($containers, 'inner_volume'));
        if (Exact::sign($opened) <= 0 || $shrinks || $inner === []) {
            return 0;
        }
        $smallestInner = $inner[0];
        foreach ($inner as $volume) {
            $smallestInner = self::min($smallestInner, $volume);
        }
        $filled = Exact::ceilDiv(Exact::mul(self::smallest(\array_reverse($volumes), $placed), self::SCORE_SCALE), $smallestInner);
        $unused = Exact::sub(Exact::sub(Exact::mul($opened, self::SCORE_SCALE), $filled), Exact::sub($opened, 1));
        return Exact::max(0, $unused);
    }

    /**
     * `L4`.
     *
     * @param list<array{0:int|string,1:int|string}> $volumes @param int|string $placed
     * @param list<array<string,int|string|null>> $containers @param int|string $opened @return int|string
     */
    private static function heightBound(array $volumes, bool $shrinks, $placed, array $containers, $opened)
    {
        $floors = self::positive(self::column($containers, 'base_area'));
        $heights = self::positive(self::column($containers, 'height'));
        if (Exact::sign($opened) <= 0 || $shrinks || $floors === [] || $heights === []) {
            return 0;
        }
        $required = Exact::ceilDiv(self::smallest($volumes, $placed), self::largest($floors));
        $aggregate = Exact::floorDiv(Exact::mul($required, self::SCORE_SCALE), self::largest($heights));
        return Exact::max(0, Exact::sub($aggregate, Exact::sub($opened, 1)));
    }

    /** @param list<int|string|null> $values @return list<int|string> */
    private static function positive(array $values): array
    {
        $kept = [];
        foreach ($values as $value) {
            if ($value !== null && Exact::sign($value) > 0) {
                $kept[] = $value;
            }
        }
        return $kept;
    }

    /**
     * The first position where `score` leaves `bound`, and whether it is above (1) or below (-1).
     *
     * @param list<int|string> $score @param list<int|string> $bound @return array{0:int,1:int}|null
     */
    private static function departure(array $score, array $bound): ?array
    {
        $shared = \min(\count($score), \count($bound));
        for ($index = 0; $index < $shared; ++$index) {
            $order = Exact::compare($score[$index], $bound[$index]);
            if ($order !== 0) {
                return [$index, $order];
            }
        }
        return null;
    }

    /** `lower_bound` as counts when it is a list of 1 to 5 non-negative integers. @param mixed $emitted @return list<int>|null */
    private static function wellFormed($emitted): ?array
    {
        if (!Json::isList($emitted) || \count($emitted) < 1 || \count($emitted) > 5) {
            return null;
        }
        foreach ($emitted as $value) {
            if (!\is_int($value) || $value < 0) {
                return null;
            }
        }
        return $emitted;
    }

    /** @param mixed $fact */
    private static function reportsGap($fact): bool
    {
        foreach (self::GAP_FIELDS as $name) {
            if (Json::get($fact, $name) !== null) {
                return true;
            }
        }
        return false;
    }

    /** @param list<int|string> $left @param list<int|string> $right */
    private static function same(array $left, array $right): bool
    {
        if (\count($left) !== \count($right)) {
            return false;
        }
        foreach ($left as $index => $value) {
            if (Exact::compare($value, $right[$index]) !== 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<int> $emitted @param list<int|string> $score @param list<int|string> $bound
     * @param array{0:int,1:int}|null $departure @param mixed $fact @param list<ResultIssue> $issues
     */
    private static function checkReported(array $emitted, array $score, array $bound, ?array $departure, $fact, array &$issues): void
    {
        $prefix = \array_slice($bound, 0, \count($emitted));
        if (!self::same($emitted, $prefix)) {
            $issues[] = new ResultIssue('optimality_bound_mismatch', 'reported ' . PyFormat::intList($emitted) . ', recomputed ' . PyFormat::intList($prefix));
        }
        $length = \count($bound);
        $expected = ['gap_key' => null, 'absolute_gap' => null, 'relative_gap' => null];
        if ($departure !== null) {
            $key = $departure[0];
            $absolute = Exact::sub($score[$key], $bound[$key]);
            $length = $key + 1;
            $expected = [
                'gap_key' => $key,
                'absolute_gap' => $absolute,
                'relative_gap' => Exact::sign($bound[$key]) === 0 ? null : [$absolute, $bound[$key]],
            ];
        }
        if (\count($emitted) !== $length) {
            $issues[] = new ResultIssue('optimality_gap_mismatch', 'lower_bound has ' . \count($emitted) . " entries, expected {$length}");
        }
        foreach ($expected as $name => $value) {
            if (!self::gapFieldMatches($name, Json::get($fact, $name), $value)) {
                $text = $value === null ? 'absent' : ($name === 'relative_gap' ? "{$value[0]}/{$value[1]}" : (string)$value);
                $issues[] = new ResultIssue('optimality_gap_mismatch', "{$name} should be {$text}");
            }
        }
    }

    /** @param mixed $actual @param int|string|array{0:int|string,1:int|string}|null $expected */
    private static function gapFieldMatches(string $name, $actual, $expected): bool
    {
        if ($expected === null) {
            return $actual === null;
        }
        if ($name !== 'relative_gap') {
            return \is_int($actual) && Exact::compare($actual, $expected) === 0;
        }
        return Json::isDict($actual) && \count($actual) === 2
            && \is_int(Json::get($actual, 'numerator')) && Exact::compare($actual['numerator'], $expected[0]) === 0
            && \is_int(Json::get($actual, 'denominator')) && Exact::compare($actual['denominator'], $expected[1]) === 0;
    }

    /** @param mixed $result @param mixed $fact @param list<ResultIssue> $issues */
    private static function checkClaims($result, $fact, bool $attained, bool $claimsAttainment, array &$issues): void
    {
        $status = Json::at($result, 'status');
        $complete = Json::truthy(Json::at($result, 'complete'));
        if (Json::isText($status, 'optimal') && !($complete && $attained)) {
            $issues[] = new ResultIssue('result_fact_mismatch', "status 'optimal' requires a complete result that attains the recomputed lower bound");
        }
        if (Json::isText($status, 'feasible') && $complete && $claimsAttainment) {
            $issues[] = new ResultIssue('result_fact_mismatch', "status is 'feasible', but the result attains its lower_bound; expected 'optimal'");
        }
        if (Json::isText(Json::get($fact, 'code'), 'proven_optimal') && !$claimsAttainment) {
            $issues[] = new ResultIssue('unproven_optimality_claim', "optimality.code is 'proven_optimal' without an attained lower_bound");
        }
    }
}
