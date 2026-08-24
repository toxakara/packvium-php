<?php
/**
 * Objectives: six ways to be "best", and the scenes where they disagree.
 *
 * Run it:
 *
 *     php examples/objectives.php
 *
 * Every solve returns the arrangement that scores best -- but "best" is a choice, and it
 * is the one setting most likely to make the library look wrong when it is merely
 * answering a different question than you meant to ask. This example builds scenes where
 * two objectives genuinely pick different containers, so the difference is visible
 * rather than asserted.
 *
 * The score is always a lexicographic vector of exact integers, never a float, and its
 * first key is always the unpacked count: no objective will ever leave an item behind to
 * save money. See docs/OBJECTIVE.md for the full key ordering.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container, Dimensions, Item, RateTable};
use Packvium\Domain\UnratedWeightException;
use Packvium\Packer;

$widgets = [Item::create('widget', Dimensions::mm('100', '100', '100'), '500 g', quantity: 8)];

$solve = static function (PackingConfig $config, array $containers) use ($widgets): string {
    $result = (new Packer($config))->pack($widgets, $containers);
    $chosen = $result->containers === [] ? 'none' : $result->containers[0]->container->id;
    return sprintf('%-6s score=%s', $chosen, json_encode($result->score));
};

// -----------------------------------------------------------------------------------
// `default` -- fewest containers, then tightest fit. What you want when the boxes are
// interchangeable and you are simply trying not to open another one.
// -----------------------------------------------------------------------------------
$snug = Container::create('snug', Dimensions::mm('300', '300', '300'), maxPayload: '20 kg', costMinor: 500);
$roomy = Container::create('roomy', Dimensions::mm('400', '400', '400'), maxPayload: '20 kg', costMinor: 150);

printf("%-18s %s\n", 'default', $solve(PackingConfig::balanced(), [$snug, $roomy]));

// -----------------------------------------------------------------------------------
// `lowest_cost` -- the cheapest *packaging*. `costMinor` is what the box costs you, so
// this is the objective for a warehouse buying cartons, not for a shipper paying a
// carrier.
// -----------------------------------------------------------------------------------
printf("%-18s %s\n", 'lowest_cost', $solve(new PackingConfig(objective: 'lowest_cost'), [$snug, $roomy]));

// -----------------------------------------------------------------------------------
// `shipping_cost` -- carrier-billable *weight*: the greater of actual gross weight and
// dimensional weight. A big light box can bill more than a small heavy one, which is why
// this is not the same objective as `lowest_cost`.
//
// It needs a divisor, and refuses rather than guessing one: a wrong divisor silently
// misprices every shipment.
// -----------------------------------------------------------------------------------
$byWeight = new PackingConfig(
    objective: 'shipping_cost',
    dimensionalWeightDivisor: 5000,
    dimensionalWeightLengthUnit: 'cm',
    dimensionalWeightWeightUnit: 'kg',
);
printf("%-18s %s\n", 'shipping_cost', $solve($byWeight, [$snug, $roomy]));

// -----------------------------------------------------------------------------------
// `lowest_landed_cost` -- carrier-billable *money*, with the rate card arriving as
// request data. It exists because weight and money do not always agree: a bracket step,
// or a minimum charge, can make the cheaper shipment the heavier one.
//
// Below the roomy box bills heavier (12,800 g of dimensional weight against the snug
// box's 5,400) and still costs less, because the snug box's carrier charges a steep
// first bracket. Rank by weight and you pick the wrong box.
// -----------------------------------------------------------------------------------
$dearPerGram = Container::create(
    'snug', Dimensions::mm('300', '300', '300'), maxPayload: '20 kg',
    rateTable: new RateTable([6000, 20000], [2400, 3100]),
);
$cheapPerGram = Container::create(
    'roomy', Dimensions::mm('400', '400', '400'), maxPayload: '20 kg',
    rateTable: new RateTable([6000, 20000], [900, 1500]),
);
$byMoney = new PackingConfig(
    objective: 'lowest_landed_cost',
    dimensionalWeightDivisor: 5000,
    dimensionalWeightLengthUnit: 'cm',
    dimensionalWeightWeightUnit: 'kg',
);
printf("%-18s %s\n", 'lowest_landed_cost', $solve($byMoney, [$dearPerGram, $cheapPerGram]));

// A rate card that stops short of the shipment is a refusal, never a silent clamp to the
// top bracket -- you would otherwise be quoted a price the carrier never published.
$tooNarrow = Container::create(
    'roomy', Dimensions::mm('400', '400', '400'), maxPayload: '20 kg',
    rateTable: new RateTable([2000], [900]),
);
try {
    $solve($byMoney, [$tooNarrow]);
} catch (UnratedWeightException $refusal) {
    printf("%-18s refused: %s\n", 'lowest_landed_cost*', $refusal->getMessage());
}

// -----------------------------------------------------------------------------------
// `open_dimension_height` -- pack into the shortest stack, for a lidless container or a
// pallet that has to clear a doorway.
// -----------------------------------------------------------------------------------
printf("%-18s %s\n", 'open_dimension_height',
    $solve(new PackingConfig(objective: 'open_dimension_height'), [$snug, $roomy]));

// -----------------------------------------------------------------------------------
// `maximum_value` -- when not everything fits, leave the *cheap* things behind. Note the
// honest limitation: this orders by value, it does not solve the knapsack problem to
// optimality. `quantity: 1` on the container is what makes it a choice at all -- with an
// unlimited supply the packer simply opens another box.
// -----------------------------------------------------------------------------------
$tiny = [Container::create('tiny', Dimensions::mm('200', '100', '100'), maxPayload: '20 kg', quantity: 1)];
$mixed = [
    Item::create('gold', Dimensions::mm('100', '100', '100'), '500 g', quantity: 2, value: 90000),
    Item::create('gravel', Dimensions::mm('100', '100', '100'), '500 g', quantity: 2, value: 10),
];
$result = (new Packer(new PackingConfig(objective: 'maximum_value')))->pack($mixed, $tiny);
$kept = [];
foreach ($result->containers as $container) {
    foreach ($container->placements as $placement) {
        $kept[] = $placement->instance->item->id;
    }
}
$left = array_map(static fn ($u) => $u->instance->item->id, $result->unpacked);
sort($kept);
sort($left);
printf("%-18s packed=%s left behind=%s\n", 'maximum_value', json_encode($kept), json_encode($left));
