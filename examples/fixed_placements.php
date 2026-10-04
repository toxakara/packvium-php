<?php
/**
 * Pack a trailer that is already partly loaded.
 *
 * Run it:
 *
 *     php examples/fixed_placements.php
 *
 * A trailer arrives at the depot with four pallets of returns already on board. They are
 * staying on for the next leg, and nobody is going to unload them to make the plan tidier.
 * The depot has fourteen store pallets to add.
 *
 * `fixed_placements` describes what is already there: which item, in which container, at
 * which position and orientation -- exactly the way a result reports a placement, so a
 * placement from an earlier plan can be fixed by quoting it. Fixed items are real cargo:
 * they carry weight, count against payload and can support what is stacked on them. The
 * engine packs around them, never moves them, and marks them `fixed: true` in the result.
 *
 * A fixed item is also an instance of its item type: `quantity` counts the whole order,
 * and the fixed entries take its first instances.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Serialization\ArrayCodec;
use Packvium\Unit\Weight;
use Packvium\Validation\FixedPlacementException;

function onBoard(string $x, string $y, string $orientation = 'LWH'): array
{
    return ['item_type' => 'returns-pallet', 'container_type' => 'trailer', 'orientation' => $orientation,
        'position' => ['x' => $x, 'y' => $y, 'z' => '0']];
}

$request = [
    'units' => ['length' => 'mm'],
    // Counted work decides the answer, not the clock (see reproducibility.php); the time
    // limit is a fuse far above what this solve needs.
    'configuration' => ['time_limit_ms' => 60000, 'effort_budget' => ['max_restarts' => 8]],
    'items' => [
        ['id' => 'returns-pallet', 'quantity' => 4, 'weight' => '350 kg', 'keep_upright' => true, 'stackable' => false,
         'dimensions' => ['length' => '1200', 'width' => '800', 'height' => '1500']],
        ['id' => 'store-pallet', 'quantity' => 14, 'weight' => '500 kg', 'keep_upright' => true,
         'dimensions' => ['length' => '1200', 'width' => '800', 'height' => '1100']],
    ],
    'containers' => [['id' => 'trailer', 'max_payload' => '9000 kg',
                      'inner_dimensions' => ['length' => '7200', 'width' => '2400', 'height' => '2400']]],
    // Three across the nose, and one turned behind them.
    'fixed_placements' => [onBoard('0', '0'), onBoard('0', '800'), onBoard('0', '1600'),
                           onBoard('1200', '0', 'WLH')],
];

/** Solve a copy of the request with `$change` applied to it. */
function solve(array $request, ?callable $change = null): array
{
    if ($change !== null) {
        $change($request);
    }
    return ArrayCodec::pack($request);
}

function show(array $result): void
{
    $trailer = $result['containers'][0];
    $fixed = array_values(array_filter($trailer['placements'], static fn(array $p): bool => !empty($p['fixed'])));
    $added = array_values(array_filter($trailer['placements'], static fn(array $p): bool => empty($p['fixed'])));
    $payload = (new Weight($trailer['payload_weight']['ticks']))->decimal('kg', 1);
    printf("  status %s, payload %s kg, left out %d\n", $result['status'], $payload, count($result['unpacked_items']));
    echo '  already on board, unmoved: ', implode(', ', array_column($fixed, 'item_id')), "\n";
    printf("  added: %d store pallets\n", count($added));
    foreach ($added as $placement) {
        if ($placement['position']['z']['value'] !== '0') {
            printf("    %s is stacked, at z = %s mm\n", $placement['item_id'], $placement['position']['z']['value']);
        }
    }
}

function section(string $title): void
{
    echo "\n", str_repeat('=', 78), "\n", $title, "\n", str_repeat('=', 78), "\n";
}

// --------------------------------------------------------------------------------------
section('1. Packed around the pallets already on board');

show(solve($request));
echo '
  The returns pallets keep their ids, positions and orientations. They took the
  first four instances of `returns-pallet`, so none are left for the engine to
  place; ask for five and it would place one more.
';

// --------------------------------------------------------------------------------------
section('2. One pallet was left in the middle of the floor');

show(solve($request, static function (array &$r): void {
    $r['fixed_placements'][3]['position'] = ['x' => '1200', 'y' => '400', 'z' => '0'];
}));
echo '
  The fourth returns pallet stands 400 mm off the side wall, and no pallet fits in
  the strip it leaves. The plan still takes all fourteen store pallets, but it has
  to put one on top of another to do it. Whether that is acceptable is
  your call; the plan shows you the price of not moving that pallet.
';

// --------------------------------------------------------------------------------------
section('3. What is on board cannot be where the record says');

foreach ([
    'two pallets recorded in the same place' => static function (array &$r): void {
        $r['fixed_placements'][3]['position'] = ['x' => '600', 'y' => '0', 'z' => '0'];
    },
    'a 1000 kg trailer limit, already exceeded by the returns' => static function (array &$r): void {
        $r['containers'][0]['max_payload'] = '1000 kg';
    },
] as $label => $change) {
    try {
        solve($request, $change);
    } catch (FixedPlacementException $refusal) {
        echo "  {$label}:\n";
        echo "    {$refusal->reason()}, {$refusal->field()}: {$refusal->getMessage()}\n";
    }
}
echo '
  Nothing is solved when the starting point is impossible: a plan built on a
  collision or an overweight trailer would be wrong before the first pallet moved.
  `cannot_hold` names the fixed set as a whole; the detail says what is wrong.
';
