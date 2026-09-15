<?php
/**
 * Hand a packing result to a warehouse system that has no engine.
 *
 * Run it:
 *
 *     php examples/artifacts.php
 *
 * An execution plan says what to lift first. A warehouse or transport system needs more than
 * that before it can act on its own: how big each box is, what the load weighs, a sheet to
 * print for the dock, and a record of which request and solver produced it.
 *
 * The operational artifact is that one document. It wraps the plan unchanged and adds geometry,
 * display values and provenance, and it exports to JSON, CSV and a printable HTML work order
 * without calling a solver, a renderer or a clock. PHP, Python, Rust and JavaScript build the
 * same bytes from the same request and result.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Artifacts\ArtifactExports;
use Packvium\Artifacts\OperationalArtifact;
use Packvium\Artifacts\OperationalArtifactException;
use Packvium\Serialization\ArrayCodec;

$request = [
    'units' => ['length' => 'mm'],
    'configuration' => [
        // A safety fuse far above what this solve needs, so the answer never depends on load.
        'time_limit_ms' => 60000,
    ],
    'items' => [
        ['id' => 'printer', 'quantity' => 1, 'weight' => '12 kg',
         'dimensions' => ['length' => '420', 'width' => '300', 'height' => '250'],
         'metadata' => ['sales_order' => 'SO-1042']],
        ['id' => 'toner', 'quantity' => 3, 'weight' => '1.5 kg',
         'dimensions' => ['length' => '300', 'width' => '100', 'height' => '100']],
    ],
    'containers' => [
        ['id' => 'crate', 'quantity' => 1,
         'inner_dimensions' => ['length' => '800', 'width' => '400', 'height' => '400']],
    ],
];

$result = ArrayCodec::pack($request);
$rule = str_repeat('=', 78);

function section(string $rule, string $title): void
{
    echo PHP_EOL, $rule, PHP_EOL, $title, PHP_EOL, $rule, PHP_EOL;
}

section($rule, '1. One document that carries everything a consumer needs');

// No loading order is passed, so the plan lists every placement unnumbered. An engine's
// sequence API supplies a safe order; the artifact never invents one.
$artifact = OperationalArtifact::build($request, $result);
printf("  format:          %s\n", $artifact['format']);
printf("  plan steps:      %d\n", count($artifact['plan']['containers'][0]['steps']));
printf("  crate inside:    %s ticks long\n", $artifact['geometry']['containers'][0]['inner_dimensions']['length']);
printf("  order:           %s\n", $artifact['plan']['containers'][0]['order']);
printf("  sales order:     %s\n", $artifact['provenance']['request']['items'][0]['metadata']['sales_order']);
echo <<<TEXT

  The request is inside the artifact, not a hash of it: only the request itself lets
  someone replay the artifact without a lookup. The order is `unavailable` because
  none was supplied, and the work order says so instead of numbering boxes by
  accident.

TEXT;

section($rule, '2. A CSV a warehouse system can import');

foreach (array_slice(explode("\r\n", ArtifactExports::csv($artifact)), 0, 3) as $row) {
    echo '  ', $row, PHP_EOL;
}
echo <<<TEXT

  One row per step, then one per item that was not packed. The tick columns are the
  identifiers a system matches on; the rendered columns are for people.

TEXT;

section($rule, '3. A work order to print');

$html = ArtifactExports::workOrderHtml($artifact);
printf("  %d bytes of HTML, one section per container, one checkbox per step\n", strlen($html));
printf("  contains a script or an external resource: %s\n",
    (strpos($html, '<script') !== false || strpos($html, 'http') !== false) ? 'yes' : 'no');

section($rule, '4. The same bytes in every engine, and a refusal instead of a guess');

$canonical = ArtifactExports::json($artifact);
printf("  canonical JSON: %d bytes, starting %s...\n", strlen($canonical), substr($canonical, 0, 40));
try {
    ArtifactExports::csv(['format' => 'packvium-operational-artifact/v2'] + $artifact);
} catch (OperationalArtifactException $error) {
    printf("  a v2 document: refused with %s\n", $error->errorCode());
}
echo <<<TEXT

  The canonical form is RFC 8785, so Python, Rust and JavaScript build these exact
  bytes from the same request and result. A reader that meets a format it does not
  know refuses it by name rather than printing half of it.

TEXT;
