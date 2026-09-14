<?php
declare(strict_types=1);
namespace Packvium\Execution;

use InvalidArgumentException;

/**
 * The adapter was handed something it cannot describe.
 *
 * An exception rather than a status, because unlike an infeasible packing request this is
 * never a well-formed question the model cannot answer -- it is a malformed result, and a
 * plan derived from one would be a document about nothing.
 */
final class ExecutionPlanException extends InvalidArgumentException
{
}
