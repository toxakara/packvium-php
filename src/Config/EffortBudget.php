<?php
declare(strict_types=1);
namespace Packvium\Config;

/*
 * `Packvium\Config\EffortBudget`: the public name of the counted-work limits a
 * `PackingConfig` takes. It is an alias of the solver's own class, not a copy, so
 * `new EffortBudget(...)` here and the budget `ArrayCodec` decodes from
 * `configuration.effort_budget` are the same type.
 *
 * The constructor (`maxCandidatesEvaluated`, `maxPlacementAttempts`, `maxSearchNodes`,
 * `maxRestarts`, all optional, each at least 1) and the four readonly properties are the
 * contract. `exceeded()` is what the search calls and is not meant for callers.
 *
 * Loading the class declares the alias; see the bottom of `Algorithm/EffortBudget.php`.
 */
\class_exists(\Packvium\Algorithm\EffortBudget::class);
