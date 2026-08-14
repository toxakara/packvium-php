# Packvium for PHP

Deterministic 3D cartonization and rectangular bin packing. Pure PHP, **no runtime
dependencies**, exact integer geometry.

> **Version 0.1.0 — early release.** The public API is not frozen; pin an exact version.
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

PHP 8.2 or newer. No extensions required — `bcmath` and `gmp` are deliberately not
depended upon.

## Other ports exist

The same request and result contract is implemented independently in Python and Rust, and
all three are held to producing identical placements on a shared fixture set. If your
stack spans languages, you can compute a packing on any of them and get the same answer.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security reports go through the process in
[SECURITY.md](SECURITY.md), not public issues.

## License

MIT. See [LICENSE](LICENSE).
