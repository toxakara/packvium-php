# Contributing

Thanks for looking. This library is small, dependency-free and deliberately strict about
exactness — please keep it that way.

## Getting set up

```bash
composer install
composer test          # php tests/run.php
composer lint          # php -l over every source, test, example and the CLI
```

The suite should be green before you change anything, so that any later failure is yours.

## The rules that matter here

**No floats in geometry or feasibility.** Every length is an integer number of ticks
(1/16000 mm) and every weight an integer number of 1/8 µg. If a change makes a placement
decision depend on floating point, it is wrong even if the tests pass — see
[docs/UNITS-AND-NUMERICS.md](docs/UNITS-AND-NUMERICS.md).

This matters more in PHP than elsewhere. A container volume in cubic ticks overflows a
64-bit integer — a one-metre cube is 4.096 × 10²¹ — so volume arithmetic goes through
`Packvium\Support\BigInt`, which works on decimal strings. Do not "simplify" it to
`(float)` casts, and do not reach for `bcmath` or `gmp`: neither is a guaranteed
extension, and requiring one would break installs.

**No runtime dependencies, no framework coupling.** `declare(strict_types=1)` in every
file, PSR-12 style, PSR-4 autoloading. No facades, no service locators, no static state.
Constructor injection only.

**Inputs are immutable.** Domain objects are `readonly`. A pack call must never mutate the
items or containers it was given.

**Determinism.** The same input and seed must produce the same output. Sort explicitly
wherever order can reach a result; `Packvium\Support\StableSorter` exists for that.

**Every fixed defect gets a regression test.** Not "a test somewhere" — a test that fails
before your fix and passes after.

## Behavioural changes

This package is one of three independent implementations of the same documented contract;
the Python and Rust ports must produce identical placements for the same request. That
means:

- A change to solver behaviour, the objective vector, serialization field names or status
  semantics is a **cross-language change**. Open an issue describing it before writing
  code, so the other implementations move with it.
- A change confined to PHP internals — types, refactoring, performance without a change
  in output — is local and needs no coordination.

If you are unsure which kind you have, run your change against the examples and see
whether any placement moves.

## Pull requests

- One logical change per pull request.
- Commit messages in imperative mood, under 72 characters:
  `type(scope): description` with `feat`, `fix`, `refactor`, `chore`, `docs` or `test`.
- Add or update tests. The suite must pass on PHP 8.2 through 8.4.
- `composer validate --strict` must stay clean. Do **not** add a `version` field —
  Packagist infers it from Git tags.
- Update the relevant document under `docs/` when you change behaviour. Complexity,
  limitations and failure modes are part of the change, not follow-up work.

## Reporting a bug

Please include the full request that reproduces it — items, containers and configuration —
and what you expected instead. A packing bug is nearly impossible to act on without the
exact input.

For anything with security implications, follow [SECURITY.md](SECURITY.md) rather than
opening a public issue.
