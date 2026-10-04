# Changelog

What changed in `packvium/packvium` on Packagist, release by release. The format follows
[Keep a Changelog](https://keepachangelog.com/1.1.0/) and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.5.0]

No API changes. The beam search is faster with identical placements, a block answer reports
its real support, and a misspelt configuration key is now refused (see *Fixed*).

### Changed

- **Beam search 18–24% faster, same results.** The bound that ranks beam nodes reads prefix
  sums built once per step, one binary search per node instead of a `BigInt` addition per
  future item, and expansions that cannot beat the best plan so far are skipped early.

### Fixed

- **A block answer reports the support each item really has.** `HomogeneousBlockSolver` wrote
  `supportRatio` 1.0 for every item, including those of a block set on a smaller one, which
  overhang it. The bottom layer of each block now reports the share of its base that rests on
  something. With `minimumSupportRatio` above zero the block solver is no longer tried at all;
  its answer was built and then discarded by validation.
- **A misspelt `configuration` key is refused.** A key the request schema does not declare in
  `configuration` or its `effort_budget` (`profile` for `solver_profile`, `top_k` for
  `alternatives`) was silently ignored, so the request ran on defaults. `ArrayCodec::pack` now
  throws `InvalidRequestException` with reason `not_allowed` and the key's pointer, for example
  `/configuration/profile`.
- **A number past PHP's integer range is refused instead of changed.** A length or weight of
  `9223372036854775808` became `9223372036854775807`, and a decimal whose scaled value overflowed
  became a float, so the request was solved for a different size than the one sent. Both now
  throw `InvalidArgumentException` ("Scaled unit value exceeds integer range").
- **An unknown `objective` or access direction is refused with its pointer.** It was
  `invalid_value` with an empty field; it is now `not_allowed` with, for example,
  `/configuration/objective`.
- **Compressible items are no longer laid out as rigid blocks** by `HomogeneousBlockSolver`.
- **Refusal messages match the documentation**, e.g. a fixed placement with unknown keys says it
  `cannot carry` them.

## [1.4.0]

Replanning a job that has already started, and container ids that match the other engines.
A request the schema never allowed is now refused (see *Fixed*).

### Added

- **Fixed placements.** `fixed_placements` in an array request, or `Packer::pack($items,
  $containers, $fixedPlacements)` with `Packvium\Domain\FixedPlacement`. Each entry pins an
  item type to a container type and instance, at the origin a result reports, in one
  orientation. Fixed items keep their place, count toward weight, support and top load, and
  come back marked `fixed`. A fixed set that is not a valid packing on its own throws
  `Packvium\Validation\FixedPlacementException` before any search.
- **`Packvium\Revisions\PlanRevision`** — `root()`, `derive()`, `applyEvents()`,
  `verifyChain()`, `digest()`, `canonicalJson()` and `parse()`. A `packvium-plan-revision/v1`
  chain records what happened on the dock — `item_missing`, `container_substituted`,
  `placement_locked`, `placement_verified` — against the artifact it changed, linked by
  SHA-256, and carries the request the next plan solves. Every Packvium engine computes the
  same bytes from the same inputs. Errors are `PlanRevisionException` with a stable
  `errorCode()`.
- Both are available on PHP 7.3+ through `src-legacy/` as well.
- **`Packvium\Serialization\InvalidRequestException`.** A malformed request names what is
  wrong: `errorCode()` is `invalid_request`, `reason()` one of a closed set (`missing_field`,
  `wrong_type`, `below_minimum`, `above_maximum`, `negative_measure`, `invalid_unit`,
  `duplicate_id`, `not_allowed`, `invalid_value`), `field()` the JSON Pointer of the bad value,
  and the message reads `invalid_request: /items/0/quantity: must be at least 1`. It extends
  `InvalidArgumentException`, so existing handlers still catch it;
  `FixedPlacementException` extends it.
- `examples/revisions.php`.

### Changed

- **Container ids number each type from 1.** The `quality` profile's container-plan search
  counted containers across types, so a crate opened after a box was `crate#2` where every
  other search, and every other engine, said `crate#1`. Only answers with more than one
  container type change, and only their ids.

### Fixed

- **Limits below their floor are refused.** A zero or negative container `max_items`,
  `maxContainers` or effort-budget limit was accepted; each now throws.
- **A fixed placement is refused, never coerced, when it is not the schema's shape.** A
  position given as a list or null was read as the origin, and an instance of `"1"` or `1.9` as
  1; each is now refused naming the entry.

## [1.3.0]

Portable operational artifacts for PHP, and two execution-plan fixes. Nothing breaks 1.2.0.

### Added

- **`Packvium\Artifacts\OperationalArtifact`** — `build($request, $result, $loadingOrders)`,
  `fromJson()`, `parse()` and `canonicalJson()`. One `packvium-operational-artifact/v1`
  document carries the execution plan, exact geometry, the values a work order shows, and the
  request that produced it. Every Packvium engine builds the same bytes from the same result.
- **`Packvium\Artifacts\ArtifactExports`** — `json()`, `csv()` (RFC 4180, 18 columns) and
  `workOrderHtml()`: a printable work order in one HTML file with no scripts and no external
  resources. Errors are `OperationalArtifactException` with a stable `errorCode()`.
- Both are available on PHP 7.3+ through `src-legacy/` as well.
- `examples/artifacts.php`.

### Fixed

- **`Plan::canonicalJson` escaped U+2028 and U+2029**, so a plan whose item type or reason held
  either character differed from the other engines. It now writes RFC 8785, as they do. Every
  plan without those characters keeps its 1.2.0 bytes.
- **`Plan::build` refused an empty container's empty loading order.** It is now accepted.

### Changed

- A pinned catalog version resolves without scanning the version history.

## [1.2.0]

Execution plans for PHP, and a faster grid-admission path. Nothing breaks 1.1.0.

### Added

- **`Packvium\Execution\Plan`** — `Plan::build($request, $result, $loadingOrders)` and
  `Plan::canonicalJson($plan)` turn a validated result into a work order: what to lift, why a
  carton was chosen, what was not packed. No solver or validator is called. Step order comes
  from `$loadingOrders` or is reported as `"unavailable"`. Available on PHP 7.3+ through
  `src-legacy/` as well.
- `examples/execution.php`.

### Changed

- **Grid admission and load ordering reuse a prototype profile and a canonical order** instead
  of rebuilding them per candidate. Results are byte-identical.
- Every example sets its own `time_limit_ms`, so its output no longer depends on machine load.

## Earlier releases

Up to 1.1.0 one changelog covered every Packvium language. Those entries are kept in
this repository's GitHub Releases for each tag.
