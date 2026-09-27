<?php
declare(strict_types=1);
namespace Packvium\Revisions;

use InvalidArgumentException;
use Throwable;

/**
 * A revision could not be built. `errorCode()` is one of a closed set shared by four
 * engines: `invalid_revision`, `invalid_event`, `event_conflict`, `invalid_artifact`,
 * `invalid_json`, and the canonical-form codes `number_out_of_range`, `invalid_string`,
 * `invalid_value`.
 */
final class PlanRevisionException extends InvalidArgumentException
{
    /** @var string */
    private $errorCode;

    public function __construct(string $errorCode, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
