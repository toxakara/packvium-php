<?php
/**
 * Turn a packing result into instructions someone can follow on a dock.
 *
 * Run it:
 *
 *     php examples/execution.php
 *
 * `pack()` answers where every box goes. That answer is not yet a work order: it does not
 * say what to lift first, and it does not separate what the solver *decided* from what a
 * screen should *say*.
 *
 * The execution plan is that second document. It is derived from an already validated
 * result -- it calls no solver and no validator, and a test in each language asserts so.
 * Anything it could decide on its own would be a decision made twice.
 *
 * The scene below is the same one `examples/execution.py` builds, and the plan is held to
 * a byte-identical canonical form across all four engines. The Python file continues past
 * this one into operator locks, which are Python-only.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Execution\Plan;
use Packvium\Serialization\ArrayCodec;

/**
 * One crate, a printer that must stay upright, four toner cartridges, and a pallet jack
 * that was never going to fit. The last one is deliberate: an execution plan has to say
 * what is *not* going on the truck as clearly as what is.
 */
$request = [
    'units' => ['length' => 'mm'],
    'configuration' => [
        'objective' => 'default',
        'profile' => 'balanced',
        'seed' => 42,
        // A safety fuse, not a target -- nothing in this scene comes close to it.
        'time_limit_ms' => 60000,
    ],
    'items' => [
        ['id' => 'printer', 'quantity' => 1, 'weight' => '9 kg', 'keep_upright' => true,
         'dimensions' => ['length' => '420', 'width' => '340', 'height' => '260']],
        ['id' => 'toner', 'quantity' => 4, 'weight' => '900 g',
         'dimensions' => ['length' => '180', 'width' => '120', 'height' => '100']],
        ['id' => 'pallet-jack', 'quantity' => 1, 'weight' => '80 kg',
         'dimensions' => ['length' => '1200', 'width' => '550', 'height' => '1200']],
    ],
    'containers' => [
        ['id' => 'crate', 'quantity' => 1, 'max_payload' => '30 kg',
         'inner_dimensions' => ['length' => '600', 'width' => '400', 'height' => '400']],
    ],
];

$result = ArrayCodec::pack($request);
$plan = Plan::build($request, $result);
$container = $plan['containers'][0];

$rule = str_repeat('=', 78);

echo $rule, PHP_EOL;
echo '1. What the solver decided, kept apart from what a screen says', PHP_EOL;
echo $rule, PHP_EOL, PHP_EOL;

printf("  format:          %s\n", $plan['format']);
printf("  status:          %s\n", $plan['facts']['status']);
printf("  containers used: %d\n", $plan['facts']['container_count']);
printf("  score:           [%s]\n", implode(', ', $plan['facts']['score']));
printf("  utilization:     %s\n", $container['facts']['volume_utilization']);
echo PHP_EOL;
echo "  Everything above is under `facts`. It is the solver's own answer, copied and", PHP_EOL;
echo '  not re-derived, so a downstream system that reads only `facts` loses nothing it', PHP_EOL;
echo '  is entitled to rely on. The score stays a vector: collapsing five axes into one', PHP_EOL;
echo '  number is a judgement about your priorities that this document does not make.', PHP_EOL;
echo PHP_EOL;

echo $rule, PHP_EOL;
echo '2. The step order is injected, or it is honestly absent', PHP_EOL;
echo $rule, PHP_EOL, PHP_EOL;

printf("  order: %s\n", $container['order']);
foreach ($container['steps'] as $step) {
    $reference = $step['placement'];
    $ticks = $reference['position_ticks'];
    printf("    %-9s %s  at (%d, %d, %d)\n", $reference['item_type'],
        $reference['orientation'], $ticks['x'], $ticks['y'], $ticks['z']);
}
echo PHP_EOL;
echo '  Every placement is listed and not one is numbered. The engines compute a safe', PHP_EOL;
echo '  loading order from geometry this adapter never sees, so without one it says', PHP_EOL;
echo '  `unavailable` rather than guessing.', PHP_EOL;
echo PHP_EOL;
echo '  There is no third behaviour on purpose. Falling back to the order placements', PHP_EOL;
echo '  happen to appear in would present an artifact of how the solver walked its', PHP_EOL;
echo '  candidate points as an order that is safe to lift boxes in. It is not.', PHP_EOL;
echo PHP_EOL;

$reversed = array_reverse(array_keys($container['steps']));
$ordered = Plan::build($request, $result, [0 => $reversed]);
printf("  order: %s\n", $ordered['containers'][0]['order']);
foreach ($ordered['containers'][0]['steps'] as $step) {
    printf("    %d. %s\n", $step['sequence'], $step['placement']['item_type']);
}
echo PHP_EOL;
echo '  Hand it an order and each step is numbered. The order above is reversed on', PHP_EOL;
echo '  purpose, to show that the sequence is the one you supplied and not one the', PHP_EOL;
echo '  adapter re-derived behind your back.', PHP_EOL;
echo PHP_EOL;

echo $rule, PHP_EOL;
echo '3. Every sentence names the fields it was built from', PHP_EOL;
echo $rule, PHP_EOL, PHP_EOL;

foreach ($plan['unplaced'] as $entry) {
    $facts = $entry['facts'];
    printf("  facts:        item_type='%s'\n", $facts['item_type']);
    printf("                reason='%s' proof_level='%s'\n", $facts['reason'], $facts['proof_level']);
    printf("  presentation: %s\n", $entry['presentation']['summary']);
    printf("  cites:        %s\n", implode(', ', $entry['presentation']['cites']));
}
echo PHP_EOL;
echo '  `proven` is a claim about a search, not a summary of one: no orientation of the', PHP_EOL;
echo '  pallet jack fits any offered crate, so nothing was tried and nothing needed to', PHP_EOL;
echo '  be. A reason with no citation would be a sentence nobody can check, which is', PHP_EOL;
echo '  why `cites` is part of the format rather than a convention.', PHP_EOL;
echo PHP_EOL;

echo $rule, PHP_EOL;
echo '4. One plan, four engines, the same bytes', PHP_EOL;
echo $rule, PHP_EOL, PHP_EOL;

$canonical = Plan::canonicalJson($plan);
printf("  canonical form: %d bytes, first 68 of them\n", strlen($canonical));
printf("    %s...\n", substr($canonical, 0, 68));
echo PHP_EOL;
echo '  Hand the same *result* to all four adapters and they emit the same bytes. That', PHP_EOL;
echo '  is stricter than the packing contract, and it can be: a plan is derived from a', PHP_EOL;
echo '  result, so there is nothing left to differ about.', PHP_EOL;
echo PHP_EOL;
echo '  It does not follow that four engines packing the same *request* agree. Python', PHP_EOL;
echo '  and PHP are held to identical placements, which is why this file and', PHP_EOL;
echo '  examples/execution.py print the same byte count; Rust and JavaScript are held to', PHP_EOL;
echo '  a valid answer at or above the objective floor, and the Node example prints 1280', PHP_EOL;
echo '  bytes rather than 1877 for that reason.', PHP_EOL;
echo PHP_EOL;
echo '  Operator locks continue this story and are Python-only: a lock has no', PHP_EOL;
echo '  representation in the request schema, so there is nothing to hand another engine.', PHP_EOL;
