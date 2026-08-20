<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

/**
 * A predicate names a scope or operator this engine version does not recognize. Raised
 * at admission time -- an unsupported predicate is refused outright, never silently
 * admitted and then ignored during evaluation.
 */
final class UnsupportedPredicateException extends PolicyException {}
