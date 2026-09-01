<?php
/**
 * Shapes: when an item is not its box.
 *
 * Run it:
 *
 *     php examples/shapes.php
 *
 * Every other example treats an item as the box it declares. That is the default and it
 * is right for almost everything, because a carton *is* a cuboid. Two kinds of goods are
 * not: a moulded or tapered part that leaves a usable void beside it, and a soft one that
 * gives way under whatever is stacked on it.
 *
 * `shape_type` narrows the box in one direction each -- `convex_hull` in space,
 * `compressible` in height under load -- and neither is ever inferred. An engine that
 * quietly packed a hull as its bounding box would return a plan that validates and does
 * not physically fit, so the value must be asked for.
 *
 * Both are written here through `ArrayCodec::pack`, the request contract the four engines
 * share, so the same document runs unchanged against the Python, Rust and JavaScript
 * engines.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Serialization\ArrayCodec;

const MM = ['units' => ['length' => 'mm']];

function crate(string $length, string $width, string $height): array
{
    return [['id' => 'crate',
             'inner_dimensions' => ['length' => $length, 'width' => $width, 'height' => $height]]];
}

/** Run one request and print only what the shape changed: containers and refusals. */
function summarise(string $label, array $request): void
{
    $result = ArrayCodec::pack($request);
    $placed = 0;
    foreach ($result['containers'] as $container) {
        $placed += count($container['placements']);
    }
    printf("  %-22s %-10s %d container(s), %d placed, %d refused\n",
        $label, $result['status'], count($result['containers']), $placed,
        count($result['unpacked_items']));
}

// ------------------------------------------------------------------ convex_hull
//
// Two triangular prisms, each cut from the same 100 mm cube along the diagonal. Their
// bounding boxes are identical and fill the crate on their own, so as cuboids the second
// one has nowhere to go. As hulls they are complementary halves and share the crate
// exactly -- the collision test is an exact integer separating-axis test on the vertices,
// not a box overlap.
//
// The hull is given in the item's own coordinates, in the request's length unit, and must
// fit inside the declared dimensions. It is not a replacement for them: the box still
// bounds the item, the hull only says how much of that box is solid.

const LOWER_WEDGE = [
    ['x' => '0', 'y' => '0', 'z' => '0'], ['x' => '100', 'y' => '0', 'z' => '0'],
    ['x' => '0', 'y' => '100', 'z' => '0'], ['x' => '0', 'y' => '0', 'z' => '100'],
    ['x' => '100', 'y' => '0', 'z' => '100'], ['x' => '0', 'y' => '100', 'z' => '100'],
];
const UPPER_WEDGE = [
    ['x' => '100', 'y' => '100', 'z' => '0'], ['x' => '100', 'y' => '0', 'z' => '0'],
    ['x' => '0', 'y' => '100', 'z' => '0'], ['x' => '100', 'y' => '100', 'z' => '100'],
    ['x' => '100', 'y' => '0', 'z' => '100'], ['x' => '0', 'y' => '100', 'z' => '100'],
];

function wedge(string $id, ?array $vertices): array
{
    $item = ['id' => $id, 'quantity' => 1,
             'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100'],
             'weight' => ['value' => '1', 'unit' => 'kg']];
    if ($vertices !== null) {
        $item['shape_type'] = 'convex_hull';
        $item['hull_vertices'] = $vertices;
    }
    return $item;
}

echo "convex_hull -- two complementary wedges cut from one cube\n";
summarise('as cuboids', MM + [
    'items' => [wedge('wedge-lower', null), wedge('wedge-upper', null)],
    'containers' => crate('100', '100', '100'),
]);
summarise('as hulls', MM + [
    'items' => [wedge('wedge-lower', LOWER_WEDGE), wedge('wedge-upper', UPPER_WEDGE)],
    'containers' => crate('100', '100', '100'),
]);

// One crate instead of two, for the same goods and the same crate. Nothing about the
// request changed except the claim that the items are wedges rather than blocks.

// ----------------------------------------------------------------- compressible
//
// `compression_ratio` is the fraction of its own height an item may lose when something
// rests on it -- 0.25 means it can give up a quarter. The mass above it is what decides
// how much it actually gives, so the occupied height of a compressible item is not a
// property of the item alone; it depends on what the solver put on top.
//
// `max_compression_pressure_kpa` is the other half of the same field. Past that pressure
// the item is not compressed further, it is crushed, and the load is refused instead.
//
// Note `must_be_on_floor` on the cushion. Without it the solver is free to put the brick
// underneath, nothing bears on the cushion, and the feature never engages -- which is the
// honest reason the rule is here and not an incidental detail of the example.

function cushion(int $crushKpa): array
{
    return ['id' => 'cushion', 'quantity' => 1,
            'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100'],
            'weight' => ['value' => '2', 'unit' => 'kg'],
            'must_be_on_floor' => true,
            'shape_type' => 'compressible',
            'compression_ratio' => 0.25,
            'max_compression_pressure_kpa' => $crushKpa];
}

function brick(int $kilograms): array
{
    return ['id' => 'brick', 'quantity' => 1,
            'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100'],
            'weight' => ['value' => (string) $kilograms, 'unit' => 'kg']];
}

/** One crate, one cushion, one brick -- only the brick's mass changes. */
function load(string $label, int $kilograms): void
{
    $result = ArrayCodec::pack(MM + [
        'items' => [cushion(100), brick($kilograms)],
        'containers' => crate('100', '100', '200'),
    ]);
    printf("  %-22s %d container(s), unused volume %d ppm\n",
        $label, count($result['containers']), $result['score'][3]);
}

// The crate is 100x100x200 and the two items are 100 mm cubes, so rigidly they fill it
// exactly and nothing is unused. Under 101 kg the cushion gives up part of its quarter,
// the pair still ships as one stack, and the volume it stopped occupying shows up as
// unused. One more kilogram crosses 100 kPa over the cushion's 0.01 m^2 face: the stack
// is refused, the brick opens a second crate, and half of each crate is empty.
echo "\ncompressible -- a cushion that yields to the load above it\n";
load('brick 101 kg', 101);
load('brick 102 kg', 102);

// Both shapes are refused rather than approximated wherever an engine cannot honour them
// exactly -- a hull on a route, a hull under a configured clearance, a compressible item
// with `nesting_height`. A wrong answer that validates is worse than a refusal that does
// not, which is the whole reason these are opt-in.
