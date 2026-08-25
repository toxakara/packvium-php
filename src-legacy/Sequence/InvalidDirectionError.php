<?php
declare(strict_types=1);
namespace Packvium\Sequence;

/**
 * A direction outside the six-value vocabulary was supplied. Rejected rather than
 * silently treated as one of the six -- `-z` in particular, since that was this
 * module's own previous (wrong) default for anything unrecognised.
 */
final class InvalidDirectionError extends \InvalidArgumentException
{
    /**
     * @readonly
     * @var string
     */
    public $direction;
    /** See `SequenceError::ERROR_CODE` for why this isn't named `$code`. */
    public const ERROR_CODE = 'invalid_direction';

    public function __construct(string $direction)
    {
        $this->direction = $direction;
        parent::__construct("unknown movement direction '{$direction}'; expected one of +x, -x, +y, -y, +z, -z");
    }

    /**
     * Canonical JSON shape shared with the Python, Rust and JavaScript
     * implementations.
     *
     * @return array{code:string,direction:string}
     */
    public function toArray(): array
    {
        return ['code' => self::ERROR_CODE, 'direction' => $this->direction];
    }
}
