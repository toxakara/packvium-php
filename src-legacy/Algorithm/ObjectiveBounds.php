<?php
declare(strict_types=1);
namespace Packvium\Algorithm;

use Packvium\Constraint\VolumeReserve;
use Packvium\Domain\Container;
use Packvium\Domain\Item;
use Packvium\Domain\ItemInstance;
use Packvium\Domain\ShapeType;
use Packvium\Support\BigInt;

/**
 * Lower bounds on the objective vector, and the gap against an incumbent.
 *
 * The mathematics is fixed by docs/OPTIMALITY-CERTIFICATES.md. This is a port of the Python
 * implementation and is held to byte-identical results on the shared corpus, which
 * is what `conformance/scene/objective-bounds.json` exists to prove: Python writes the
 * vectors, this engine recomputes them from the same fixtures, and any divergence fails.
 *
 * Arithmetic. A container's volume in ticks passes a 64-bit integer well before it reaches a
 * realistic size -- a one-metre cube is 4.1e21 cubic ticks -- so every volume here is a
 * decimal string through `BigInt`, exactly as `VolumeReserve` and `Dimensions::volumeString`
 * already are. Weights, capacities and selected costs are also summed as decimal strings;
 * counts and the two parts-per-million keys return to native integers only after the common
 * portable-result ceiling has been checked. This keeps the unsigned `BigInt` away from the
 * one place a negative intermediate appears without letting PHP promote an overflowing sum
 * to a float.
 *
 * Complexity. `O(n log n + c log c)` for `n` instances and `c` container types, the same as
 * the Python port: one sort of the volumes, one of the weights, one of the container types
 * by cost. The volume sort compares decimal strings through `BigInt::compare` rather than
 * PHP's default string order, where "9" would sort after "10".
 *
 * Which instances, and when. The bound is a property of the request: `Packer` computes it
 * once per pack through `forRequest`, over every instance the request declares -- fixed
 * placements included -- before the first solver runs. Refusal is the
 * one predicate of docs/OPTIMALITY-CERTIFICATES.md, the same in every engine.
 *
 * Namespace. `Packvium\Algorithm` is one of the three namespaces `spec/api/php.json`
 * records as internal, and that is deliberate rather than incidental. A lower bound is
 * solver machinery, and nothing serializes it until the 1.8.0 result fields are emitted
 *. Filed first under the public `Packvium\Objective`, it added two classes to a
 * frozen public API for no caller outside the engine, which is a contract this project would
 * then owe forever. Python and JavaScript reached the same place by construction: neither
 * exports its bound.
 */
final class ObjectiveBounds
{
    /** Parts per million, the scale keys 3 and 4 of the objective are carried at. */
    public const PPM = 1000000;

    /**
     * Every sum in the bound path must stay below this, as a decimal string.
     *
     * A string because the ceiling itself is past a 64-bit integer -- which is the point.
     * Declared rather than inherited: this engine's native integers silently become doubles
     * on overflow, JavaScript's stop being exact past 2^53, Python's are unbounded and Rust's
     * `i128` wraps. If each refused at its own limit the four would disagree about which
     * requests are answerable. Keys 3 and 4 multiply a summed volume by `PPM`, so
     * `10^30 * 10^6` sits about 170-fold inside `i128`.
     *
     * Weights, costs and capacities are summed through `BigInt` here for the same reason: a
     * ceiling this engine cannot represent natively is a ceiling it cannot enforce natively.
     */
    public const MAX_BOUND_SUM = '1000000000000000000000000000000';

    /** Largest result integer every binding can carry without changing its value. */
    public const MAX_BOUND_VALUE = '9007199254740991';

    /**
     * The objectives a bound is stated for, and where each reads the five bounds: the
     * position in `compute`'s default-order vector of each of the objective's score keys.
     * `lowest_cost` scores cost ahead of the container count, and the document shows the
     * permuted vector is still a bound. Every other objective gets none.
     */
    public const OBJECTIVE_ORDERS = ObjectiveBoundScope::OBJECTIVE_ORDERS;

    /**
     * The request's bound in `$objective`'s own score order, or null where none is stated.
     *
     * Null for an objective with no bound, for a request with a `made_to_measure` container
     * (D13: the unused-volume key would read the machine maximum while the score
     * reads the fabricated box), and for a request past a declared ceiling. The last
     * withholds the bound and nothing else -- the pack is answered as it was before any
     * bound existed, rather than failing with `BoundOverflowException` (finding F5).
     *
     * @param list<ItemInstance> $instances every instance of the request, fixed ones included
     * @param list<Container> $containers
     * @return list<int>|null
     */
    public static function forRequest(array $instances, array $containers, string $objective): ?array
    {
        $order = ObjectiveBoundScope::order($containers, $objective);
        if ($order === null) {
            return null;
        }
        try {
            $vector = self::compute($instances, $containers);
        } catch (BoundOverflowException $refused) {
            return null;
        }
        return array_map(static function (int $position) use ($vector): int {
            return $vector[$position];
        }, $order);
    }

    /**
     * The `optimality` fields one result states against its request's bound
     * (OPTIMALITY-GAP.md § 1.1), or null where § 1.5 withholds them.
     *
     * `$bound` is in the result's own score order. The prefix runs through the first position
     * the score misses, or is the whole bound when nothing is missed -- the attained case,
     * which carries no gap at all rather than a zero one. A score below its bound means the
     * bound is wrong, and an engine must not publish a claim it has just refuted, so nothing
     * is stated. At most five comparisons, `O(1)`.
     *
     * @param list<int|float|string> $score
     * @param list<int> $bound
     * @return array<string,mixed>|null
     */
    public static function statement(array $score, array $bound): ?array
    {
        foreach ($bound as $index => $least) {
            $achieved = $score[$index];
            if ($achieved < $least) {
                return null;
            }
            if ($achieved > $least) {
                $difference = BigInt::subtract((string)$achieved, (string)$least);
                if (BigInt::compare($difference, self::MAX_BOUND_VALUE) > 0) {
                    return null;
                }
                $absolute = (int)$difference;
                $fields = [
                    'lower_bound' => array_slice($bound, 0, $index + 1),
                    'gap_key' => $index,
                    'absolute_gap' => $absolute,
                ];
                if ($least > 0) {
                    // Unreduced, so the pair reads as (score - bound, bound) by eye.
                    $fields['relative_gap'] = ['numerator' => $absolute, 'denominator' => $least];
                }
                return $fields;
            }
        }
        return ['lower_bound' => $bound];
    }

    /**
     * Refuse a sum past the declared ceiling instead of carrying it further.
     *
     * A structured refusal rather than a number: the alternative is the failure Baldacci et
     * al. document for floating-point bin-packing solvers, a bound that is quietly wrong and
     * carries no signal that it is.
     */
    private static function guard(string $total, string $quantity): string
    {
        if (BigInt::compare($total, self::MAX_BOUND_SUM) > 0) {
            throw new BoundOverflowException(
                "$quantity sums past the " . self::MAX_BOUND_SUM
                . ' ceiling the bound path declares; refusing rather than returning a number'
                . ' no engine can agree on',
            );
        }
        return $total;
    }

    /** A capacity total is guarded only when it is a number: an unbounded one is not. */
    private static function guardFinite(?string $total, string $quantity): ?string
    {
        return $total === null ? null : self::guard($total, $quantity);
    }

    /** Refuse a result before PHP or JavaScript can round it on a binding boundary. */
    private static function guardOutput(string $value, string $quantity): int
    {
        if (BigInt::compare($value, self::MAX_BOUND_VALUE) > 0) {
            throw new BoundOverflowException(
                "$quantity bound is $value, above the " . self::MAX_BOUND_VALUE
                . ' exact portable result ceiling',
            );
        }
        return (int)$value;
    }

    /**
     * Every bound for one request, in the default objective's key order.
     *
     * @param list<ItemInstance> $instances quantity-expanded, free and fixed alike
     * @param list<Container> $containers the types the request permits
     * @return array{int,int,int,int,int}
     */
    public static function compute(array $instances, array $containers): array
    {
        $normalisedInstances = [];
        foreach ($instances as $instance) {
            $normalisedInstances[] = [
                'volume' => $instance->item->dimensions->volumeString(),
                'weight' => $instance->item->weight->ticks,
                'shrinks' => self::occupiesLessThanItsBox($instance->item),
            ];
        }
        $normalisedContainers = [];
        foreach ($containers as $container) {
            $normalisedContainers[] = [
                'usable' => VolumeReserve::usable($container),
                'inner' => $container->innerDimensions->volumeString(),
                'base_area' => $container->innerDimensions->baseAreaTicks(),
                'height' => $container->innerDimensions->height->ticks,
                'payload' => ($nullsafeVariable1 = $container->maxPayload) ? $nullsafeVariable1->ticks : null,
                'max_items' => $container->maxItems,
                'quantity' => $container->quantity,
                'cost_minor' => $container->costMinor,
            ];
        }
        return self::fromNormalised($normalisedInstances, $normalisedContainers);
    }

    /**
     * The same bounds, from the numbers the formulas actually consume.
     *
     * This is the shape `conformance/scene/objective-bounds.json` records, so that a port
     * can be held to Python's vectors without first reimplementing a request parser. It
     * takes `shrinks` as given rather than deriving it: whether this engine decides that
     * flag correctly for a `convex_hull` is asserted separately, in this engine's own tests,
     * so a port cannot pass the arithmetic check by copying a flag it never computes.
     *
     * @param list<array{volume:string,weight:int,shrinks:bool}> $instances
     * @param list<array{usable:string,inner:string,base_area:int,height:int,payload:?int,max_items:?int,quantity:?int,cost_minor:int}> $containers
     * @return array{int,int,int,int,int}
     */
    public static function fromNormalised(array $instances, array $containers): array
    {
        $volumes = [];
        $weights = [];
        $shrinks = false;
        foreach ($instances as $instance) {
            $volumes[] = $instance['volume'];
            $weights[] = $instance['weight'];
            $shrinks = $shrinks || $instance['shrinks'];
        }
        self::sortVolumes($volumes);
        sort($weights);
        $count = count($instances);

        $usable = array_map(static function (array $c): string {
            return $c['usable'];
        }, $containers);
        $inner = array_map(static function (array $c): string {
            return $c['inner'];
        }, $containers);
        $areas = array_map(static function (array $c): int {
            return $c['base_area'];
        }, $containers);
        $heights = array_map(static function (array $c): int {
            return $c['height'];
        }, $containers);
        $payloads = array_map(static function (array $c): ?int {
            return $c['payload'];
        }, $containers);
        $slots = array_map(static function (array $c): ?int {
            return $c['max_items'];
        }, $containers);
        $quantities = array_map(static function (array $c): ?int {
            return $c['quantity'];
        }, $containers);
        $costs = array_map(static function (array $c): int {
            return $c['cost_minor'];
        }, $containers);

        // The one refusal predicate, on the way in: the two instance sums and every
        // *finite* capacity total. Every later product is bounded by these times `PPM`, so
        // guarding them here makes the rest safe by derivation. Nothing the formulas never
        // form is guarded -- no single type's capacity or cost, no total an unlimited type
        // makes unbounded -- and the shapes are never read, so the same request is refused
        // in every engine. The five results are held to the portable ceiling on the way out.
        self::guard(self::sumBig($volumes), 'instance volume');
        self::guard(self::sumBig(array_map(\Closure::fromCallable('strval'), $weights)), 'instance weight');
        $capacities = [
            'volume' => self::guardFinite(self::volumeCapacity($usable, $quantities), 'volume capacity'),
            'payload' => self::guardFinite(self::nativeCapacity($payloads, $quantities), 'payload capacity'),
            'slots' => self::guardFinite(self::nativeCapacity($slots, $quantities), 'slot capacity'),
        ];

        $unpacked = self::unpackedBound($volumes, $weights, $shrinks, $count, $capacities);
        $placed = $count - $unpacked;
        $opened = self::containerBound($volumes, $weights, $shrinks, $placed, $containers,
            $usable, $payloads, $slots);
        $cost = self::costBound($costs, $quantities, $opened);
        $unused = self::unusedVolumeBound($volumes, $shrinks, $placed, $inner, $opened);
        $height = self::stackHeightBound($volumes, $shrinks, $placed, $areas, $heights, $opened);
        return [
            self::guardOutput((string)$unpacked, 'unpacked count'),
            self::guardOutput((string)$opened, 'container count'),
            self::guardOutput((string)$cost, 'opening cost'),
            self::guardOutput((string)$unused, 'unused volume'),
            self::guardOutput((string)$height, 'stack height'),
        ];
    }

    /**
     * Can this item take up less room than its declared dimensions?
     *
     * Three ways, and each breaks the same argument -- that nominal volumes sum to something
     * a solution must carry. A nested item sinks into the one below it; a `convex_hull`
     * occupies its hull and leaves the rest of its bounding box free; a `compressible` item
     * gives up height under load.
     *
     * The design document named only the first until found the omission with a
     * soundness test. Asking the question once, here, is what stops a future fourth shape
     * from reintroducing the same unsoundness silently.
     */
    private static function occupiesLessThanItsBox(Item $item): bool
    {
        if ($item->nestingHeight !== null) {
            return true;
        }
        return $item->shapeType === ShapeType::CONVEX_HULL
            || $item->shapeType === ShapeType::COMPRESSIBLE;
    }

    /**
     * `L0`: remove the geometry entirely and ask what the declared resources alone forbid.
     *
     * @param list<string> $volumes ascending
     * @param list<int> $weights ascending
     * @param array{volume:?string,payload:?string,slots:?string} $capacities null when unbounded
     */
    private static function unpackedBound(array $volumes, array $weights, bool $shrinks,
        int $count, array $capacities): int
    {
        $placeable = $count;
        if (!$shrinks) {
            $placeable = min($placeable, self::fitBig($volumes, $capacities['volume']));
        }
        $placeable = min($placeable, self::fitNative($weights, $capacities['payload']));
        $slotCapacity = $capacities['slots'];
        if ($slotCapacity !== null) {
            $placeable = min($placeable,
                BigInt::compare($slotCapacity, (string)$count) >= 0 ? $count : (int)$slotCapacity);
        }
        return $count - $placeable;
    }

    /**
     * `L1`: grant every container the largest capacity available, which can only understate
     * how many are needed.
     *
     * @param list<string> $volumes ascending
     * @param list<int> $weights ascending
     * @param list<Container> $containers
     * @param list<string> $usable
     * @param list<?int> $payloads
     * @param list<?int> $slots
     */
    private static function containerBound(array $volumes, array $weights, bool $shrinks,
        int $placed, array $containers, array $usable, array $payloads, array $slots): int
    {
        if ($placed <= 0 || $containers === []) {
            return 0;
        }
        $bound = 1;
        if (!$shrinks) {
            $largest = self::maxBig($usable);
            if (BigInt::compare($largest, '0') > 0) {
                $totalVolume = self::sumBig(array_slice($volumes, 0, $placed));
                $needed = BigInt::compare($totalVolume, '0') === 0 ? '0' : BigInt::divide(
                    BigInt::add(BigInt::subtract($totalVolume, '1'), $largest),
                    $largest,
                );
                $bound = max($bound, (int)$needed);
            }
        }
        $largestPayload = self::finiteMax($payloads);
        if ($largestPayload !== null && $largestPayload > 0) {
            $totalWeight = self::sumBig(array_map(\Closure::fromCallable('strval'), array_slice($weights, 0, $placed)));
            $needed = BigInt::compare($totalWeight, '0') === 0 ? '0' : BigInt::divide(
                BigInt::add(BigInt::subtract($totalWeight, '1'), (string)$largestPayload),
                (string)$largestPayload,
            );
            $bound = max($bound, (int)$needed);
        }
        $largestSlots = self::finiteMax($slots);
        if ($largestSlots !== null && $largestSlots > 0) {
            $bound = max($bound, intdiv($placed + $largestSlots - 1, $largestSlots));
        }
        return $bound;
    }

    /**
     * `L2`: at least `L1` containers open, each costing at least the cheapest the inventory
     * still holds.
     *
     * The types are sorted by cost once and taken greedily, `min(quantity, still needed)` of
     * each: the sum of the `L1` cheapest per-unit entries without building that multiset,
     * `O(c log c)` rather than the `O(c * L1 log(c * L1))` of materialising and sorting up to
     * `L1` copies of every type (finding F6). Ties in cost cannot change the sum, so the
     * order among them does not matter.
     *
     * @param list<int> $costs
     * @param list<?int> $quantities
     */
    private static function costBound(array $costs, array $quantities, int $opened): int
    {
        if ($opened <= 0) {
            return 0;
        }
        $cheapestFirst = array_keys($costs);
        usort($cheapestFirst, static function (int $a, int $b) use ($costs): int {
            return $costs[$a] <=> $costs[$b];
        });
        $needed = $opened;
        $selected = '0';
        foreach ($cheapestFirst as $index) {
            if ($needed <= 0) {
                break;
            }
            $quantity = $quantities[$index];
            $taken = $quantity === null ? $needed : min($quantity, $needed);
            $selected = BigInt::add($selected, BigInt::multiply((string)$costs[$index], (string)$taken));
            $needed -= $taken;
        }
        return self::guardOutput($selected, 'opening cost');
    }

    /**
     * `L3`: the fill is largest when every container is the smallest available and holds the
     * greatest volume that could be placed at all.
     *
     * @param list<string> $volumes ascending
     * @param list<string> $inner
     */
    private static function unusedVolumeBound(array $volumes, bool $shrinks, int $placed,
        array $inner, int $opened): int
    {
        if ($shrinks || $opened <= 0 || $inner === []) {
            return 0;
        }
        $smallest = self::minBig($inner);
        if (BigInt::compare($smallest, '0') <= 0) {
            return 0;
        }
        $largestPlaced = $placed > 0
            ? self::sumBig(array_slice($volumes, count($volumes) - $placed))
            : '0';
        // The ratio is a parts-per-million figure once divided, so it returns to a native
        // integer before the only subtraction -- `BigInt` is unsigned and the result here can
        // legitimately fall below zero on the way to being clamped.
        $filled = BigInt::compare($largestPlaced, '0') === 0 ? '0' : BigInt::divide(
            BigInt::add(
                BigInt::subtract(BigInt::multiply($largestPlaced, (string)self::PPM), '1'),
                $smallest,
            ),
            $smallest,
        );
        $maximum = $opened * self::PPM - ($opened - 1);
        if (BigInt::compare($filled, (string)$maximum) >= 0) {
            return 0;
        }
        return self::guardOutput(
            BigInt::subtract((string)$maximum, $filled),
            'unused volume',
        );
    }

    /**
     * `L4`: the volume that must be placed has to stand at least as tall as itself spread
     * across the widest floor available, in the tallest container available.
     *
     * The `L1 - 1` correction is the exact worst-case difference between a sum of floors and
     * the floor of a sum, not a cautionary fudge: the objective floors each container's ratio
     * before summing.
     *
     * @param list<string> $volumes ascending
     * @param list<int> $areas
     * @param list<int> $heights
     */
    private static function stackHeightBound(array $volumes, bool $shrinks, int $placed,
        array $areas, array $heights, int $opened): int
    {
        if ($shrinks || $opened <= 0 || $areas === []) {
            return 0;
        }
        $widest = max($areas);
        $tallest = max($heights);
        if ($widest <= 0 || $tallest <= 0) {
            return 0;
        }
        $required = '0';
        if ($placed > 0) {
            $total = self::sumBig(array_slice($volumes, 0, $placed));
            if (BigInt::compare($total, '0') > 0) {
                $required = BigInt::divide(
                    BigInt::add(BigInt::subtract($total, '1'), (string)$widest),
                    (string)$widest,
                );
            }
        }
        $ratio = BigInt::divide(
            BigInt::multiply($required, (string)self::PPM),
            (string)$tallest,
        );
        $correction = (string)($opened - 1);
        if (BigInt::compare($ratio, $correction) <= 0) {
            return 0;
        }
        return self::guardOutput(BigInt::subtract($ratio, $correction), 'stack height');
    }

    /**
     * `Sum of usable * quantity`, or null for an unbounded total.
     *
     * A container with no usable volume adds nothing however many of it there are, which is
     * why an unlimited quantity only makes the total unbounded when the type actually holds
     * something.
     *
     * @param list<string> $usable
     * @param list<?int> $quantities
     */
    private static function volumeCapacity(array $usable, array $quantities): ?string
    {
        $total = '0';
        foreach ($usable as $index => $value) {
            $quantity = $quantities[$index];
            if ($quantity === null) {
                if (BigInt::compare($value, '0') > 0) {
                    return null;
                }
                continue;
            }
            $total = BigInt::add($total, BigInt::multiply($value, (string)$quantity));
        }
        return $total;
    }

    /**
     * `Sum of limit * quantity`, or null when any limit or inventory is undeclared.
     *
     * @param list<?int> $values
     * @param list<?int> $quantities
     */
    private static function nativeCapacity(array $values, array $quantities): ?string
    {
        $total = '0';
        foreach ($values as $index => $value) {
            if ($value === null) {
                return null;
            }
            $quantity = $quantities[$index];
            if ($quantity === null) {
                if ($value > 0) {
                    return null;
                }
                continue;
            }
            $total = BigInt::add($total, BigInt::multiply((string)$value, (string)$quantity));
        }
        return $total;
    }

    /**
     * Ascending decimal strings, in numeric order.
     *
     * PHP's own string order is not an option, where "9" would sort after "10". When every
     * volume fits a native integer -- a 10 mm cube is 4.1e15 cubic ticks -- they are sorted
     * as integers in C and written back as the same decimal strings. Measured on 51,200
     * volumes: 17 ms, against 78 ms for the comparator callback and 182 ms for
     * `SORT_NUMERIC`, which re-parses both strings on every comparison. Only a volume wider
     * than a native integer needs the `BigInt` comparator.
     *
     * @param list<string> $volumes
     */
    private static function sortVolumes(array &$volumes): void
    {
        $widest = $volumes === [] ? 0 : max(array_map(\Closure::fromCallable('strlen'), $volumes));
        if ($widest <= 18) {
            $native = array_map(\Closure::fromCallable('intval'), $volumes);
            sort($native);
            $volumes = array_map(\Closure::fromCallable('strval'), $native);
            return;
        }
        usort($volumes, static function (string $a, string $b): int {
            if (strlen($a) <= 18 && strlen($b) <= 18) {
                return (int)$a <=> (int)$b;
            }
            return BigInt::compare($a, $b);
        });
    }

    /**
     * The largest `n` such that the `n` smallest values sum to at most the capacity.
     *
     * The running total is `$flushed + $native`: native additions until one more would
     * overflow, then one `BigInt` addition that folds the chunk in. The capacity test stays
     * native too, against the room the capacity leaves above `$flushed`, so a long run of
     * small values costs native work rather than a string addition and comparison each.
     *
     * @param list<string> $ascending
     */
    private static function fitBig(array $ascending, ?string $capacity): int
    {
        if ($capacity === null) {
            return count($ascending);
        }
        $flushed = '0';
        $native = 0;
        $room = self::nativeRoom($capacity, $flushed);
        foreach ($ascending as $taken => $value) {
            if (strlen($value) > 18 || $native > PHP_INT_MAX - (int)$value) {
                $flushed = BigInt::add(BigInt::add($flushed, (string)$native), $value);
                $native = 0;
                if (BigInt::compare($flushed, $capacity) > 0) {
                    return $taken;
                }
                $room = self::nativeRoom($capacity, $flushed);
                continue;
            }
            $native += (int)$value;
            if ($room !== null && $native > $room) {
                return $taken;
            }
        }
        return count($ascending);
    }

    /**
     * `capacity - flushed` as a native integer, or null when it exceeds every native total.
     *
     * The caller guarantees `flushed <= capacity`.
     */
    private static function nativeRoom(string $capacity, string $flushed): ?int
    {
        $room = BigInt::subtract($capacity, $flushed);
        return BigInt::compare($room, (string)PHP_INT_MAX) <= 0 ? (int)$room : null;
    }

    /** @param list<int> $ascending */
    private static function fitNative(array $ascending, ?string $capacity): int
    {
        return self::fitBig(array_map(\Closure::fromCallable('strval'), $ascending), $capacity);
    }

    /**
     * The largest declared limit, or null if any type declares none.
     *
     * One unlimited type makes the maximum unbounded and every term conditioned on it
     * vacuous, which is why this collapses to null rather than ignoring the gap.
     *
     * @param list<?int> $values
     */
    private static function finiteMax(array $values): ?int
    {
        $best = null;
        foreach ($values as $value) {
            if ($value === null) {
                return null;
            }
            $best = $best === null ? $value : max($best, $value);
        }
        return $best;
    }

    /**
     * Sum decimal strings, staying on native integers for as long as that is exact.
     *
     * The same trade `HullShape::axisFitsNatively` makes, and for the same reason: string
     * arithmetic is exact and slow, so it should be reached for when it is needed rather
     * than always. A single volume fits a 64-bit integer comfortably -- a 10 mm cube is
     * 4.1e15 cubic ticks -- and only the running total leaves that range, at around two
     * thousand such items. So values are added natively in chunks, and a chunk is folded
     * into the `BigInt` total only when one more addend would overflow it: one string
     * addition per ~2^63 of total rather than one per value past the first overflow, which
     * is what the bound computed on every pack pays at scale.
     *
     * The exactness is unchanged: a chunk is folded before the value that would wrap it is
     * added, and a value too wide for `(int)` goes straight to `BigInt`.
     *
     * @param list<string> $values
     */
    private static function sumBig(array $values): string
    {
        $total = '0';
        $native = 0;
        foreach ($values as $value) {
            if (strlen($value) > 18) {
                $total = BigInt::add($total, $value);
                continue;
            }
            $addend = (int)$value;
            if ($native > PHP_INT_MAX - $addend) {
                $total = BigInt::add($total, (string)$native);
                $native = 0;
            }
            $native += $addend;
        }
        return BigInt::add($total, (string)$native);
    }

    /** @param list<string> $values */
    private static function maxBig(array $values): string
    {
        $best = '0';
        foreach ($values as $value) {
            if (BigInt::compare($value, $best) > 0) {
                $best = $value;
            }
        }
        return $best;
    }

    /** @param list<string> $values */
    private static function minBig(array $values): string
    {
        $best = null;
        foreach ($values as $value) {
            if ($best === null || BigInt::compare($value, $best) < 0) {
                $best = $value;
            }
        }
        return $best ?? '0';
    }
}
