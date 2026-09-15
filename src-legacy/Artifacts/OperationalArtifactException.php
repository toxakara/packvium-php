<?php
declare(strict_types=1);
namespace Packvium\Artifacts;

use InvalidArgumentException;
use Throwable;

/**
 * The builder or an exporter was handed something an artifact cannot carry.
 *
 * `errorCode()` is one of a closed set shared by all four engines: `invalid_request`,
 * `invalid_result`, `invalid_plan_input`, `mixed_units`, `number_out_of_range`,
 * `invalid_string`, `invalid_value`, `unknown_format`, `invalid_json`.
 */
final class OperationalArtifactException extends InvalidArgumentException
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
