<?php
declare(strict_types=1);
namespace Packvium\Serialization;

use InvalidArgumentException;
use Throwable;

/**
 * The request is not one any engine may answer. Nothing was solved.
 *
 * Held to `packvium.request_errors.InvalidRequestError`. A caller branches on `errorCode()`
 * (`invalid_request`; subclasses such as `FixedPlacementException` name their own), on
 * `reason()` -- one of a closed set shared by four engines: `missing_field`, `wrong_type`,
 * `below_minimum`, `above_maximum`, `negative_measure`, `invalid_unit`, `duplicate_id`,
 * `not_allowed`, `invalid_value` -- and on `field()`, the RFC 6901 JSON Pointer of the
 * offending value (`""` for the request as a whole), so no caller has to parse prose.
 */
class InvalidRequestException extends InvalidArgumentException
{
    /** @var string */
    private $reason;
    /** @var string */
    private $field;
    /** @var string */
    private $detail;

    public function __construct(string $reason, string $field, string $detail, ?Throwable $previous = null)
    {
        $this->reason = $reason;
        $this->field = $field;
        $this->detail = $detail;
        parent::__construct($this->composeMessage(), 0, $previous);
    }

    public function errorCode(): string
    {
        return 'invalid_request';
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function field(): string
    {
        return $this->field;
    }

    public function detail(): string
    {
        return $this->detail;
    }

    /** The message every engine prints for this error, so the prose agrees too. */
    protected function composeMessage(): string
    {
        return $this->field === ''
            ? "{$this->errorCode()}: {$this->detail}"
            : "{$this->errorCode()}: {$this->field}: {$this->detail}";
    }
}
