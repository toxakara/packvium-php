# Packvium for PHP

Deterministic 3D cartonization and rectangular bin packing. Pure PHP, **no runtime
dependencies**, exact integer geometry.

Use it to pick the smallest carton for an order, build a pallet, load a shipping container,
or load a truck within its axle ratings and delivery-stop order. Every answer comes back as
coordinates and rotations for each item, with a reason for anything that did not fit.

Full documentation, the constraint reference and benchmarks live at
[packvium.com](https://packvium.com).

> **Version 1.5.0 — the public API is frozen.** Field names, status codes and the
> objective vector do not change without a major version, so any `1.x` is a safe upgrade
> from any earlier `1.x`.
> Read [docs/GUARANTEES.md](https://github.com/toxakara/packvium-php/blob/main/docs/GUARANTEES.md)
> before relying on a result.

```bash
composer require packvium/packvium
```

The library runs on PHP 7.3 and newer. The quick start below and the shipped examples are
written for **PHP 8.2 or newer**; [Older PHP](#older-php-73-to-81) shows what changes below
that.

## Quick start

```php
<?php
use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container, Dimensions, Item};
use Packvium\Packer;

$result = (new Packer(PackingConfig::balanced(timeLimitMs: 60000)))->pack(
    [Item::create('book', Dimensions::mm('210', '140', '30'), '450 g', quantity: 4)],
    [Container::create('box', Dimensions::mm('400', '300', '250'))],
);

echo $result->status->value, PHP_EOL;          // feasible
foreach ($result->containers as $container) {
    foreach ($container->placements as $placement) {
        echo $placement->instance->id(), ' ', $placement->rotation->value, PHP_EOL;
    }
}
```

The same request can be written as a plain array, the JSON document every Packvium
engine reads. This form works unchanged on every supported PHP version:

```php
use Packvium\Serialization\ArrayCodec;

$result = ArrayCodec::pack([
    'units' => ['length' => 'mm'],
    'configuration' => ['time_limit_ms' => 60000],
    'items' => [['id' => 'book', 'quantity' => 4, 'weight' => '450 g',
                 'dimensions' => ['length' => '210', 'width' => '140', 'height' => '30']]],
    'containers' => [['id' => 'box',
                      'inner_dimensions' => ['length' => '400', 'width' => '300', 'height' => '250']]],
]);
echo $result['status'], PHP_EOL;               // feasible
```

There is also a CLI that reads the same JSON request on standard input:

```bash
echo '{"items":[{"id":"box","quantity":8,"dimensions":{"length":"50","width":"50","height":"50"}}],
       "containers":[{"id":"carton","inner_dimensions":{"length":"100","width":"100","height":"100"}}]}' \
  | vendor/bin/packvium
```

The library is framework-independent: no facades, no static state, no container bindings.
Construct a `Packer` and call it.

## Units

Every length is an integer number of ticks of 1/16000 mm, and every weight an integer
number of 1/8 µg. Nothing on the path from request to score is a float, so no placement
decision depends on rounding, and an imperial spec sheet and a metric container agree
without a tolerance:

```php
Dimensions::inches('12 3/8', '8 1/2', '3/4');  // exact: fractions, mixed fractions, decimals
Dimensions::mm('210', '140', '30');
Item::create('mug', Dimensions::mm('100', '100', '120'), '12 oz');
```

Lengths accept `mm`, `cm`, `m`, `in`, `ft` and ticks; weights `mg`, `g`, `kg`, `oz` and `lb`.
Pass numbers as strings or integers — the API has no float parameters for a measure. Round
item exteriors outward and container interiors inward before building the request.
Volumes that exceed `PHP_INT_MAX` are handled in exact decimal-string arithmetic rather
than losing precision. See
[docs/UNITS-AND-NUMERICS.md](https://github.com/toxakara/packvium-php/blob/main/docs/UNITS-AND-NUMERICS.md)
and [`units.php`](https://github.com/toxakara/packvium-php/blob/main/examples/units.php).

## Determinism

The same request, seed and budget give the same result. The one thing that can break that
is the wall clock: `time_limit_ms` (`timeLimitMs`) stops the search when time runs out, and
how far a search gets in that time depends on the machine and its load. A search that
finishes inside its limit is reproducible; one the clock stopped is not.

`effort_budget` bounds the search by counted work instead — candidates evaluated,
placements attempted, search nodes expanded, portfolio restarts — so it stops at the same
point everywhere. Keep `time_limit_ms` as a generous safety fuse beside it:

```php
$result = ArrayCodec::pack([
    'configuration' => [
        'effort_budget' => ['max_search_nodes' => 20000],
        'time_limit_ms' => 60000,
    ],
    // items, containers ...
]);
$result['termination']['code'];                // "complete", "effort_limit" or "time_limit"
```

`termination` says which one stopped the search; only `time_limit` is a result you cannot
replay. In the object API the budget is `new PackingConfig(effortBudget: new EffortBudget(...))`;
`EffortBudget` lives in `Packvium\Algorithm`, the one namespace outside the frozen API, so
prefer the array form where you can.
[`reproducibility.php`](https://github.com/toxakara/packvium-php/blob/main/examples/reproducibility.php)
shows all three outcomes.

## Errors

A request that no engine may answer throws `Packvium\Serialization\InvalidRequestException`,
an `InvalidArgumentException`, before anything is solved. It names the problem instead of
describing it:

```php
use Packvium\Serialization\ArrayCodec;
use Packvium\Serialization\InvalidRequestException;

try {
    $result = ArrayCodec::pack($request);
} catch (InvalidRequestException $e) {
    $e->errorCode();  // "invalid_request"
    $e->reason();     // "below_minimum"
    $e->field();      // "/items/0/quantity" -- a JSON Pointer into your request
    $e->detail();     // "must be at least 1"
    $e->getMessage(); // "invalid_request: /items/0/quantity: must be at least 1"
}
```

- `reason()` is one of `missing_field`, `wrong_type`, `below_minimum`, `above_maximum`,
  `negative_measure`, `invalid_unit`, `duplicate_id`, `not_allowed` or `invalid_value`.
  Branch on `reason()` and `field()`; show the message to a person. The message is the
  same in every Packvium engine. When a request breaks several rules, the first is
  reported, in a fixed order: units, configuration, items, containers, fixed placements.
- `Packvium\Validation\FixedPlacementException` extends it, with code
  `invalid_fixed_placement` and reason `malformed` (an entry is not the right shape) or
  `cannot_hold` (the fixed items are not a valid packing on their own).
- `Packvium\Serialization\UnsupportedFeatureException` (code `unsupported_feature`) is a
  separate type: the request uses a field this engine has not implemented yet, and it is
  refused rather than silently ignored.
- An unknown `objective` or `access_directions` value in a request raises
  `InvalidRequestException` with reason `not_allowed` and a `field()` pointing to
  the bad value. Direct domain constructors may raise their own argument errors.

A request that is valid but does not fit completely is not an error: the result lists what
was left out, and why, in `unpacked_items`.
[`errors.php`](https://github.com/toxakara/packvium-php/blob/main/examples/errors.php) turns
each kind of refusal into the answer a web handler would send.

## Examples

Runnable, in [`examples/`](https://github.com/toxakara/packvium-php/tree/main/examples). Each
one is a single file you can read top to bottom and execute without a project around it.
Every one of them is executed by the test suite on each release, so none of them can
quietly stop working. They are PHP 8.2 code (named arguments, enums); run them on 8.2 or
newer.

New here? Read `basic.php`, then `objectives.php` — between them they cover what most
callers need. `units.php` and `serialization.php` explain the two design choices that
surprise people.

| File | What it shows |
| --- | --- |
| [`basic.php`](https://github.com/toxakara/packvium-php/blob/main/examples/basic.php) | The smallest useful call: items in, placements out — and the three details in it that are easy to miss. |
| [`objectives.php`](https://github.com/toxakara/packvium-php/blob/main/examples/objectives.php) | All six objectives on scenes where they genuinely disagree, including the rate card that makes the heavier shipment the cheaper one. |
| [`constraints.php`](https://github.com/toxakara/packvium-php/blob/main/examples/constraints.php) | Upright-only, floor-only, non-stackable, top-load limits, and tags that keep two items out of the same box — plus how to read the reason an item was refused. |
| [`limits.php`](https://github.com/toxakara/packvium-php/blob/main/examples/limits.php) | A courier's refrigerated van and cargo bikes: obstacles built from `additional_boxes`, `max_items`, `tag_limits`, `eligible_container_tags` and `max_containers`, and what each one costs. |
| [`units.php`](https://github.com/toxakara/packvium-php/blob/main/examples/units.php) | Why there are no floats anywhere: fractional inches, exact ticks, and the one-tick difference between a fit and a refusal. |
| [`serialization.php`](https://github.com/toxakara/packvium-php/blob/main/examples/serialization.php) | The same request as JSON, the result in full, runners-up via `alternatives`, and exactly which mistakes are refused and which are silently ignored. |
| [`errors.php`](https://github.com/toxakara/packvium-php/blob/main/examples/errors.php) | A web handler that turns every refusal into a 422 naming the item and the value: each `InvalidRequestException` reason, the fixed-placement refusals, and the oversize order that is not an error at all. |
| [`reproducibility.php`](https://github.com/toxakara/packvium-php/blob/main/examples/reproducibility.php) | `effortBudget` against `timeLimitMs` on four simulated hosts: a clock-stopped search that answers differently on each, a budget that answers the same on all of them, and how to read `termination`. |
| [`trucking.php`](https://github.com/toxakara/packvium-php/blob/main/examples/trucking.php) | A van with two axle ratings, a rear door and three stops: the route-legal plan, axle loads, the unloading and loading order, what the driver can reach at the first stop, and what goes wrong without `stopIndex`. |
| [`fixed_placements.php`](https://github.com/toxakara/packvium-php/blob/main/examples/fixed_placements.php) | Pack a trailer that already has four pallets on board: packing around them, the cost of one left in the wrong place, and the refusals when the record is impossible. |
| [`shapes.php`](https://github.com/toxakara/packvium-php/blob/main/examples/shapes.php) | Items that are not their box: complementary wedges sharing one crate as `convex_hull`, and a cushion that compresses under load until the crush limit refuses it. |
| [`nested.php`](https://github.com/toxakara/packvium-php/blob/main/examples/nested.php) | Units into cartons, cartons onto a pallet, in one call. |
| [`extensions.php`](https://github.com/toxakara/packvium-php/blob/main/examples/extensions.php) | Rules the request has no field for: a custom placement constraint and container selector through `ExtensionRegistry`, a custom solution scorer — and why a rule everyone must obey belongs in the request instead. |
| [`commerce.php`](https://github.com/toxakara/packvium-php/blob/main/examples/commerce.php) | Rate a shipment, apply an eligibility rule, and pin a catalog version. |
| [`execution.php`](https://github.com/toxakara/packvium-php/blob/main/examples/execution.php) | Turn a result into dock instructions: solver facts kept apart from screen text, and a step order that is injected or honestly absent — byte-identical to the other three engines. |
| [`artifacts.php`](https://github.com/toxakara/packvium-php/blob/main/examples/artifacts.php) | Hand a result to a system with no engine: one document with the plan, geometry and the request that produced it, exported as CSV and a printable HTML work order — byte-identical to the other three engines. |
| [`revisions.php`](https://github.com/toxakara/packvium-php/blob/main/examples/revisions.php) | Replan a half-loaded job: a missing item and a locked placement recorded against the approved plan, a replan that keeps the locked item in place, and a hash-chained record that notices an edit — the same digests as the other three engines. |

```bash
php examples/objectives.php
```

`limits.php`, `fixed_placements.php`, `nested.php` and `shapes.php` print byte for byte
what their [Python counterparts](https://github.com/toxakara/packvium-python/tree/main/examples)
print. `trucking.php`, `errors.php`, `reproducibility.php` and `constraints.php` differ only
where a line names a PHP spelling (`stopIndex`, `allowedRotations`, `true`) or a
language's own exception class. That is the cross-language contract this port is held to —
identical placements for the same request — not a coincidence. The other examples print
PHP values (`[0,1,180]` where Python prints `(0, 1, 180)`), so their text differs even
where their answers agree.

## What it does

- **Exact arithmetic.** Length is measured in ticks of 1/16000 mm and weight in 1/8 µg.
  No coordinate is ever a float, so no placement decision depends on rounding.
- **Real constraints.** Weight and payload limits, permitted rotations, keep-upright,
  floor-only, non-stackable, top-load limits, minimum support ratio, tag incompatibility
  and per-container tag limits, item-count limits, clearance, obstacles built from unions
  of boxes, two-axle load limits, delivery stops and door access.
- **A solver portfolio, not one algorithm.** Regular-grid, layer, extreme-point,
  maximal-space and bounded exact search, selected by problem shape and profile.
- **Answers you can check.** Every solution is re-validated by logic independent of the
  search. Unplaced items come back with a reason code, not silently missing.
- **Deterministic.** The same input, seed and effort budget produce the same result, and
  the result says when a clock, not the budget, stopped the search.
- **Multi-container and nested.** Split across containers, or pack containers into
  containers.
- **Extensible.** Register your own constraints, item orderings, candidate scorers,
  container selectors or complete solvers.
- **Loading and unloading order.** `Packvium\Sequence` answers which placements a door can
  reach right now and gives a safe loading order, or an unloading order that empties each
  stop before the next.
- **Work orders and portable artifacts.** `Packvium\Execution\Plan` turns a result into an
  operator's step list. `Packvium\Artifacts\OperationalArtifact` wraps that plan with
  geometry, display values and the request that produced it, and `ArtifactExports` writes it
  as canonical JSON, CSV or a self-contained HTML work order, byte for byte what the Python,
  Rust and JavaScript packages write.
- **Items already in place, and replanning around them.** A request's `fixed_placements`
  pins items to known positions before the solve: they keep their place, carry weight and
  support, and come back marked `fixed: true`. `Packvium\Revisions\PlanRevision` records what
  changed on the dock — a missing item, a substituted container, a lock, a verification — as
  an append-only chain linked by SHA-256, and derives the request the next plan solves.

## Documentation

| Document | Covers |
| --- | --- |
| [docs/GUARANTEES.md](https://github.com/toxakara/packvium-php/blob/main/docs/GUARANTEES.md) | What is promised and what is not. Start here. |
| [docs/PUBLIC-API.md](https://github.com/toxakara/packvium-php/blob/main/docs/PUBLIC-API.md) | Inputs, outputs and status semantics. |
| [docs/UNITS-AND-NUMERICS.md](https://github.com/toxakara/packvium-php/blob/main/docs/UNITS-AND-NUMERICS.md) | Units, accepted input forms, rounding policy. |
| [docs/COMMERCE-API.md](https://github.com/toxakara/packvium-php/blob/main/docs/COMMERCE-API.md) | Carrier rating, policy evaluation and catalog versions. |

## Requirements

PHP 7.3 or newer. No extensions required — `bcmath` and `gmp` are deliberately not
depended upon.

One package name covers the whole range. It carries two source trees — the canonical
PHP 8.2+ `src/` and `src-legacy/`, generated from it by a pinned AST downgrade — and
`autoload.php` selects one by `PHP_VERSION_ID` before Composer's PSR-4 map gets a chance
to load the wrong one. PHP parses only what it loads. There is no wrapper, no second
package and nothing to configure; namespaces, classes and results are identical either
way, and both trees are held to the same committed placement results on every supported
version.

PHP 7.3, 7.4, 8.0 and 8.1 are end-of-life and receive no upstream security fixes. Packvium
runs there so that an upgrade does not have to block your project — not so that it can be
postponed.

### Older PHP (7.3 to 8.1)

Two things differ from the quick start: one is PHP syntax your version does not have, the
other is how the downgraded tree represents enums.

- **No named arguments** (they need PHP 8.0). Pass arguments positionally, filling the
  defaults you skip: `Item::create('book', $dimensions, '450 g', 4)`.
- **Enums are strings.** Below 8.2 `PackingStatus`, `Rotation`, `SolverProfile`,
  `ShapeType` and `Rounding` are classes of string constants, so `$result->status` is the
  string `'feasible'` itself — there is no `->value`. `Rotation::LWH` is the string `'LWH'`.

```php
$result = (new Packer(PackingConfig::balanced(60000)))->pack(
    [Item::create('book', Dimensions::mm('210', '140', '30'), '450 g', 4)],
    [Container::create('box', Dimensions::mm('400', '300', '250'))]
);

echo $result->status, PHP_EOL;                 // feasible
foreach ($result->containers as $container) {
    foreach ($container->placements as $placement) {
        echo $placement->instance->id(), ' ', $placement->rotation, PHP_EOL;
    }
}
```

The array API (`ArrayCodec::pack`) takes and returns plain arrays of strings and integers,
so code written against it is the same on every version. The shipped examples are 8.2
code and are not meant to run below it.

## The Packvium family

One request and result contract, implemented independently in four engines (Rust,
Python, PHP, JavaScript) and held to identical placements on a shared fixture set.
Pick the package for your stack; mixing them in one system is safe.

Documentation, the constraint reference and the benchmarks are at
[packvium.com](https://packvium.com).

| Package | Install | Source |
| --- | --- | --- |
| Python — [`packvium`](https://pypi.org/project/packvium/) | `pip install packvium` | [packvium-python](https://github.com/toxakara/packvium-python) |
| PHP — [`packvium/packvium`](https://packagist.org/packages/packvium/packvium) | `composer require packvium/packvium` | [packvium-php](https://github.com/toxakara/packvium-php) |
| Rust — [`packvium`](https://crates.io/crates/packvium) | `packvium = "1.0"` | [packvium-rust](https://github.com/toxakara/packvium-rust) |
| Node.js — [`@packvium/engine`](https://www.npmjs.com/package/@packvium/engine) | `npm install @packvium/engine` | [packvium-node](https://github.com/toxakara/packvium-node) |
| Browser / WebAssembly — [`@packvium/browser`](https://www.npmjs.com/package/@packvium/browser) | `npm install @packvium/browser` | [packvium-wasm](https://github.com/toxakara/packvium-wasm) |
| PHP FFI bridge — [`packvium/native-bridge`](https://packagist.org/packages/packvium/native-bridge) | `composer require packvium/native-bridge` | [packvium-php-bridge](https://github.com/toxakara/packvium-php-bridge) |
| Python native selector — `packvium-native` | from source until the native wheels ship | [packvium-python-adapter](https://github.com/toxakara/packvium-python-adapter) |

## Contributing

See [CONTRIBUTING.md](https://github.com/toxakara/packvium-php/blob/main/CONTRIBUTING.md).
Security reports go through the process in
[SECURITY.md](https://github.com/toxakara/packvium-php/blob/main/SECURITY.md), not public
issues.

## Citation

If Packvium supports your research, cite it as software. GitHub's **Cite this repository**
button reads [`CITATION.cff`](https://github.com/toxakara/packvium-php/blob/main/CITATION.cff), and
[`codemeta.json`](https://github.com/toxakara/packvium-php/blob/main/codemeta.json) carries the same record in
CodeMeta form.

```bibtex
@software{packvium_php,
  author  = {{Packvium contributors}},
  title   = {Packvium for PHP},
  version = {1.5.0},
  license = {MIT},
  url     = {https://packvium.com}
}
```

## License

MIT. See [LICENSE](https://github.com/toxakara/packvium-php/blob/main/LICENSE).
