<?php
/**
 * Packaging that is not a rigid box: an overhanging pallet, a gusseted mailer, a box cut
 * to measure with room left for void fill, and a pallet that chooses its own cartons.
 *
 * Run it:
 *
 *     php examples/packaging.php
 *
 * Each of these is a request field or a constructor argument, so it holds in every
 * Packvium engine:
 *
 * - `pallet_overhang_limit` on a container: the load may pass the deck by that much at
 *   each end and each side, but only the deck bears weight.
 * - `deformable_boundary` of kind `gusseted_bag`: a mailer whose side gussets give it
 *   `side_gusset_depth` more girth than a flat bag of the same size.
 * - `made_to_measure` beside `void_fill_reserve_ratio`: the box is cut for the load, and
 *   still keeps the share of its volume the packer asked to leave for void fill.
 * - `new NestedPacker(K)`: the K best carton sets travel up to the pallet level, so a
 *   carton set that looks worse on its own can win by fitting the pallet.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container, Dimensions, Item};
use Packvium\Nested\{NestedPacker, PackingLevel};
use Packvium\Serialization\ArrayCodec;

// Counted work decides every answer below, not the clock (see reproducibility.php); the
// time limit is a fuse far above what these solves need.
const CONFIGURATION = ['effort_budget' => ['max_search_nodes' => 20000], 'time_limit_ms' => 60000];
const RULE = '==============================================================================';

function section(string $title): void
{
    echo "\n", RULE, "\n", $title, "\n", RULE, "\n";
}

/** @param array<string,array<string,mixed>> $dimensions */
function size(array $dimensions): string
{
    return "{$dimensions['length']['value']} x {$dimensions['width']['value']} x {$dimensions['height']['value']} mm";
}

/** @param array<string,mixed> $result */
function placedCount(array $result): int
{
    $placed = 0;
    foreach ($result['containers'] as $container) {
        $placed += count($container['placements']);
    }
    return $placed;
}

// --------------------------------------------------------------------------------------
section('1. A crate wider than the pallet deck');

$pallet = [
    'units' => ['length' => 'mm'],
    'configuration' => CONFIGURATION,
    'items' => [
        ['id' => 'crate', 'quantity' => 1, 'weight' => '40 kg',
         'dimensions' => ['length' => '1250', 'width' => '850', 'height' => '600']],
    ],
    'containers' => [
        ['id' => 'euro', 'max_payload' => '1000 kg',
         'inner_dimensions' => ['length' => '1200', 'width' => '800', 'height' => '1000']],
    ],
];
$deckOnly = ArrayCodec::pack($pallet);
printf("  deck only:          %s, %d placed, crate refused: %s\n",
    $deckOnly['status'], placedCount($deckOnly), $deckOnly['unpacked_items'][0]['reason']);

$pallet['containers'][0]['pallet_overhang_limit'] = ['length' => '50', 'width' => '50'];
$overhung = ArrayCodec::pack($pallet);
$loaded = $overhung['containers'][0];
$crate = $loaded['placements'][0];
printf("  50 mm overhang:     %s, %d placed\n", $overhung['status'], placedCount($overhung));
printf("  declared deck:      %s\n", size($loaded['inner_dimensions']));
printf("  achieved footprint: %s\n", size($loaded['achieved_dimensions']));
printf("  crate at x=%s y=%s mm, %s of its base on the deck\n",
    $crate['position']['x']['value'], $crate['position']['y']['value'], $crate['support_ratio']);
echo <<<TEXT

  Coordinates are measured from the corner of the overhang zone, so the deck starts
  50 mm in. The crate is centred over it and passes each edge by 25 mm; only the
  deck bears weight, so `support_ratio` is the share of its base the deck carries.
  The pallet is billed, and handed up a level, at its achieved footprint when that is
  larger than the deck.

TEXT;

// --------------------------------------------------------------------------------------
section('2. A gusseted mailer holds a layer a flat one cannot');

$mailer = [
    'units' => ['length' => 'mm'],
    'configuration' => CONFIGURATION,
    'items' => [
        ['id' => 'book', 'quantity' => 3, 'weight' => '300 g', 'allowed_rotations' => ['LWH'],
         'dimensions' => ['length' => '200', 'width' => '100', 'height' => '20']],
    ],
    'containers' => [
        ['id' => 'mailer', 'quantity' => 1, 'deformable_boundary' => ['kind' => 'flat_bag'],
         'inner_dimensions' => ['length' => '300', 'width' => '150', 'height' => '100']],
    ],
];
$flat = ArrayCodec::pack($mailer);
printf("  flat bag:            %s, %d of 3 books\n", $flat['status'], placedCount($flat));

$mailer['containers'][0]['deformable_boundary'] = ['kind' => 'gusseted_bag', 'side_gusset_depth' => '25'];
$gusseted = ArrayCodec::pack($mailer);
printf("  25 mm side gussets:  %s, %d of 3 books, filled to %s\n",
    $gusseted['status'], placedCount($gusseted), size($gusseted['containers'][0]['achieved_dimensions']));
echo <<<TEXT

  A bag that bulges trades width for height: a flat bag 150 mm wide admits a load
  whose width plus height is at most 150 mm, so two books (100 + 40). The gussets
  add their depth to that girth, 175 mm, and a third book (100 + 60) fits.

TEXT;

// --------------------------------------------------------------------------------------
section('3. A box cut to measure that keeps room for void fill');

$cut = [
    'units' => ['length' => 'mm'],
    'configuration' => CONFIGURATION,
    'items' => [
        ['id' => 'cube', 'quantity' => 8, 'weight' => '100 g',
         'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100']],
    ],
    'containers' => [
        ['id' => 'machine', 'cost_minor' => 40,
         'inner_dimensions' => ['length' => '1510', 'width' => '1510', 'height' => '1510'],
         'made_to_measure' => [
             'minimum' => ['length' => '60', 'width' => '60', 'height' => '60'],
             'increment' => ['length' => '25', 'width' => '25', 'height' => '25'],
             'outer_allowance' => ['length' => '3', 'width' => '3', 'height' => '3'],
             'cost_per_square_metre_minor' => 1000,
             'tare_per_square_metre' => '500',
         ]],
    ],
];
foreach ([0.0, 0.5] as $reserve) {
    $cut['containers'][0]['void_fill_reserve_ratio'] = $reserve;
    $box = ArrayCodec::pack($cut)['containers'][0];
    printf("  reserve %.1f:  cut to %s, costs %d, %s of it filled\n",
        $reserve, size($box['inner_dimensions']), $box['made_to_measure']['cost_minor'],
        $box['volume_utilization']);
}
echo <<<TEXT

  The tight cut is the smallest box on the machine's grid (60 mm, then steps of
  25 mm) that holds the load. A reserve of 0.5 asks for half of the box to stay free
  for void fill, which the tight box breaks, so the box is cut larger, and costs
  more, until no more than half of it is load.

TEXT;

// --------------------------------------------------------------------------------------
section('4. A pallet level that chooses its cartons');

$jars = [Item::create('jar', Dimensions::mm('100', '100', '100'), '500 g', 2)];
$levels = [
    new PackingLevel('carton', [
        Container::create('sleeve', Dimensions::mm('200', '100', '100')),
        Container::create('single', Dimensions::mm('100', '100', '100')),
    ], PackingConfig::fast(60000)),
    // A parcel-locker compartment: tall and narrow, so a sleeve of two jars lying side by
    // side does not go in, and two single cartons stacked do.
    new PackingLevel('locker', [
        Container::create('locker', Dimensions::mm('100', '100', '200')),
    ], PackingConfig::fast(60000)),
];
foreach ([1, 2] as $width) {
    $chain = (new NestedPacker($width))->pack($jars, $levels);
    $cartons = array_map(static fn ($packed): string => $packed->id(), $chain->levels[0]->containers);
    printf("  new NestedPacker(%d):  cartons %s, locker %s\n",
        $width, json_encode($cartons), $chain->levels[1]->complete() ? 'complete' : 'leaves a carton out');
}
echo <<<TEXT

  One sleeve is the better carton level on its own, so the greedy chain (width 1, the
  default) takes it and the locker cannot hold it. A width of 2 carries the two best
  carton sets up a level and keeps the one the locker scores best: two singles.

TEXT;
