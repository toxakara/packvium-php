<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

/** The evidence a decision cites: which rule id/version produced it, and why. */
final readonly class Citation
{
    public function __construct(
        public string $ruleId,
        public int $version,
        public PolicyAction $action,
        public int $priority,
        public string $reason,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'version' => $this->version,
            'action' => $this->action->value,
            'priority' => $this->priority,
            'reason' => $this->reason,
        ];
    }
}
