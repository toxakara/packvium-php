<?php
declare(strict_types=1);
namespace Packvium\Commerce;

/**
 * Internal: a structured rejection travelling to the API boundary, where it becomes the
 * {"status":"rejected", ...} result document. Never escapes this namespace.
 */
final class Rejection extends \RuntimeException
{
    /**
     * @readonly
     * @var string
     */
    public $rejectionCode;
    /**
     * @var array<string, mixed>
     * @readonly
     */
    public $fields;
    /** @param array<string,mixed> $fields */
    public function __construct(string $rejectionCode, array $fields)
    {
        $this->rejectionCode = $rejectionCode;
        $this->fields = $fields;
        parent::__construct($rejectionCode);
    }
}
