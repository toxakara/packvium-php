<?php
declare(strict_types=1);

namespace Packvium\Explain;

/**
 * A reason code with no registered message -- thrown here, at the boundary, rather
 * than letting a missing array key pass silently or surface as an unrelated error
 * deep inside a renderer.
 */
final class UnknownReasonException extends \OutOfBoundsException
{
    /**
     * @readonly
     * @var string
     */
    public $reason;
    public function __construct(string $reason)
    {
        $this->reason = $reason;
        parent::__construct("no explanation registered for reason code '{$reason}'");
    }
}
