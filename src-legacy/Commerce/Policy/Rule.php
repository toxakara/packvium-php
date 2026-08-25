<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

/**
 * One immutable, numbered version of one rule id's history. All of a rule's predicates
 * must match (logical AND) for the rule to apply.
 */
final class Rule
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
     * @var \Packvium\Commerce\Policy\PolicyScope
     */
    public $scope;
    /**
     * @readonly
     * @var \Packvium\Commerce\Policy\PolicyAction
     */
    public $action;
    /**
     * @var list<Predicate>
     * @readonly
     */
    public $predicates;
    /**
     * @readonly
     * @var int
     */
    public $priority;
    /**
     * @readonly
     * @var int
     */
    public $effectiveAt;
    /**
     * @readonly
     * @var string
     */
    public $reason = '';
    /** @param list<Predicate> $predicates
     * @param mixed $scope
     * @param mixed $action */
    public function __construct(
        string $ruleId,
        int $version,
        $scope,
        $action,
        array $predicates,
        int $priority,
        int $effectiveAt,
        string $reason = ''
    ) {
        $this->ruleId = $ruleId;
        $this->version = $version;
        $this->scope = $scope;
        $this->action = $action;
        $this->predicates = $predicates;
        $this->priority = $priority;
        $this->effectiveAt = $effectiveAt;
        $this->reason = $reason;
        if ($ruleId === '') { throw new \InvalidArgumentException('rule_id is required'); }
        if ($version <= 0) { throw new \InvalidArgumentException('version must be positive'); }
        if ($predicates === []) { throw new \InvalidArgumentException('a rule must have at least one predicate'); }
        if ($effectiveAt < 0) { throw new \InvalidArgumentException('effective_at cannot be negative'); }
        foreach ($predicates as $predicate) {
            if ($predicate->scope !== $scope) {
                throw new \InvalidArgumentException("every predicate of rule '{$ruleId}' must share the rule's own scope");
            }
        }
    }

    /** @param array<string,mixed> $context */
    public function matches(array $context): bool
    {
        foreach ($this->predicates as $predicate) {
            if (!$predicate->matches($context)) { return false; }
        }
        return true;
    }
}
