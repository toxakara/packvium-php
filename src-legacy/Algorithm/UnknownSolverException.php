<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use InvalidArgumentException;
/** `PackingConfig::$solvers` named a solver this library does not implement. */
final class UnknownSolverException extends InvalidArgumentException{}
