<?php
/**
 * Load a delivery van for a three-stop route, within its axle ratings.
 *
 * Run it:
 *
 *     php examples/trucking.php
 *
 * A van is not a big box. Its payload rides on two axles, and each axle has its own rating
 * that the total payload limit does not protect: a van can be under its payload and still
 * overload the front axle. Its cargo also comes out through one door, in the order the
 * stops come up, so the goods for the first stop must not be walled in by the goods for
 * the last one.
 *
 * Three request fields carry that:
 *
 * - `axles` on the container: `[front, rear]`, each a position along the length and an
 *   optional maximum load. Checked for every placement against the gross load (payload
 *   plus tare), so no plan puts either axle over its rating.
 * - `accessDirections` on the container: the walls cargo can leave through. `+x` is the
 *   wall at the far end of the length axis -- here, the rear doors.
 * - `stopIndex` on each item: which stop it is delivered at, `0` first.
 *
 * After the solve, `Packvium\Sequence` answers the questions a driver asks: in what order
 * do things come off, in what order must they go on, and what can I reach when I open the
 * doors at the first stop?
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Algorithm\EffortBudget;
use Packvium\Config\{PackingConfig, SolverProfile};
use Packvium\Domain\{Axle, Container, Dimensions, Item, Rotation};
use Packvium\Packer;
use Packvium\Result\PackingResult;
use Packvium\Sequence\{LoadingDependencyGraph, RouteSequenceError, UnloadingDependencyGraph};
use Packvium\Unit\{Length, Weight};

const REAR_DOORS = ['+x'];

// Length runs from the cab bulkhead (x = 0) to the rear doors. The axle positions are
// measured along that same axis, which is what lets the engine take moments about them.
$frontAxle = new Axle(Length::mm('600'), Weight::parse('1650 kg'));
$rearAxle = new Axle(Length::mm('3500'), Weight::parse('2200 kg'));

function van(Axle $front, Axle $rear): Container
{
    return Container::create(
        'van', Dimensions::mm('4200', '1800', '1900'),
        // Tare matters here: axle ratings are gross limits, and the empty van already
        // puts most of the front rating to use before anything is loaded.
        tareWeight: '2400 kg', maxPayload: '1300 kg',
        axles: [$front, $rear], accessDirections: REAR_DOORS,
    );
}

/** @return list<Item> */
function manifest(bool $withRoute = true): array
{
    $stop = static fn(int $index): ?int => $withRoute ? $index : null;
    // Every orientation is offered and `keepUpright` narrows it. Naming the list keeps the
    // later named arguments valid for the PHP 7.3 build of this package.
    $any = [Rotation::LWH, Rotation::LHW, Rotation::WLH, Rotation::WHL, Rotation::HLW, Rotation::HWL];
    return [
        Item::create('bakery-rack', Dimensions::mm('800', '600', '1700'), '90 kg', 2, $any,
            keepUpright: true, stopIndex: $stop(0)),
        Item::create('drinks-pallet', Dimensions::mm('1200', '800', '1100'), '420 kg', 1, $any,
            keepUpright: true, mustBeOnFloor: true, stopIndex: $stop(1)),
        Item::create('parcel', Dimensions::mm('600', '400', '400'), '18 kg', 6, $any, stopIndex: $stop(2)),
        Item::create('dry-goods-pallet', Dimensions::mm('1200', '800', '1400'), '260 kg', 1, $any,
            keepUpright: true, stopIndex: $stop(2)),
    ];
}

// The effort budget decides where the search stops, so every run gives this same plan;
// the time limit is only a fuse far above what this needs (see reproducibility.php).
// `maxContainers: 1` because there is one van: anything that does not fit is reported,
// not sent in a second vehicle.
$config = new PackingConfig(
    SolverProfile::Balanced, timeLimitMs: 60000, maxContainers: 1,
    effortBudget: new EffortBudget(null, null, null, 8),
);

function section(string $title): void
{
    echo "\n", str_repeat('=', 78), "\n", $title, "\n", str_repeat('=', 78), "\n";
}

function millimetres(int $ticks): string
{
    return (new Length($ticks))->decimal('mm', 1);
}

/**
 * The exact axle reactions from the result document, shown in kilograms.
 *
 * They arrive as a numerator over a shared denominator so no engine has to round them;
 * rounding happens here, for display only. The numerator is a decimal string because
 * weight ticks times length ticks outgrows a 64-bit integer, so it is divided digit by
 * digit, then rounded half to even like every other value Packvium renders.
 *
 * @return array{0:string,1:string}
 */
function axleLoadsKg(PackingResult $result): array
{
    $reactions = $result->toArray()['containers'][0]['axle_reactions'];
    $denominator = (int)$reactions['denominator'];
    $kilograms = static function (string $numerator) use ($denominator): string {
        $quotient = 0;
        $remainder = 0;
        foreach (str_split($numerator) as $digit) {
            $remainder = $remainder * 10 + (int)$digit;
            $quotient = $quotient * 10 + intdiv($remainder, $denominator);
            $remainder %= $denominator;
        }
        $twice = 2 * $remainder;
        if ($twice > $denominator || ($twice === $denominator && $quotient % 2 === 1)) {
            $quotient++;
        }
        return (new Weight($quotient))->decimal('kg', 1);
    };
    return [$kilograms($reactions['front_numerator']), $kilograms($reactions['rear_numerator'])];
}

// --------------------------------------------------------------------------------------
section("1. The plan: nothing for a later stop stands in an earlier stop's way out");

$result = (new Packer($config))->pack(manifest(), [van($frontAxle, $rearAxle)]);
$packed = $result->containers[0];
$placements = $packed->placements;
printf("  status: %s, left behind: %d\n", $result->status->value, count($result->unpacked));
$byPosition = $placements;
usort($byPosition, static fn($a, $b): int => [$a->position->x, $a->position->y, $a->position->z]
    <=> [$b->position->x, $b->position->y, $b->position->z]);
foreach ($byPosition as $placement) {
    printf("    stop %d  %-20s x=%6s mm  y=%6s mm\n", $placement->instance->item->stopIndex,
        $placement->instance->id(), millimetres($placement->position->x), millimetres($placement->position->y));
}
echo "
  x is the distance from the bulkhead, so the rear doors are at x = 4200 mm. The
  bakery racks sit at the front, but the aisle beside them runs clear to the doors:
  the rule is that nothing due later blocks every way out, not that stop 0 is last in.
";

// --------------------------------------------------------------------------------------
section('2. Axle loads against their ratings');

[$front, $rear] = axleLoadsKg($result);
printf("  payload: %s kg of 1300 kg allowed\n", $packed->payloadWeight()->decimal('kg', 1));
printf("  front axle: %s kg of %s kg\n", $front, $frontAxle->maxLoad->decimal('kg', 1));
printf("  rear axle:  %s kg of %s kg\n", $rear, $rearAxle->maxLoad->decimal('kg', 1));

$tightAxle = new Axle(Length::mm('600'), Weight::parse('1450 kg'));
$tight = (new Packer($config))->pack(manifest(), [van($tightAxle, $rearAxle)]);
echo "\n  the same load with a 1450 kg front axle rating:\n";
foreach ($tight->unpacked as $unpacked) {
    printf("    left behind: %s, reason %s (%s)\n", $unpacked->instance->id(), $unpacked->reason, $unpacked->proof->level);
}
echo "
  The axle rule refuses a placement rather than reporting an overload afterwards.
  Note the reason: an item the axles cannot carry is reported as the generic
  `no_feasible_placement`, not as an axle problem, so when a load comes up short on
  a vehicle with axle ratings, check the reactions before shopping for a bigger van.
";

// --------------------------------------------------------------------------------------
section('3. Unloading order along the route, and the loading order it implies');

$boxes = array_map(static fn($placement) => $placement->envelopeBox(), $placements);
$stops = array_map(static fn($placement): ?int => $placement->instance->item->stopIndex, $placements);
$inside = $packed->container->innerDimensions;

$unloading = UnloadingDependencyGraph::safeRouteRemovalOrder($boxes, $stops, $inside, REAR_DOORS);
foreach ($unloading as $step => $index) {
    printf("    off %2d: stop %d  %s\n", $step + 1, $stops[$index], $placements[$index]->instance->id());
}

// Loading is unloading played backwards: whatever comes off last goes on first.
// `replayLoadingOrder` checks that independently -- every item supported by what is
// already in, and each one able to travel in through the doors -- and throws if not.
$loading = array_reverse($unloading);
LoadingDependencyGraph::replayLoadingOrder($boxes, $inside, $loading, REAR_DOORS);
echo "\n  load in this order: ",
    implode(', ', array_map(static fn(int $index): string => $placements[$index]->instance->id(), $loading)), "\n";

// --------------------------------------------------------------------------------------
section('4. What the driver can reach on opening the doors at stop 0');

foreach (UnloadingDependencyGraph::placementReachability($boxes, $inside, $stops, REAR_DOORS) as $reach) {
    if ($stops[$reach->index] !== 0) {
        continue;
    }
    $blockers = array_map(
        static fn(int $index): string => $placements[$index]->instance->id(),
        array_keys($reach->blockedByNeighbors + $reach->blockedBySupport)
    );
    sort($blockers);
    printf("    %s: reachable=%s%s\n", $placements[$reach->index]->instance->id(),
        $reach->reachable ? 'true' : 'false', $blockers === [] ? '' : ', behind ' . implode(', ', $blockers));
}
echo "
  Reachability is a snapshot, not a plan: one rack is behind the other, which is
  fine because both are for this stop and the first one comes off first.
";

// --------------------------------------------------------------------------------------
section('5. The same goods without `stopIndex`');

$unrouted = (new Packer($config))->pack(manifest(false), [van($frontAxle, $rearAxle)]);
$unroutedPlacements = $unrouted->containers[0]->placements;
$due = ['bakery-rack' => 0, 'drinks-pallet' => 1, 'parcel' => 2, 'dry-goods-pallet' => 2];
try {
    UnloadingDependencyGraph::safeRouteRemovalOrder(
        array_map(static fn($placement) => $placement->envelopeBox(), $unroutedPlacements),
        array_map(static fn($placement): int => $due[$placement->instance->item->id], $unroutedPlacements),
        $inside,
        REAR_DOORS
    );
} catch (RouteSequenceError $stuck) {
    $names = array_map(static fn(int $index): string => $unroutedPlacements[$index]->instance->id(), $stuck->stuck);
    sort($names);
    printf("  status %s, every item packed -- and at stop %d the driver cannot get these out: %s\n",
        $unrouted->status->value, $stuck->stop, implode(', ', $names));
}
echo "
  Without the route the plan is still a valid packing, and still within the axle
  ratings; it just cannot be delivered in order. The route is only enforced when
  you state it.
";
