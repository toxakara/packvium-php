<?php
/**
 * Nested packing: cartons into a pallet, in one call.
 *
 * Run it:
 *
 *     php examples/nested.php
 *
 * Real fulfilment is rarely one level. Units go into cartons, cartons go onto a pallet,
 * and sometimes pallets go into a trailer. `NestedPacker` runs those levels in order and
 * feeds each level's *packed containers* into the next level as items -- a carton that
 * came out of level one arrives at level two as a box with its own outer dimensions and
 * its total packed weight.
 *
 * The levels stay independent on purpose. Level two does not reach back and repack level
 * one to get a better pallet, because that would make the carton contents depend on the
 * pallet, and a carton you already taped shut cannot be repacked. If you want that
 * trade-off explored, run the packer yourself with different carton sets and compare.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container, Dimensions, Item};
use Packvium\Nested\{NestedPacker, PackingLevel};

// What the customer ordered.
$items = [
    Item::create('mug', Dimensions::mm('120', '120', '100'), '400 g', quantity: 24),
    Item::create('plate', Dimensions::mm('260', '260', '20'), '600 g', quantity: 16),
];

$levels = [
    // Level 1: choose cartons. `outerDimensions` matters here -- the next level packs
    // the *outside* of this carton, including its wall thickness.
    new PackingLevel('carton', [
        Container::create(
            'box-s',
            Dimensions::mm('300', '300', '300'),
            tareWeight: '300 g',
            maxPayload: '15 kg',
            outerDimensions: Dimensions::mm('310', '310', '310'),
            costMinor: 120,
        ),
        Container::create(
            'box-l',
            Dimensions::mm('400', '400', '400'),
            tareWeight: '500 g',
            maxPayload: '25 kg',
            outerDimensions: Dimensions::mm('412', '412', '412'),
            costMinor: 180,
        ),
    ], PackingConfig::balanced()),

    // Level 2: put those cartons on a pallet. The deck is the inner dimension and the
    // usable stack height is the rest.
    new PackingLevel('pallet', [
        Container::create(
            'euro',
            Dimensions::mm('1200', '800', '1400'),
            tareWeight: '25 kg',
            maxPayload: '700 kg',
            costMinor: 1500,
        ),
    ]),
];

$result = (new NestedPacker())->pack($items, $levels);

// `$result->levels` is one PackingResult per level, in the order you supplied them, so
// index it back against the level names you chose.
foreach ($levels as $index => $level) {
    $packedLevel = $result->levels[$index];
    printf("== %s ==\n", $level->name);
    printf("   status: %s\n", $packedLevel->status->value);
    printf("   containers used: %d\n", count($packedLevel->containers));
    foreach ($packedLevel->containers as $packed) {
        printf("     %-8s holding %d item(s)\n", $packed->container->id, count($packed->placements));
    }
    if ($packedLevel->unpacked !== []) {
        printf("   left over: %d\n", count($packedLevel->unpacked));
    }
    echo "\n";
}

$cartons = count($result->levels[0]->containers);
$pallets = count($result->levels[count($result->levels) - 1]->containers);
printf("%d carton(s) travelling on %d pallet(s)\n", $cartons, $pallets);
