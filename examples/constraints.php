<?php
/**
 * Constraints: how to say "this may not go there" and get told why.
 *
 * Run it:
 *
 *     php examples/constraints.php
 *
 * The solver's job is not only to fit boxes. Most real packing rules are refusals --
 * this side up, nothing on top of that, keep the chemicals away from the food -- and the
 * useful part of the answer is often the item that did *not* fit and the reason it did
 * not.
 *
 * Every constraint here is a named argument to `Item::create` or `Container::create`.
 * None of them needs a custom class, and none changes how you call `pack`.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container, Dimensions, Item, Rotation};
use Packvium\Explain\Explain;
use Packvium\Packer;
use Packvium\Unit\Length;

// The six axis-aligned orientations, which is also the default. Narrow this list when an
// item may only be set down certain ways -- `[Rotation::LWH, Rotation::WLH]` keeps a
// carton flat while still letting it turn on the spot.
$anyOrientation = [
    Rotation::LWH, Rotation::LHW, Rotation::WLH,
    Rotation::WHL, Rotation::HLW, Rotation::HWL,
];

$items = [
    // `keepUpright` forbids every rotation that would tip the item over. An open tub of
    // paint is the usual reason.
    Item::create('paint', Dimensions::mm('200', '200', '250'), '5 kg', quantity: 2, allowedRotations: $anyOrientation, keepUpright: true),

    // `mustBeOnFloor` keeps the item on the container floor, and `maxTopLoad` caps what
    // may rest directly on it. Note "directly": this is not a whole-stack limit.
    Item::create(
        'glass-panel',
        Dimensions::mm('400', '300', '40'),
        '8 kg',
        quantity: 1,
        allowedRotations: $anyOrientation,
        mustBeOnFloor: true,
        maxTopLoad: '2 kg',
    ),

    // `stackable: false` means nothing may be placed on this item at all.
    Item::create('cake', Dimensions::mm('250', '250', '150'), '1 kg', quantity: 1, allowedRotations: $anyOrientation, stackable: false),

    // Tags are how two items refuse each other. `incompatibleTags` is checked both ways,
    // so tagging one side is enough.
    Item::create(
        'bleach',
        Dimensions::mm('120', '120', '300'),
        '2 kg',
        quantity: 2,
        allowedRotations: $anyOrientation,
        tags: ['hazmat'],
        incompatibleTags: ['food'],
    ),
    Item::create('flour', Dimensions::mm('200', '150', '100'), '1500 g', quantity: 3, allowedRotations: $anyOrientation, tags: ['food']),

    // Longer than the crate's longest inner edge in every orientation, so no solver can
    // place it. It is here to show what a refusal looks like.
    Item::create('ladder', Dimensions::mm('1800', '300', '100'), '6 kg', quantity: 1),
];

$containers = [
    // `maxPayload` is the weight the container may carry, excluding its own tare.
    Container::create(
        'crate',
        Dimensions::mm('600', '500', '500'),
        tareWeight: '3 kg',
        maxPayload: '25 kg',
        costMinor: 400,
    ),
];

$result = (new Packer(PackingConfig::balanced()))->pack($items, $containers);

/** Positions are exact integers in 1/16000 mm; render them for a human. */
$millimetres = static fn (int $ticks): string => rtrim(rtrim(
    number_format($ticks / Length::TICKS_PER_MM, 2, '.', ''),
    '0',
), '.');

printf("status: %s\n", $result->status->value);
printf("containers opened: %d\n", count($result->containers));

foreach ($result->containers as $index => $packed) {
    printf("\ncrate #%d (%s): %d placement(s)\n", $index + 1, $packed->container->id, count($packed->placements));
    foreach ($packed->placements as $placement) {
        printf(
            "  %-12s at (%s, %s, %s) mm\n",
            $placement->instance->item->id,
            $millimetres($placement->position->x),
            $millimetres($placement->position->y),
            $millimetres($placement->position->z),
        );
    }
}

// Two crates for a load that would fit in one by volume: `bleach` is tagged `hazmat` and
// refuses `food`, so it cannot share a container with `flour`. Nothing asked the solver
// to open a second crate -- the constraint did.

// The refusals are the interesting half. `Explain::unpackedItem` turns the structured
// reason into a sentence, so you can show a human why their order will not ship as one
// box without teaching them the reason codes.
if ($result->unpacked !== []) {
    echo "\nnot packed:\n";
    foreach ($result->unpacked as $unpacked) {
        printf("  %-12s %s\n", $unpacked->instance->item->id, Explain::unpackedItem($unpacked));
    }
}

// ------------------------------------------------------------- one rule at a time
//
// The four rules below are each shown twice: the same items, the same container, once
// without the rule and once with it. A constraint you cannot watch change the answer is
// one the reader has to take on faith, and the pair makes the rule -- rather than the
// geometry -- provably the reason.
//
// Note what "the rule bit" looks like. Only sometimes is it a refusal; more often the
// solver satisfies the rule by opening another container, which costs money and is the
// answer you actually wanted to see coming. So both numbers are printed.

$compare = static function (string $rule, array $without, array $withRule, array $containers): void {
    printf("\n%s\n", $rule);
    foreach ([['without the rule', $without], ['with the rule   ', $withRule]] as [$label, $variant]) {
        $outcome = (new Packer(PackingConfig::balanced()))->pack($variant, $containers);
        $placed = 0;
        foreach ($outcome->containers as $packed) {
            $placed += count($packed->placements);
        }
        printf(
            "  %s: %d container(s), %d placed, %d refused\n",
            $label, count($outcome->containers), $placed, count($outcome->unpacked),
        );
        foreach ($outcome->unpacked as $unpacked) {
            printf("      %s\n", Explain::unpackedItem($unpacked));
        }
    }
};

$shelf = [Container::create('shelf', Dimensions::mm('800', '400', '500'), maxPayload: '40 kg')];

// `allowedRotations` narrows the six orientations to the ones you permit, and
// `[Rotation::LWH, Rotation::WLH]` is the pair that keeps the item's own height vertical
// -- what you want for anything with a printed face or an open top. The pole is 700 mm
// tall and the shelf is 500 mm deep, so it fits only by being laid down, which is what
// this forbids.
$pole = Dimensions::mm('90', '90', '700');
$compare(
    'allowedRotations -- a pole that only fits lying down, forbidden from lying down',
    [Item::create('pole', $pole, '1 kg', allowedRotations: $anyOrientation)],
    [Item::create('pole', $pole, '1 kg', allowedRotations: [Rotation::LWH, Rotation::WLH])],
    $shelf,
);

// `maxStackedItems` caps how many units may sit above one item -- a pallet-pattern rule
// ("three high, no more"), not a weight limit. The column below is one tin wide, so
// height is the only way to fit more, and the second container is the price of the cap.
$column = [Container::create('column', Dimensions::mm('160', '160', '600'), maxPayload: '40 kg')];
$tin = Dimensions::mm('150', '150', '120');
$compare(
    'maxStackedItems -- five tins fit in one column; three-high needs two columns',
    [Item::create('tin', $tin, '800 g', quantity: 5, allowedRotations: $anyOrientation)],
    [Item::create('tin', $tin, '800 g', quantity: 5, allowedRotations: $anyOrientation, maxStackedItems: 3)],
    $column,
);

// `minimumSupportRatio` is how much of an item's base must rest on something solid. The
// plinth stands on the floor and covers a quarter of the ledge, and the ledge is too
// shallow for the slab to stand on edge -- so the only place the slab fits is perched on
// the plinth, on a quarter of its base. At 0.9 that is refused and a second ledge opens.
$ledge = [Container::create('ledge', Dimensions::mm('400', '400', '350'), maxPayload: '40 kg')];
$plinth = Item::create('plinth', Dimensions::mm('200', '200', '300'), '5 kg', allowedRotations: $anyOrientation, mustBeOnFloor: true);
$slab = Dimensions::mm('400', '400', '60');
$compare(
    'minimumSupportRatio -- a slab perched on a quarter of its base',
    [$plinth, Item::create('slab', $slab, '9 kg', allowedRotations: $anyOrientation)],
    [$plinth, Item::create('slab', $slab, '9 kg', allowedRotations: $anyOrientation, minimumSupportRatio: 0.9)],
    $ledge,
);

// `group` is atomic: every member ships in one container or none of them does. The third
// part is deliberately too long for the shelf, so it takes the other two down with it
// rather than shipping two thirds of an assembly nobody can use.
$parts = [
    Dimensions::mm('200', '200', '100'),
    Dimensions::mm('200', '200', '100'),
    Dimensions::mm('900', '100', '100'),
];
$loose = $grouped = [];
foreach ($parts as $index => $dimensions) {
    $id = 'kit-' . ($index + 1);
    $loose[] = Item::create($id, $dimensions, '2 kg', allowedRotations: $anyOrientation);
    $grouped[] = Item::create($id, $dimensions, '2 kg', allowedRotations: $anyOrientation, group: 'assembly');
}
$compare('group -- one member cannot be placed, so none of them is', $loose, $grouped, $shelf);

// Every reason code above is a fact about the request, not a solver failure -- which is
// why `Explain::unpackedItem` can turn it into a sentence a customer is allowed to read.
