<?php
declare(strict_types=1);
namespace Packvium\Validation;

/**
 * The documents do not have the shape the contract requires: a contract break, not a packing
 * defect, so no issue list is returned.
 *
 * `kind` is `contract` when the reference raises `MalformedResult` with this message (where that
 * message quotes a Python exception, the text after the quote is this port's own account), and
 * `shape` when the reference fails on the input another way -- a missing key, a value of the
 * wrong type -- in which case the message is this port's.
 */
final class MalformedResult extends \InvalidArgumentException
{
    public const CONTRACT = 'contract';
    public const SHAPE = 'shape';

    /** @var string */
    public $kind;

    public function __construct(string $message, string $kind)
    {
        parent::__construct($message);
        $this->kind = $kind;
    }

    public static function contract(string $message): self
    {
        return new self($message, self::CONTRACT);
    }

    public static function shape(string $message): self
    {
        return new self($message, self::SHAPE);
    }
}
