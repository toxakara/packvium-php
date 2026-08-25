<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

/** The evidence a decision cites: which rule id/version produced it, and why. */
final class Citation
{
    /**
     * @readonly
     * @var string
     */
    public $ruleId;
    /**
     * @readonly
     * @var int
     */
    public $version;
    /**
     * @readonly
     * @var \Packvium\Commerce\Policy\PolicyAction
     */
    public $action;
    /**
     * @readonly
     * @var int
     */
    public $priority;
    /**
     * @readonly
     * @var string
     */
    public $reason;
    /**
     * @param mixed $action
     */
    public function __construct(string $ruleId, int $version, $action, int $priority, string $reason)
    {
        $this->ruleId = $ruleId;
        $this->version = $version;
        $this->action = $action;
        $this->priority = $priority;
        $this->reason = $reason;
    }

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
