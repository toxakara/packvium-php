<?php
declare(strict_types=1);
namespace Packvium\Policy;
use InvalidArgumentException;
/**
 * A rule set this engine cannot honour exactly as written.
 *
 * Structured rather than skipped: a rule quietly dropped for being malformed would let a
 * request pack in a way its own policy forbids, which is the failure the whole contract
 * exists to prevent.
 */
final class PolicyException extends InvalidArgumentException {}
