<?php
declare(strict_types=1);
namespace Packvium\Policy;
/**
 * The rules that participate in one request, already resolved and ordered.
 *
 * Resolution is fixed by the contract in docs/POLICY-RULES.md and must be identical in
 * every engine, or the same request packs differently depending on which one answered
 * it.
 */
final readonly class PolicyRuleSet
{
    /** @param list<PolicyRule> $rules */
    public function __construct(public array $rules = []) {}

    public static function fromArray(mixed $raw): self
    {
        if ($raw === null) return new self();
        if (!is_array($raw)) throw new PolicyException('policy must be an object');
        $unknown = array_diff(array_keys($raw), ['as_of', 'shipment', 'rules']);
        sort($unknown);
        if ($unknown !== []) throw new PolicyException('policy has unknown keys: ' . implode(', ', $unknown));
        $declared = $raw['rules'] ?? [];
        if (!is_array($declared)) throw new PolicyException('policy.rules must be an array');
        if ($declared === []) return new self();
        if (!array_key_exists('as_of', $raw)) {
            // No default: a guessed instant silently activates or hides a restriction,
            // and reading a clock here would make one request pack differently on
            // different days.
            throw new PolicyException('policy.as_of is required whenever policy.rules is non-empty');
        }
        $asOf = PolicyRule::integer($raw['as_of'], 'policy.as_of', 0);
        $shipment = ShipmentContext::fromArray($raw['shipment'] ?? null, 'policy.shipment');
        $parsed = [];
        foreach (array_values($declared) as $index => $rule) $parsed[] = PolicyRule::fromArray($rule, $index);
        return new self(self::resolve($parsed, $asOf, $shipment));
    }

    /**
     * @param list<PolicyRule> $rules
     * @return list<PolicyRule>
     */
    private static function resolve(array $rules, int $asOf, ShipmentContext $shipment): array
    {
        // Append-only per id: among participating versions of one id the highest
        // `effectiveAt` wins, ties broken by the highest `version`. The same resolution
        // the catalog registry already uses for `as_of` lookups, deliberately, so a
        // reader learns one rule and not two.
        $latest = [];
        foreach ($rules as $rule) {
            if ($rule->effectiveAt > $asOf || !$rule->appliesTo->satisfiedBy($shipment)) continue;
            $current = $latest[$rule->id] ?? null;
            if ($current === null
                || [$rule->effectiveAt, $rule->version] > [$current->effectiveAt, $current->version]) {
                $latest[$rule->id] = $rule;
            }
        }
        $resolved = array_values($latest);
        // Citation order, not evaluation order: the first rule that rejects a candidate
        // is the one cited, so sorting here is what makes the citation deterministic.
        // Ties go to the lexicographically smallest id -- never to array insertion
        // order, which would follow the order the caller happened to write.
        usort($resolved, static fn(PolicyRule $a, PolicyRule $b): int
            => ($b->priority <=> $a->priority) ?: ($a->id <=> $b->id));
        return $resolved;
    }

    /** @return list<PolicyConstraint> */
    public function constraints(): array
    {
        return $this->rules === [] ? [] : [new PolicyConstraint($this->rules)];
    }
}
