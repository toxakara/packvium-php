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
