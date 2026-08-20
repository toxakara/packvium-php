<?php
declare(strict_types=1);
namespace Packvium\Commerce\Rating;

/**
 * The requested zone or accessorial is not defined by the resolved tariff -- a
 * structured rejection naming exactly what was missing, never a silently-zero charge.
 *
 * Exactly one of $zone / $accessorialIds is set, so the API boundary reads the machine-
 * readable reason off the exception instead of parsing it back out of the message.
 */
final class UnavailableServiceException extends RatingException
{
    /** @param list<string> $accessorialIds */
    public function __construct(string $message, public readonly ?string $zone = null, public readonly array $accessorialIds = [])
    {
        parent::__construct($message);
    }
}
