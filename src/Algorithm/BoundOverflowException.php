<?php
declare(strict_types=1);
namespace Packvium\Algorithm;

use RuntimeException;

/**
 * A sum in the bound path exceeded the declared ceiling.
 *
 * Separate from `InvalidArgumentException` on purpose: the request is not invalid, and the
 * items in it are not wrong. The bound is declining to answer, because answering would mean
 * carrying a number this engine cannot hold exactly -- and a bound that is quietly wrong is
 * worse than none, since it will be believed.
 *
 * Every engine refuses at the same declared ceiling rather than at its own native limit, so a
 * request is either answerable in all four or refused in all four.
 */
final class BoundOverflowException extends RuntimeException
{
}
