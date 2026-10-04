<?php
/**
 * Fleet and vehicle limits: what may go where, and how much of it.
 *
 * Run it:
 *
 *     php examples/limits.php
 *
 * A same-day courier has one refrigerated van and a few cargo bikes. Every rule below is a
 * request field, so it holds in every Packvium engine and travels with the request:
 *
 * - `obstacles` on a container: space inside it that cargo may not use -- here the fridge
 *   unit under the roof, and the two wheel arches as one obstacle made of two boxes
 *   (`additional_boxes`), since an arch on each side is one thing to the person loading.
 * - `max_items` on a container: a cap on how many items it takes, whatever their size --
 *   a bike's rack holds four parcels however small they are.
 * - `tags` and `tag_limits`: at most one item tagged `dangerous_goods` in the van.
 * - `eligible_container_tags` on an item: frozen goods only go in a container tagged
 *   `refrigerated`.
 * - `max_containers` in the configuration: how many vehicles the whole plan may use.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Serialization\ArrayCodec;

$request = [
    'units' => ['length' => 'mm'],
    // Counted work decides the answer, not the clock (see reproducibility.php); the time
    // limit is a fuse far above what this solve needs.
    'configuration' => ['time_limit_ms' => 60000, 'effort_budget' => ['max_restarts' => 8]],
    'items' => [
        ['id' => 'frozen-tote', 'quantity' => 3, 'weight' => '15 kg',
         'dimensions' => ['length' => '600', 'width' => '400', 'height' => '300'],
         'eligible_container_tags' => ['refrigerated']],
        ['id' => 'lithium-battery', 'quantity' => 3, 'weight' => '12 kg',
         'dimensions' => ['length' => '400', 'width' => '300', 'height' => '250'],
         'tags' => ['dangerous_goods']],
        ['id' => 'parcel', 'quantity' => 24, 'weight' => '8 kg',
         'dimensions' => ['length' => '500', 'width' => '400', 'height' => '400']],
    ],
    'containers' => [
        ['id' => 'cargo-bike', 'quantity' => 3, 'cost_minor' => 900, 'max_items' => 4, 'max_payload' => '100 kg',
         'inner_dimensions' => ['length' => '1000', 'width' => '800', 'height' => '900']],
        ['id' => 'reefer-van', 'quantity' => 1, 'cost_minor' => 4000,
         'inner_dimensions' => ['length' => '1800', 'width' => '1400', 'height' => '1200'],
         'tags' => ['refrigerated'],
         'tag_limits' => ['dangerous_goods' => 1],
         'obstacles' => [
             ['id' => 'fridge-unit', 'origin' => ['x' => '0', 'y' => '0', 'z' => '800'],
              'dimensions' => ['length' => '400', 'width' => '1400', 'height' => '400']],
             ['id' => 'wheel-arches', 'origin' => ['x' => '700', 'y' => '0', 'z' => '0'],
              'dimensions' => ['length' => '800', 'width' => '200', 'height' => '350'],
              'additional_boxes' => [['origin' => ['x' => '700', 'y' => '1200', 'z' => '0'],
                                      'dimensions' => ['length' => '800', 'width' => '200', 'height' => '350']]]],
         ]],
    ],
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
    echo "  status: {$result['status']}\n";
    foreach ($result['containers'] as $container) {
        $contents = array_count_values(array_column($container['placements'], 'item_type'));
        ksort($contents);
        $listed = [];
        foreach ($contents as $name => $count) {
            $listed[] = "{$count} {$name}";
        }
        printf("    %-14s %s\n", $container['id'], implode(', ', $listed));
    }
    // One line per kind of refusal: a reader needs "five parcels, no room", not five lines.
    $refusals = [];
    foreach ($result['unpacked_items'] as $unpacked) {
        $key = implode("\0", [$unpacked['item_type'], $unpacked['reason'], $unpacked['proof']['level']]);
        $refusals[$key] = ($refusals[$key] ?? 0) + 1;
    }
    ksort($refusals);
    foreach ($refusals as $key => $number) {
        [$name, $reason, $level] = explode("\0", $key);
        echo "    left out: {$number} {$name}, {$reason} ({$level})\n";
    }
}

function placedCount(array $result, string $containerId, string $itemType): int
{
    $total = 0;
    foreach ($result['containers'] as $container) {
        if ($container['id'] !== $containerId) {
            continue;
        }
        $total += count(array_keys(array_column($container['placements'], 'item_type'), $itemType, true));
    }
    return $total;
}

function section(string $title): void
{
    echo "\n", str_repeat('=', 78), "\n", $title, "\n", str_repeat('=', 78), "\n";
}

// --------------------------------------------------------------------------------------
section('1. The plan');

$plan = solve($request);
show($plan);
echo '
  All three frozen totes are in the van, because nothing else is refrigerated. Only
  one battery rides in the van; the other two go by bike. The bike takes four items,
  its `max_items`, although there is room and payload for more.
';

// --------------------------------------------------------------------------------------
section('2. What the obstacles cost');

$openVan = solve($request, static function (array &$r): void { unset($r['containers'][1]['obstacles']); });
printf("  parcels in the van with the fridge unit and arches: %d\n", placedCount($plan, 'reefer-van#1', 'parcel'));
printf("  parcels in the van if it were an empty box:        %d\n", placedCount($openVan, 'reefer-van#1', 'parcel'));
echo '
  An obstacle is exact space, not a hint. Model what is really there, or the plan
  will put a parcel where the wheel arch is.
';

// --------------------------------------------------------------------------------------
section('3. `max_containers`: the plan may use one vehicle');

show(solve($request, static function (array &$r): void { $r['configuration']['max_containers'] = 1; }));
echo '
  The cap is honoured, and what does not fit is listed rather than silently sent in
  a vehicle you did not allow. The reason is `observed`, not `proven`: the search
  found no room for these, which is not a claim that no arrangement has room.
';

// --------------------------------------------------------------------------------------
section('4. `eligible_container_tags`: the van is off the road today');

show(solve($request, static function (array &$r): void { array_splice($r['containers'], 1, 1); }));
echo '
  The frozen totes cannot go anywhere, and their reason is `proven`: no container in
  the request carries the tag they need, which is a fact about the request rather
  than about how far the search got. The rest is `observed`: three bikes of four
  items each were all the request offered, and the search ran out of them.
';
