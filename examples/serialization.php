<?php
/**
 * Serialization: the same request as JSON, and what comes back.
 *
 * Run it:
 *
 *     php examples/serialization.php
 *
 * Everything the library can do is reachable over one JSON document, and that is not a
 * convenience wrapper -- it is the contract four independent implementations are held
 * to. The PHP, Python, Rust and JavaScript engines read this exact shape and are checked
 * against each other on a shared fixture corpus, so a request you build here is a
 * request you can hand to any of them.
 *
 * Two consequences worth knowing:
 *
 * - lengths and weights travel as *decimal strings*, never as floats, so "12 3/8 in"
 *   survives the trip intact (see units.php for why that matters);
 * - a field this engine has deliberately not implemented yet is refused by name, never
 *   quietly ignored -- but a key the parser does not recognise on an item *is* ignored.
 *   The difference matters, and the last section shows both.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Serialization\ArrayCodec;
use Packvium\Serialization\UnsupportedFeatureException;

// -----------------------------------------------------------------------------------
// A request is a plain array. This one is the whole vocabulary in miniature: units,
// solver configuration, items with rules, and a container with a carrier rate card.
// -----------------------------------------------------------------------------------
$request = [
    'units' => ['length' => 'mm'],
    'configuration' => [
        'objective' => 'lowest_landed_cost',
        'dimensional_weight_divisor' => 5000,
        'dimensional_weight_length_unit' => 'cm',
        'dimensional_weight_weight_unit' => 'kg',
        'solver_profile' => 'balanced',
        'seed' => 42,
        // Up to two complete answers: the winner and one runner-up.
        'alternatives' => 2,
        // A wall clock is a fact about the machine, not about the request: how many
        // runners-up a clock-bounded search finds depends on how busy the host is. The
        // effort budget counts work instead, so it stops at the same point on every
        // machine, and the time limit is only a safety fuse far above what this needs.
        // reproducibility.php shows what happens when the clock does decide.
        'effort_budget' => ['max_search_nodes' => 20000],
        'time_limit_ms' => 60000,
    ],
    'items' => [
        [
            'id' => 'book', 'quantity' => 6,
            'dimensions' => ['length' => '210', 'width' => '140', 'height' => '30'],
            'weight' => '450 g',
        ],
        [
            'id' => 'mug', 'quantity' => 2,
            'dimensions' => ['length' => '100', 'width' => '100', 'height' => '120'],
            'weight' => '380 g',
            'keep_upright' => true,
            'max_top_load' => '1 kg',
        ],
    ],
    'containers' => [
        [
            'id' => 'box-m',
            'inner_dimensions' => ['length' => '400', 'width' => '300', 'height' => '250'],
            'max_payload' => '20 kg',
            'cost_minor' => 180,
            'rate_table' => [
                'weight_brackets_g' => [5000, 10000, 30000],
                'prices_minor' => [890, 1240, 2050],
                'minimum_charge_minor' => 650,
                'fuel_surcharge_permille' => 78,
            ],
        ],
    ],
];

$result = ArrayCodec::pack($request);

// -----------------------------------------------------------------------------------
// The result is a plain array too, and deliberately verbose: every placement has exact
// coordinates, every unplaced item has a structured reason, and the algorithm report
// says which solver won and what it spent getting there.
// -----------------------------------------------------------------------------------
$placed = 0;
foreach ($result['containers'] as $container) {
    $placed += count($container['placements']);
}
printf("status      %s\n", $result['status']);
printf("score       %s  <- lexicographic, exact integers, cheapest first\n", json_encode($result['score']));
printf("solver      %s\n", $result['algorithm']['solver']);
printf("containers  %s\n", json_encode(array_column($result['containers'], 'container_type')));
printf("placed      %d\n", $placed);
printf("unplaced    %s\n", json_encode($result['unpacked_items'] ?? []));

echo "\none placement, in full:\n";
echo substr(json_encode($result['containers'][0]['placements'][0], JSON_PRETTY_PRINT), 0, 320), " ...\n";

// -----------------------------------------------------------------------------------
// `alternatives` asks for runners-up. They are real alternative arrangements, already
// scored and already validated -- useful when you want to show a human a choice rather
// than a verdict. The count includes the winner, so `2` means at most one runner-up, and
// an empty list is normal: a search that found nothing else worth ranking says so.
// -----------------------------------------------------------------------------------
// Two alternatives can share a score and still be different arrangements -- equal cost,
// different geometry. Compare their placements, not their scores, when showing a choice.
echo "\nalternatives: ", count($result['alternatives'] ?? []), "\n";
foreach ($result['alternatives'] ?? [] as $alternative) {
    $positions = [];
    foreach ($alternative['containers'] as $container) {
        foreach ($container['placements'] as $placement) {
            $positions[] = [$placement['item_id'], $placement['position']['x']['value']];
        }
    }
    printf("   score %s  first two placements %s\n",
        json_encode($alternative['score']), json_encode(array_slice($positions, 0, 2)));
}

// -----------------------------------------------------------------------------------
// What is refused, and what is not. The two look alike from the outside.
//
// A key the parser does not recognise on an item is *ignored*. Misspell `keep_upright`
// and you get a silently rotatable mug, not an error. If typos in item fields matter to
// you, check keys against the field list in docs/PUBLIC-API.md before calling the engine.
// -----------------------------------------------------------------------------------
$typo = $request;
$typo['items'][1]['keep_uprght'] = true;
printf("\nmisspelled field: %s -- accepted; the misspelling is invisible to the parser,\n",
    ArrayCodec::pack($typo)['status']);
echo "                  so `keep_upright` was never applied to the mug\n";

// An unknown *value* where the engine has to choose a behaviour is a different matter.
// There is no sensible default for "rank by something I have never heard of".
$badObjective = $request;
$badObjective['configuration']['objective'] = 'cheapest';
try {
    ArrayCodec::pack($badObjective);
} catch (Throwable $refusal) {
    printf("unknown objective: %s\n", substr($refusal->getMessage(), 0, 100));
}

// And a field the schema reserves but this engine has not implemented yet is refused by
// name, so a request written for a newer engine fails loudly instead of being
// half-honoured. The list is the engine's own constant.
$refused = array_filter(ArrayCodec::UNSUPPORTED_FIELDS);
printf("fields this engine refuses by name: %s\n",
    $refused === [] ? 'none' : json_encode($refused));

$ahead = $request;
$ahead['containers'][0]['pallet_overhang_limit'] = ['length' => '50', 'width' => '50'];
try {
    ArrayCodec::pack($ahead);
} catch (UnsupportedFeatureException $refusal) {
    printf("  sending one anyway: %s\n", substr($refusal->getMessage(), 0, 100));
}

// -----------------------------------------------------------------------------------
// The same document drives the command line, which reads a request on stdin and writes
// a result on stdout -- which is how the engines are checked against each other, and how
// you would call this from a language with no binding yet:
//
//     echo '<request json>' | php bin/packvium
// -----------------------------------------------------------------------------------
echo "\nthe CLI takes exactly the document above:  echo '...' | php bin/packvium\n";
