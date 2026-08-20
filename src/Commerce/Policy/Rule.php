<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

/**
 * One immutable, numbered version of one rule id's history. All of a rule's predicates
 * must match (logical AND) for the rule to apply.
 */
final readonly class Rule
{
    /** @param list<Predicate> $predicates */
    public function __construct(
        public string $ruleId,
        public int $version,
        public PolicyScope $scope,
        public PolicyAction $action,
        public array $predicates,
        public int $priority,
        public int $effectiveAt,
        public string $reason = '',
    ) {
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
