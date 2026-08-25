<?php
declare(strict_types=1);
namespace Packvium\Policy;
/**
 * What a rule forbids. Exactly three, each compiling to a constraint the pipeline
 * already runs, because the tag fields already express the predicates -- a rule adds
 * identity, dating and priority, not a second vocabulary.
 */
interface RuleForm {}
