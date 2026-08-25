<?php
declare(strict_types=1);
namespace Packvium\Objective;
use InvalidArgumentException;
/** `PackingConfig::$objective` named an objective this library does not implement. */
final class UnknownObjectiveException extends InvalidArgumentException{}
