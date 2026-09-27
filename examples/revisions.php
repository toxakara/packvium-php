<?php
/**
 * Replan a half-loaded job without losing what was already done, or the record of why.
 *
 * Run it:
 *
 *     php examples/revisions.php
 *
 * A plan is approved, and the dock starts loading. Then something changes: a slab is missing
 * from the shelf, and a cube that is already in the tote must stay exactly where it is. The next
 * plan has to keep the cube in place and pack around it, and anyone auditing the job later has
 * to see what changed, in what order, and which approved plan each change was recorded against.
 *
 * A plan revision is that record. It is append-only and hash-chained: each revision names its
 * parent and the artifact it replaced by SHA-256, and carries the request the events produce.
 * The replan is an ordinary solve of that request. Every Packvium engine computes the same
 * revision bytes from the same inputs, on PHP 7.3 through `src-legacy/` as well.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Artifacts\OperationalArtifact;
use Packvium\Revisions\PlanRevision;
use Packvium\Revisions\PlanRevisionException;
use Packvium\Revisions\RevisionIssue;
use Packvium\Serialization\ArrayCodec;

$request = [
    'units' => ['length' => 'mm'],
    'configuration' => [
        // Counted work decides where the search stops, not a clock: a plan the clock stopped
        // could not be replayed, and its revision would say `replay: not_guaranteed`. The time
        // limit is only a fuse, far above what this needs even on a slow or emulated host.
        'effort_budget' => ['max_search_nodes' => 20000],
        'time_limit_ms' => 60000,
    ],
    'items' => [
        ['id' => 'cube', 'quantity' => 4, 'weight' => '1 kg',
         'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100']],
        ['id' => 'slab', 'quantity' => 2, 'weight' => '2 kg',
         'dimensions' => ['length' => '200', 'width' => '100', 'height' => '50']],
    ],
    'containers' => [
        ['id' => 'tote', 'quantity' => 2,
         'inner_dimensions' => ['length' => '200', 'width' => '200', 'height' => '150']],
    ],
];
$rule = str_repeat('=', 78);

function section(string $rule, string $title): void
{
    echo "\n{$rule}\n{$title}\n{$rule}\n";
}

/**
 * A revision's request as the plain array the codec reads.
 *
 * @param stdClass|array<string,mixed> $request
 * @return array<string,mixed>
 */
function plain($request): array
{
    return json_decode(json_encode($request), true);
}

/**
 * Solve a request and wrap the answer as the artifact the dock works from.
 *
 * @param array<string,mixed> $request
 * @return array<string,mixed>
 */
function approve(array $request): array
{
    return OperationalArtifact::build($request, ArrayCodec::pack($request));
}

// --------------------------------------------------------------------------------------
section($rule, '1. The approved plan, and the revision that records it');

$root = PlanRevision::root($request);
$approved = approve(plain($root->request));
$firstStep = $approved['plan']['containers'][0]['steps'][0]['placement'];
echo "  revision {$root->revision}, parent " . var_export($root->parent, true) . "\n";
echo "  first step: a {$firstStep['item_type']} at x={$firstStep['position_ticks']['x']} in container 0\n";
echo <<<TEXT

  The root records nothing against the request. It exists so the first change has a
  parent to name.

TEXT;

// --------------------------------------------------------------------------------------
section($rule, '2. What happened on the dock, recorded against that plan');

$loaded = ArrayCodec::pack(plain($root->request))['containers'][0]['placements'][0];
$events = [
    ['sequence' => 1, 'type' => 'placement_locked', 'placement' => [
        'item_type' => 'cube', 'container_type' => 'tote', 'container_instance' => 1,
        'position' => [
            'x' => $loaded['position']['x']['value'],
            'y' => $loaded['position']['y']['value'],
            'z' => $loaded['position']['z']['value'],
        ],
        'orientation' => $loaded['orientation'],
    ]],
    ['sequence' => 2, 'type' => 'item_missing', 'item_type' => 'slab', 'quantity' => 1],
];
$revision = PlanRevision::derive($root, $approved, $events);
echo "  revision {$revision->revision}, parent " . substr($revision->parent, 0, 23) . "...\n";
echo '  approved artifact ' . substr($revision->approved->artifact, 0, 23)
    . "..., replay {$revision->approved->replay->level}\n";
echo "  slabs still to pack: {$revision->request->items[1]->quantity}\n";
echo '  fixed placements:    ' . count($revision->request->fixed_placements) . "\n";
echo <<<TEXT

  The events are applied to the request, not to the result: one slab fewer, and the
  cube becomes a fixed placement. The revision names its parent and the artifact it
  replaces by SHA-256 over their RFC 8785 bytes, so every engine computes the same
  digest.

TEXT;

// --------------------------------------------------------------------------------------
section($rule, '3. The replan keeps the cube where it is');

$replanned = ArrayCodec::pack(plain($revision->request));
$placed = 0;
foreach ($replanned['containers'] as $container) {
    foreach ($container['placements'] as $placement) {
        $placed++;
        if (!empty($placement['fixed'])) {
            echo "  fixed in the answer: {$placement['item_id']} in {$container['id']}\n";
        }
    }
}
echo "  items placed:        {$placed}\n";
echo <<<TEXT

  An ordinary solve of the derived request, with nothing remembered from the first
  one. The fixed cube is marked `fixed: true`, and the validator refuses any answer
  that moves it.

TEXT;

// --------------------------------------------------------------------------------------
section($rule, '4. An audit that notices tampering, and a refusal instead of a guess');

$codes = static fn(array $issues): string => implode(', ', array_map(
    static fn(RevisionIssue $issue): string => $issue->code,
    $issues
)) ?: 'no issues';
echo '  intact chain: ' . $codes(PlanRevision::verifyChain([$root, $revision], [null, $approved])) . "\n";
$edited = PlanRevision::parse(json_encode($revision));
$edited->request->items[1]->quantity = 2;
echo '  edited chain: ' . $codes(PlanRevision::verifyChain([$root, $edited])) . "\n";
try {
    PlanRevision::derive($revision, approve(plain($revision->request)), [
        ['sequence' => 3, 'type' => 'item_missing', 'item_type' => 'pallet', 'quantity' => 1],
    ]);
} catch (PlanRevisionException $error) {
    echo "  a pallet that was never requested: refused with {$error->errorCode()}\n";
}
echo <<<TEXT

  An edited request no longer equals what its parent's request and its own events
  produce, and any later revision would stop naming it as a parent. An event that
  contradicts the request is refused by name rather than applied as a best guess.

TEXT;
