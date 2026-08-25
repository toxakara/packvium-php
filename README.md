# Packvium for PHP

Deterministic 3D cartonization and rectangular bin packing. Pure PHP, **no runtime
dependencies**, exact integer geometry.

> **Version 0.1.3 — early release.** The public API is not frozen; pin an exact version.
> Read [docs/GUARANTEES.md](docs/GUARANTEES.md) before relying on a result.

```bash
composer require packvium/packvium
```

## Quick start

```php
<?php
use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container, Dimensions, Item};
use Packvium\Packer;

$result = (new Packer(PackingConfig::balanced()))->pack(
    [Item::create('book', Dimensions::mm('210', '140', '30'), quantity: 4)],
    [Container::create('box', Dimensions::mm('400', '300', '250'))],
);

echo $result->status->value, PHP_EOL;          // feasible
foreach ($result->containers as $container) {
    foreach ($container->placements as $placement) {
        echo $placement->instance->id(), ' ', $placement->rotation->value, PHP_EOL;
    }
}
```

Fractional inches are exact, not approximated:

```php
Dimensions::inches('12 3/8', '8 1/2', '3/4');
```

There is also a CLI that reads a JSON request on standard input:

```bash
echo '{"items":[{"id":"box","quantity":8,"dimensions":{"length":"50","width":"50","height":"50"}}],
       "containers":[{"id":"carton","inner_dimensions":{"length":"100","width":"100","height":"100"}}]}' \
  | vendor/bin/packvium
```

The library is framework-independent: no facades, no static state, no container bindings.
Construct a `Packer` and call it.

## Examples

Runnable, in [`examples/`](examples). Each one is a single file you can read top to bottom
and execute without a project around it. Every one of them is executed by the test suite
on each release, so none of them can quietly stop working.

New here? Read `basic.php`, then `objectives.php` — between them they cover what most
callers need. `units.php` and `serialization.php` explain the two design choices that
surprise people.

| File | What it shows |
| --- | --- |
| [`basic.php`](examples/basic.php) | The smallest useful call: items in, placements out — and the three details in it that are easy to miss. |
| [`objectives.php`](examples/objectives.php) | All six objectives on scenes where they genuinely disagree, including the rate card that makes the heavier shipment the cheaper one. |
| [`constraints.php`](examples/constraints.php) | Upright-only, floor-only, non-stackable, top-load limits, and tags that keep two items out of the same box — plus how to read the reason an item was refused. |
| [`units.php`](examples/units.php) | Why there are no floats anywhere: fractional inches, exact ticks, and the one-tick difference between a fit and a refusal. |
| [`serialization.php`](examples/serialization.php) | The same request as JSON, the result in full, and exactly which mistakes are refused and which are silently ignored. |
| [`nested.php`](examples/nested.php) | Units into cartons, cartons onto a pallet, in one call. |
| [`commerce.php`](examples/commerce.php) | Rate a shipment, apply an eligibility rule, and pin a catalog version. |

```bash
php examples/objectives.php
```

`constraints.php` and `nested.php` print byte-for-byte what their Python counterparts
print, which is the cross-language contract this port is held to, not a coincidence.

## What it does

- **Exact arithmetic.** Length is measured in ticks of 1/16000 mm and weight in 1/8 µg.
  No coordinate is ever a float, so no placement decision depends on rounding. Volumes
  that exceed `PHP_INT_MAX` are handled in exact decimal-string arithmetic rather than
  losing precision.
- **Real constraints.** Weight and payload limits, permitted rotations, keep-upright,
  floor-only, non-stackable, top-load limits, minimum support ratio, tag incompatibility,
  clearance and rectangular obstacles.
- **A solver portfolio, not one algorithm.** Regular-grid, layer, extreme-point,
  maximal-space and bounded exact search, selected by problem shape and profile.
- **Answers you can check.** Every solution is re-validated by logic independent of the
  search. Unplaced items come back with a reason code, not silently missing.
- **Deterministic.** The same input and seed produce the same result, always.
- **Multi-container and nested.** Split across containers, or pack containers into
  containers.
- **Extensible.** Register your own constraints, item orderings, candidate scorers,
  container selectors or complete solvers.

## Documentation

| Document | Covers |
| --- | --- |
| [docs/GUARANTEES.md](docs/GUARANTEES.md) | What is promised and what is not. Start here. |
| [docs/PUBLIC-API.md](docs/PUBLIC-API.md) | Inputs, outputs and status semantics. |
| [docs/UNITS-AND-NUMERICS.md](docs/UNITS-AND-NUMERICS.md) | Units, accepted input forms, rounding policy. |

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
| Rust — [`packvium`](https://crates.io/crates/packvium) | `packvium = "0.1"` | [packvium-rust](https://github.com/toxakara/packvium-rust) |
| Node.js — [`@packvium/engine`](https://www.npmjs.com/package/@packvium/engine) | `npm install @packvium/engine` | [packvium-node](https://github.com/toxakara/packvium-node) |
| Browser / WebAssembly — [`@packvium/browser`](https://www.npmjs.com/package/@packvium/browser) | `npm install @packvium/browser` | [packvium-wasm](https://github.com/toxakara/packvium-wasm) |
| PHP FFI bridge — [`packvium/native-bridge`](https://packagist.org/packages/packvium/native-bridge) | `composer require packvium/native-bridge` | [packvium-php-bridge](https://github.com/toxakara/packvium-php-bridge) |
| Python native selector — `packvium-native` | from source until the native wheels ship | [packvium-python-adapter](https://github.com/toxakara/packvium-python-adapter) |

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security reports go through the process in
[SECURITY.md](SECURITY.md), not public issues.

## License

MIT. See [LICENSE](LICENSE).
