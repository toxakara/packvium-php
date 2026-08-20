<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

/**
 * Append-only history of policy rules, one history per rule id, plus the deterministic
 * conflict resolution over them.
 *
 * Complexity: evaluate() is O(n * (h + p)) over n rule ids, h versions each and p
 * predicates; decide() over an already-resolved set is O(r * p) with no lookup at all.
 */
final class PolicyRegistry
{
    /** @var array<string,list<Rule>> */
    private array $versions = [];

    /** @param list<Predicate> $predicates */
    public function publish(
        string $ruleId,
        PolicyScope $scope,
        PolicyAction $action,
        array $predicates,
        int $priority,
        int $effectiveAt,
        string $reason = '',
    ): Rule {
        $history = $this->versions[$ruleId] ?? [];
        $rule = new Rule($ruleId, count($history) + 1, $scope, $action, $predicates, $priority, $effectiveAt, $reason);
        $history[] = $rule;
        $this->versions[$ruleId] = $history;
        return $rule;
    }

    /** @return list<Rule> */
    public function versions(string $ruleId): array
    {
        if (!isset($this->versions[$ruleId])) {
            throw new RuleNotFoundException("no rule registered under id '{$ruleId}'", $ruleId);
        }
        return $this->versions[$ruleId];
    }

    public function version(string $ruleId, int $number): Rule
    {
        foreach ($this->versions($ruleId) as $rule) {
            if ($rule->version === $number) { return $rule; }
        }
        throw new VersionNotFoundException("rule '{$ruleId}' has no version {$number}", $ruleId, $number);
    }

    /**
     * Resolve and deterministically order an explicit policy snapshot.
     *
     * @param list<array{0:string,1:int}> $pins
     * @return list<Rule>
     */
    public function resolveVersions(array $pins): array
    {
        $ids = array_map(static fn(array $pin): string => $pin[0], $pins);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new \InvalidArgumentException('a policy snapshot cannot pin the same rule id twice');
        }
        usort($pins, static function (array $left, array $right): int {
            return $left[0] === $right[0] ? $left[1] <=> $right[1] : strcmp($left[0], $right[0]);
        });
        return array_map(fn(array $pin): Rule => $this->version($pin[0], $pin[1]), $pins);
    }

    /**
     * One deterministic decision for scope/context as of $asOf. Only the version
     * effective as of that instant participates for each rule id.
     *
     * @param array<string,mixed> $context
     */
    public function evaluate(PolicyScope $scope, array $context, int $asOf): Decision
    {
        $matching = [];
        foreach ($this->versions as $history) {
            $effective = self::effective($history, $asOf);
            if ($effective === null || $effective->scope !== $scope) { continue; }
            if ($effective->matches($context)) { $matching[] = $effective; }
        }
        return self::decide($matching, $scope, $context);
    }

    /**
     * Evaluate an already-resolved immutable rule set: an explicit REJECT always
     * outranks an ALLOW for the same context, the highest priority wins among equals,
     * and ties break on the lexicographically smallest rule id -- never on iteration
     * order. Nothing matching at all is allowed with no citation (open by default).
     *
     * @param list<Rule>          $rules
     * @param array<string,mixed> $context
     */
    public static function decide(array $rules, PolicyScope $scope, array $context): Decision
    {
        $rejects = [];
        $allows = [];
        foreach ($rules as $rule) {
            if ($rule->scope !== $scope || !$rule->matches($context)) { continue; }
            if ($rule->action === PolicyAction::REJECT) { $rejects[] = $rule; } else { $allows[] = $rule; }
        }
        $pool = $rejects !== [] ? $rejects : $allows;
        if ($pool === []) { return new Decision($scope, true, null); }

        $winner = $pool[0];
        foreach ($pool as $rule) {
            if ($rule->priority > $winner->priority
                || ($rule->priority === $winner->priority && strcmp($rule->ruleId, $winner->ruleId) < 0)) {
                $winner = $rule;
            }
        }
        $citation = new Citation($winner->ruleId, $winner->version, $winner->action, $winner->priority, $winner->reason);
        return new Decision($scope, $winner->action === PolicyAction::ALLOW, $citation);
    }

    /** @param list<Rule> $history */
    private static function effective(array $history, int $asOf): ?Rule
    {
        $winner = null;
        foreach ($history as $rule) {
            if ($rule->effectiveAt > $asOf) { continue; }
            if ($winner === null
                || $rule->effectiveAt > $winner->effectiveAt
                || ($rule->effectiveAt === $winner->effectiveAt && $rule->version > $winner->version)) {
                $winner = $rule;
            }
        }
        return $winner;
    }
}
