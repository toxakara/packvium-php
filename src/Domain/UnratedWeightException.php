<?php
declare(strict_types=1);
namespace Packvium\Domain;

use InvalidArgumentException;

/**
 * A billed weight the rate table does not price. Structured rather than a silent
 * top-bracket clamp, so a caller learns the tariff is short rather than being quoted a
 * number the carrier never published.
 */
final class UnratedWeightException extends InvalidArgumentException {}
