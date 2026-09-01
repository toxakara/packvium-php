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
 * the Python port: one sort of the volumes, one of the weights, one of the per-unit costs.
 * The volume sort compares decimal strings through `BigInt::compare` rather than PHP's
 * default string order, where "9" would sort after "10".
 *
 * Namespace. `Packvium\Algorithm` is one of the three namespaces `spec/api/php.json`
 * records as internal, and that is deliberate rather than incidental. A lower bound is
 * solver machinery: `SolverOrchestrator` -- its only production caller, and already in this
 * namespace -- records it into `SearchStats`, and nothing serializes it. Filed first under
 * the public `Packvium\Objective`, it added two classes to a frozen public API for no
 * caller outside this namespace, which is a contract this project would then owe forever.
 * Python and JavaScript reached the same place by construction: neither exports its bound.
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
     * @param list<ItemInstance> $instances quantity-expanded, exactly as the solver sees them
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
                'payload' => $container->maxPayload?->ticks,
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
        // Native comparison while both sides fit one, `BigInt` only when they do not. The
        // sort is the dominant cost at scale -- ten thousand instances is about 133,000
        // comparisons -- and PHP's own string order is not an option here, where "9" would
        // sort after "10".
        usort($volumes, static function (string $a, string $b): int {
            if (strlen($a) <= 18 && strlen($b) <= 18) {
                return (int)$a <=> (int)$b;
            }
            return BigInt::compare($a, $b);
        });
        sort($weights);
        $count = count($instances);

        // The a-priori check, once, on the way in. Every later product is bounded by these
        // totals times `PPM`, so guarding them here is what makes the rest safe by derivation
        // rather than by hoping each step stays small.
        self::guard(self::sumBig($volumes), 'instance volume');
        self::guard(self::sumBig(array_map(strval(...), $weights)), 'instance weight');
        self::guard(
            self::sumBig(array_map(
                static fn(array $c): string => BigInt::multiply($c['usable'], (string)($c['quantity'] ?? 1)),
                $containers,
            )),
            'container capacity',
        );
        self::guard(
            self::sumBig(array_map(
                static fn(array $c): string => BigInt::multiply((string)$c['cost_minor'], (string)($c['quantity'] ?? 1)),
                $containers,
            )),
            'opening cost',
        );

        $usable = array_map(static fn(array $c): string => $c['usable'], $containers);
        $inner = array_map(static fn(array $c): string => $c['inner'], $containers);
        $areas = array_map(static fn(array $c): int => $c['base_area'], $containers);
        $heights = array_map(static fn(array $c): int => $c['height'], $containers);
        $payloads = array_map(static fn(array $c): ?int => $c['payload'], $containers);
        $slots = array_map(static fn(array $c): ?int => $c['max_items'], $containers);
        $quantities = array_map(static fn(array $c): ?int => $c['quantity'], $containers);
        $costs = array_map(static fn(array $c): int => $c['cost_minor'], $containers);

        $unpacked = self::unpackedBound($volumes, $weights, $shrinks, $count, $usable,
            $payloads, $slots, $quantities);
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
     * The design document named only the first until  found the omission with a
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
     * @param list<string> $usable
     * @param list<?int> $payloads
     * @param list<?int> $slots
     * @param list<?int> $quantities
     */
    private static function unpackedBound(array $volumes, array $weights, bool $shrinks,
        int $count, array $usable, array $payloads, array $slots, array $quantities): int
    {
        $placeable = $count;
        if (!$shrinks) {
            $volumeCapacity = self::volumeCapacity($usable, $quantities);
            $placeable = min($placeable, self::fitBig($volumes, $volumeCapacity));
        }
        $payloadCapacity = self::nativeCapacity($payloads, $quantities);
        $placeable = min($placeable, self::fitNative($weights, $payloadCapacity));
        $slotCapacity = self::nativeCapacity($slots, $quantities);
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
            $totalWeight = self::sumBig(array_map(strval(...), array_slice($weights, 0, $placed)));
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
     * @param list<int> $costs
     * @param list<?int> $quantities
     */
    private static function costBound(array $costs, array $quantities, int $opened): int
    {
        if ($opened <= 0) {
            return 0;
        }
        $available = [];
        foreach ($costs as $index => $cost) {
            $quantity = $quantities[$index];
            $repeat = $quantity === null ? $opened : min($quantity, $opened);
            for ($taken = 0; $taken < $repeat; $taken++) {
                $available[] = $cost;
            }
        }
        sort($available);
        $selected = self::sumBig(array_map(strval(...), array_slice($available, 0, $opened)));
        self::guard($selected, 'opening cost');
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
            self::guard($total, 'container capacity');
        }
        return $total;
    }

    /**
     * The largest `n` such that the `n` smallest values sum to at most the capacity.
     *
     * @param list<string> $ascending
     */
    private static function fitBig(array $ascending, ?string $capacity): int
    {
        if ($capacity === null) {
            return count($ascending);
        }
        $used = '0';
        foreach ($ascending as $taken => $value) {
            $used = BigInt::add($used, $value);
            if (BigInt::compare($used, $capacity) > 0) {
                return $taken;
            }
        }
        return count($ascending);
    }

    /** @param list<int> $ascending */
    private static function fitNative(array $ascending, ?string $capacity): int
    {
        if ($capacity === null) {
            return count($ascending);
        }
        $used = '0';
        foreach ($ascending as $taken => $value) {
            $used = BigInt::add($used, (string)$value);
            if (BigInt::compare($used, $capacity) > 0) {
                return $taken;
            }
        }
        return count($ascending);
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
     * 4.1e15 cubic ticks -- and only the running total can leave that range, at around two
     * thousand such items. So the loop adds natively until one more addend would overflow,
     * and only then switches to `BigInt` for the remainder.
     *
     * Measured on ten thousand instances: 459 ms before, and the exactness is unchanged --
     * the switch happens on the value that would have wrapped, not after it.
     *
     * @param list<string> $values
     */
    private static function sumBig(array $values): string
    {
        $native = 0;
        $index = 0;
        $count = count($values);
        for (; $index < $count; $index++) {
            $value = $values[$index];
            // A value too wide for a native integer, or a total that would pass one, ends the
            // fast path. `is_numeric` guards nothing here -- these are engine-produced decimal
            // strings -- but the width check is what keeps `(int)` from silently truncating.
            if (strlen($value) > 18) {
                break;
            }
            $addend = (int)$value;
            if ($native > PHP_INT_MAX - $addend) {
                break;
            }
            $native += $addend;
        }
        $total = (string)$native;
        for (; $index < $count; $index++) {
            $total = BigInt::add($total, $values[$index]);
        }
        return $total;
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
