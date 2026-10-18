<?php
declare(strict_types=1);
namespace Packvium\Validation;

/**
 * One way a result fails to be a sound answer to its request, as `Packvium\Validation\validate`
 * reports it: the same code and detail every Packvium package reports (docs/VALIDATION-CONTRACT.md).
 */
final class ResultIssue
{
    /** @var string */
    public $code;
    /** @var string */
    public $detail;

    public function __construct(string $code, string $detail)
    {
        $this->code = $code;
        $this->detail = $detail;
    }

    public function __toString(): string
    {
        return "{$this->code}: {$this->detail}";
    }
}
