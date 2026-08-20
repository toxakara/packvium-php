<?php
declare(strict_types=1);
namespace Packvium\Commerce;

/**
 * Internal: a structured rejection travelling to the API boundary, where it becomes the
 * {"status":"rejected", ...} result document. Never escapes this namespace.
 */
final class Rejection extends \RuntimeException
{
    /** @param array<string,mixed> $fields */
    public function __construct(public readonly string $rejectionCode, public readonly array $fields)
    {
        parent::__construct($rejectionCode);
    }
}
