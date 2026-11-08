# Changelog

What changed in `packvium/packvium` on Packagist, release by release. The format follows
[Keep a Changelog](https://keepachangelog.com/1.1.0/) and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.10.0]

Overhanging pallets, gusseted bags, 24 rotations for a hull, a declared currency, an id on every
placement, a nested packer that compares carton sets, and bounds under three more objectives.
One stricter refusal: a `units` key other than `length` (see *Changed*).

### Added

- **`container.pallet_overhang_limit`** (`Packvium\Domain\OverhangLimit`): a load may pass the
  deck by the stated length at each end and width at each side. Positions are measured from the
  corner of the overhang zone, only the deck bears weight, and the packed pallet reports
  `achieved_dimensions`, at which it is billed and handed up. Refused beside `access_directions`,
  `axles`, `made_to_measure`, `obstacles`, a non-zero `void_fill_reserve_ratio` or a
  `stop_index`.
- **Gusseted bags:** `deformable_boundary.kind: "gusseted_bag"` with `side_gusset_depth`;
  `Container::create()` and the constructor take a trailing `?Length $sideGussetDepth`.
- **18 signed rotations for a `convex_hull` item** (`Rotation::NL_NW_PH` is `-L-W+H`), 24 in all,
  in `allowedRotations`, a placement's orientation, `fixed_placements` and revision events;
  `Rotation::every()`, `isSigned()`, `isUpright()`. `Rotation::all()` is unchanged, and a signed
  code on any other item is `invalid_request` / `invalid_value`.
- **`placement_id` on every placement**, the canonical JSON of `[container id, x, y, z,
  item_type, orientation]` in ticks. A fixed placement or a locked revision event may quote it,
  the execution plan copies it, and `validate` reports `placement_id_mismatch` and
  `duplicate_placement_id`.
- **`currency`** on the request and each `rate_table`, echoed as the result's `currency`; it
  never changes the packing. `PackingRequest`, `Packer::pack()` and `RateTable` take a trailing
  `?string $currency`, and `validate` reports `currency_mismatch`.
- **`optimality.lower_bound` and the gap under `maximum_value`, `open_dimension_height` and
  `maximum_volume`**, besides `default` and `lowest_cost`; `validate` checks them.
- **`new NestedPacker(int $levelBeamWidth = 1)`** carries the K best carton sets up a level and
  lets the outermost level's score choose; 1 is the greedy chain, answer for answer.
- **A made-to-measure box may keep a `void_fill_reserve_ratio`**: when the box cut tight to the
  load would break the reserve, a larger one is cut.
- **`PlanRevision::stockCutBoxes()`**, and the revision issue `artifact_required`.
- **`examples/packaging.php`**: an overhanging pallet, a gusseted mailer, a cut box with a
  reserve and a nested beam.

### Changed

- **`units` refuses every key but `length`**: `invalid_request` / `not_allowed` at
  `/units/<key>`. 1.9 read a bare weight as grams whatever `units.weight` said; write the unit
  on each weight instead.
- **`PlanRevision::derive()` stocks a box already cut to measure** that holds a locked or
  verified placement, as `<type>@<revision>#<instance>` at its cut size, price and tare, where
  1.9 derived a request every engine refused. `verifyChain()` checks that revision only when
  given its approved artifact.
- **`keep_upright` narrows an explicit `allowed_rotations` list** in `validate`, not only the
  default list.

### Fixed

- **An explicit rotation list emptied by `keep_upright` is refused at `allowed_rotations`**,
  with the same named request error the independent validator checks.
- **Stocking cut boxes indexes artifact lookups once** and reports malformed consumed
  machine fields as structured `invalid_revision` errors.
- **An artifact export refuses an integer past `2^53 - 1`** with `number_out_of_range`, where it
  printed one up to `PHP_INT_MAX` that other engines read differently.
- **A compressible item no longer joins a rigid item's grid lattice.**
- **A mixed fraction whose whole part is `PHP_INT_MIN`** is refused as a negative measure,
  where it failed later as a type error.

## [1.9.0]

No API change, but admission is stricter: some requests 1.8 accepted are now refused (see the
weight and length limits below). More requests now agree with the reference, kilometre-scale
requests included.

### Changed

- **Under a clearance a compressible item bears its load on its own footprint**, so its crush
  check is stricter and `validate` measures its own compressed volume.
- **A weight is at most `2^63 - 1` ticks, and so is every item's weight times its quantity plus
  the heaviest tare**: past it, `invalid_request` / `above_maximum`; a weight written as a JSON
  number past `2^53 - 1` is `invalid_value` and must be a string.
- **Agrees with the other engines where 1.8 did not:** nesting under a clearance shares the
  items' own overlap and a deep nesting column nests in pairs; a length past `2^53 - 1` ticks or
  a box past `10^30` cubic ticks is `invalid_request` / `above_maximum`; a side that reads as zero
  ticks is `invalid_value` / `must be positive`; an obstacle box outside its container is
  `invalid_value` / `lies outside inner_dimensions`, each at its own pointer.
- **`validate` checks the centroid (`centre_of_gravity_unsupported`) and the whole stack's load
  (`top_load_exceeded`)**, an item on several bearers shared by contact area. It no longer
  holds that item's whole weight against every bearer, so it accepts some results 1.8 rejected
  and names the overloaded bearer.
- **A plan beam wider than 1 compares every container type**, whatever the node limit.
- **A request whose areas pass a 64-bit integer is answered exactly**, where it
  threw a `TypeError`; `support_ratio` and `volume_utilization` are the correctly rounded
  quotient, which can differ in the sixth decimal.
- **`quality` runs the full staged portfolio:** its answer and its `alternatives` now agree
  with the reference, at about 0.9x the time on the measured quality fixtures.
- **`fast` is back to 1.7's peak memory on most requests** (the space-subtraction helper and the
  fixed-placement and landed-cost checks load only when used).

### Fixed

- **A narrow nesting item under a clearance threw `BigInt::subtract would be negative`**, and a
  deep nesting column on the lattice returned `invalid_result`.
- **Greedy `quality` starts dropped `PackingConfig::accessDirections`.**

## [1.8.0]

Results now say how far they can be from the best possible answer, `optimal` means a proof,
and a mistyped flag is refused instead of guessed.

### Added

- **`optimality.lower_bound` and the gap** under the `default` and `lowest_cost` objectives;
  `Packvium\Validation\validate` checks them.
- **The `src-legacy/` generator** (`scripts/build_php74.py`, `tools/php-compat/`), which
  rebuilds the shipped tree byte for byte from a tag.

### Changed

- **Attaining the bound, not search exhaustion, sets `optimal`** (`proven_optimal`).
- **An effort budget names a stop:** a run that ends on exactly its budget is `complete`, and
  `time_limit_ms` is no longer split across starts. Check `termination.any_start_truncated`.
- **`ArrayCodec::pack` refuses a non-boolean `keep_upright`, `stackable`, `must_be_on_floor`
  or `require_placement_coordinates`** as `wrong_type`; `"no"` packed as stackable. The
  constructors are unchanged: without `strict_types`, PHP still converts the value.
  `Packvium\Validation\validate` throws `MalformedResult` (`contract`) for such a request, and
  reads `null` as the default.
- **Solves 2-15% faster**, the loading-order check about twice as fast, identical answers.
- **The JSON path avoids a redundant object check:** `ArrayCodec::pack` retains request
  admission and model checks, and compiles fewer files. Direct measurements on the macOS
  host removed about 0.5 ms of compilation/checking work on the unpackable request; the
  additional end-to-end gain was within the A/A noise. This is wall time, not CPU time.
- **Placement under axle limits is about 2.5-3x faster**, unloading orders about 5% faster;
  answers are identical.

### Fixed

- **`homogeneous_blocks` named alone packs under `fast` and `balanced`**, and a node-limit
  stop is no longer reported as `time_limit`.
- **A bound past the declared ceiling no longer throws `BoundOverflowException`.**
- **`validate` reads `units: null` and `units.length: null` as millimetres**, as the engines do,
  and refuses a `units` that is not an object, or an unknown `units.length`, as a `contract`
  `MalformedResult` naming the field. `units.weight` is not a field and is ignored: put the unit
  on each weight.

## [1.7.0]

The reference validator ships in the package, `quality` looks ahead in its block solver, and a
request built from objects is refused as its JSON would be. No request or result field changes.

### Added

- **`Packvium\Validation\validate(array $request, array $result)`**, with `ResultIssue` (`code`,
  `detail`) and `MalformedResult` (an `InvalidArgumentException` carrying `kind`). The reference
  validator over the decoded JSON documents, returning an empty list for a valid result; it is
  loaded like the `Packvium\Commerce` functions and shares no code with the engine it checks. A
  document integer past 64 bits is refused rather than judged. Available on PHP 7.3+ through
  `src-legacy/` as well.
- **`StopAccessibilityConstraint::directionsFor()`**, which `ConstraintActivity` now asks instead
  of reading the configured doors.

### Changed

- **A request built from objects is refused as JSON is.** A model constructor that
  refuses a value throws `InvalidRequestException` (code, reason, field `""`), a subclass of the
  `InvalidArgumentException` it threw before. `Packer::pack()` checks the objects with the JSON
  rules before solving. `FixedPlacement` with a `containerInstance` below 1 is now refused by
  `pack()` as `invalid_fixed_placement`, not by its constructor.
- **Every schema enum value is refused as `not_allowed`.** A value outside its list in
  `allowed_rotations`, the output and dimensional-weight units, `shape_type`,
  `ground_contact_rule` or `configuration.solvers` is `invalid_request` with reason `not_allowed`
  at its JSON Pointer. **`ticks` as an output unit is now refused**; the schema never listed it.
- **`quality` looks ahead in the block solver.** Each round completes its leading
  blocks greedily and commits the best, so the answer is never worse than the greedy pass. On
  our 22 pinned benchmark requests every answer improved, at 3% more CPU. Under a tight effort
  budget the block start can now report `truncated` / `effort_limit`. `fast` and `balanced` are
  unchanged.
- **A `nesting_height` not below the item's height is refused as `invalid_value`** at its
  pointer, and with no `output` block the result is written in `units.length`'s schema code.

### Fixed

- **The load rules walk a stack iteratively**, and the dependency-cycle check no longer recurses.
- **The covered ground-contact rule counts only supporters with positive area**, so an item no
  longer rests on a box it touches only at an edge.
- **A quantity past 2^53 − 1 on the object path is refused** instead of exhausting memory.
- **A route violation names its violators in sorted order.**

## [1.6.0]

Boxes cut to measure, flat bags, stacking groups and a volume-first objective, all additive. A
request that uses none of them keeps its meaning; `fast` and `balanced` may now answer better.

### Added

- **`Packvium\Config\EffortBudget`**, a public name for the effort budget, and
  **`OperationalArtifact::replayLevel($result)`**.
- **`Packvium\Domain\MadeToMeasure` and `Container`'s `$madeToMeasure`.** A container becomes an
  on-demand box machine (largest box, minimum, cut step, board price and weight per m²); each
  packed box is the load's extent rounded up onto the machine grid and priced as cut.
  `PackedContainer::achievedDimensions()` reports the extent.
- **Flat bags: `Container`'s `$deformableBoundary = 'flat_bag'`** and `Packvium\Domain\FlatBag`.
  A poly mailer admits a load when length plus thickness and width plus thickness fit;
  dimensional weight is billed from the load's size.
- **Stacking groups: `Item`'s `$stackingGroup` and `$acceptsStackingGroups`.** Which item
  classes
  may rest directly on which.
- **The `maximum_volume` objective** and `Packvium\Objective\MaximumVolumeSolutionScorer`.
- **A warning for items resting on nothing** when `minimumSupportRatio` is 0
  (`unsupported_placements: N ...` in the result's warnings). No answer changes.

### Changed

- **An unpacked item names the limit that left it out, the same in every engine.**
  Where the search used to fall back to `no_feasible_placement` or `search_exhausted`, an item a
  fresh container would take but none is left reports `container_inventory_exhausted`, and one
  that overloads an axle wherever it stands alone reports `axle_overloaded`; both are
  `inferred`. Code that matched the old reason for these cases must match the new one.
- **`quality` orders its beam by a `convex_hull` item's own solid**, not its box, so
  it no longer assumes a second wedge cannot fit when it can.
- **The container plan counts a `convex_hull` item by its own solid too**, so
  `quality` no longer ranks a plan as if wedges that share a container each needed their own.
- **`fast` and `balanced` add the block solver as one more start** on multi-type loads and keep
  its answer only when strictly better.
- **The exact solver ranks volume first under `maximum_volume`.**

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
