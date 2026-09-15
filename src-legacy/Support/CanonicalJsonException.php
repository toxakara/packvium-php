<?php
declare(strict_types=1);
namespace Packvium\Support;

use InvalidArgumentException;

/**
 * A value has no canonical spelling. `errorCode()` names which rule it broke:
 * `number_out_of_range`, `invalid_string` or `invalid_value`.
 */
final class CanonicalJsonException extends InvalidArgumentException
{
    /** @var string */
    private $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
