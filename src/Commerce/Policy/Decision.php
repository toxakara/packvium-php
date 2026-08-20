<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

/**
 * The outcome of one evaluation: whether the context is admitted for the given scope,
 * and the single rule (if any) whose citation explains why.
 */
final readonly class Decision
{
    public function __construct(public PolicyScope $scope, public bool $allowed, public ?Citation $citation = null)
    {
        if (!$allowed && $citation === null) { throw new \InvalidArgumentException('a REJECT decision must carry a citation'); }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope->value,
            'allowed' => $this->allowed,
            'citation' => $this->citation?->toArray(),
        ];
    }
}
