<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

use Packvium\Validation\MalformedResult;

/**
 * A packed container's id as an issue prints it. The reference reads `packed["id"]` only while
 * formatting an issue, so a missing id fails only once an issue needs it; `verify` reports
 * that after the check, which aborts the run exactly as the reference's `KeyError` does.
 */
final class Label
{
    /** @var string|null */
    private $text;
    /** @var bool */
    private $missingUsed = false;

    /** @param mixed $packed */
    public function __construct($packed)
    {
        $id = Json::get($packed, 'id', $this);
        $this->text = $id === $this ? null : PyFormat::str($id);
    }

    public function __toString(): string
    {
        if ($this->text === null) {
            $this->missingUsed = true;
            return '?';
        }
        return $this->text;
    }

    public function verify(): void
    {
        if ($this->missingUsed) {
            throw MalformedResult::shape('a packed container with an issue has no id');
        }
    }
}
