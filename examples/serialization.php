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
 * - a field this engine has deliberately not implemented is refused by name, but a key
 *   the parser simply does not recognise is ignored. The last section shows both.
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
        'profile' => 'balanced',
        'seed' => 42,
        'top_k' => 2,
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
// `top_k` asks for runners-up: real alternative arrangements, already scored and already
// validated. Two of them can share a score and still differ in geometry, so compare
// placements rather than scores when showing a human a choice.
// -----------------------------------------------------------------------------------
echo "\nalternatives: ", count($result['alternatives'] ?? []), "\n";
foreach ($result['alternatives'] ?? [] as $alternative) {
    $first = $alternative['containers'][0]['placements'][0];
    printf("   score %s  first placement %s at x=%s\n",
        json_encode($alternative['score']), $first['item_id'], $first['position']['x']['value']);
}

// -----------------------------------------------------------------------------------
// What is refused, and what is not. The two look alike from the outside.
//
// A key the parser does not recognise is *ignored*. Misspell `keep_upright` and you get
// a silently unrotated mug, not an error -- the strictness lives in the request JSON
// Schema, which sets `additionalProperties: false` and ships with the project rather
// than with this package. Validate against it if you want typo protection.
// See docs/SERIALIZATION.md.
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

// And a field this engine names as not-yet-implemented is refused explicitly, so a
// request written for a newer engine fails loudly instead of being half-honoured.
$fromTheFuture = $request;
$fromTheFuture['items'][0]['shape_type'] = 'convex_hull';
try {
    ArrayCodec::rejectUnsupported($fromTheFuture, ['item' => ['shape_type'], 'request' => [], 'configuration' => [], 'container' => []]);
} catch (UnsupportedFeatureException $refusal) {
    printf("named unsupported field: %s\n", substr($refusal->getMessage(), 0, 110));
}

// -----------------------------------------------------------------------------------
// The same document drives the command line, which reads a request on stdin and writes
// a result on stdout -- which is how the cross-language conformance harness talks to
// every engine:
//
//     echo '<request json>' | php bin/packvium
// -----------------------------------------------------------------------------------
echo "\nthe CLI takes exactly the document above:  echo '...' | php bin/packvium\n";
