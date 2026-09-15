# Changelog

What changed in `packvium/packvium` on Packagist, release by release. The format follows
[Keep a Changelog](https://keepachangelog.com/1.1.0/) and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
