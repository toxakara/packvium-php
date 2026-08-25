<?php
declare(strict_types=1);
namespace Packvium\Policy;
use Packvium\Constraint\ConstraintContext;
use Packvium\Constraint\ConstraintResult;
use Packvium\Constraint\PlacementConstraint;
use Packvium\Constraint\ProvenRejection;
use Packvium\Domain\ItemInstance;
/**
 * Rejects a candidate placement that any participating rule forbids.
 *
 * `O(m + r)` per candidate for `m` placements already in the container and `r` resolved
 * rules: one pass collecting the tags present, then one pass over the rules. That is the
 * same bound class as the built-in compatibility and tag-count constraints the forms
 * compile onto, so the published complexity bounds are unchanged.
 *
 * Rules arrive in citation order, so the first rejection is already the one the contract
 * says to cite -- highest priority, ties to the smallest id.
 */
final class PolicyConstraint implements PlacementConstraint, ProvenRejection
{
    /**
     * @var list<PolicyRule>
     * @readonly
     */
    public $rules;
    /** @param list<PolicyRule> $rules */
    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    public function evaluate(ConstraintContext $c): ConstraintResult
    {
        $itemTags = $c->item->item->tags;
        $containerTags = $c->container->tags;
        $present = null;
        foreach ($this->rules as $rule) {
            $form = $rule->form;
            if ($form instanceof RequireContainerTag) {
                if (in_array($form->itemTag, $itemTags, true)
                    && !in_array($form->containerTag, $containerTags, true)) {
                    return self::reject($rule, "requires a container tagged '{$form->containerTag}'");
                }
                continue;
            }
            // Both remaining forms need to know what is already in the container, so the
            // walk happens once and only when a rule of that kind exists at all.
            $present = $present ?? self::tagCounts($c->placements);
            if ($form instanceof SeparateTags) {
                if (in_array($form->tag, $itemTags, true) && ($present[$form->fromTag] ?? 0) > 0) {
                    return self::reject($rule, "'{$form->tag}' may not share a container with '{$form->fromTag}'");
                }
                if (in_array($form->fromTag, $itemTags, true) && ($present[$form->tag] ?? 0) > 0) {
                    return self::reject($rule, "'{$form->fromTag}' may not share a container with '{$form->tag}'");
                }
            } elseif ($form instanceof LimitTagPerContainer
                && in_array($form->tag, $itemTags, true)
                && ($present[$form->tag] ?? 0) >= $form->maxCount) {
                return self::reject($rule, "at most {$form->maxCount} item(s) tagged '{$form->tag}' per container");
            }
        }
        return ConstraintResult::allow();
    }

    /**
     * The rule that rules this item out of every offered container, if one does.
     *
     * Only `require_container_tag` can be answered here, and that is not a gap. It is a
     * statement about the request alone -- this item carries the tag, no offered
     * container carries the one it requires -- so it holds however the search goes.
     * Segregation and per-container caps depend on what else was packed, so an item they
     * leave behind was left behind by the search, and reporting that as proven would
     * claim more than the engine knows.
     *
     * `O(r * c)` for `r` rules and `c` containers, once per unpacked item rather than
     * per candidate.
     */
    public function provesUnplaceable(ItemInstance $item, array $containers): ?array
    {
        foreach ($this->rules as $rule) {
            $form = $rule->form;
            if (!$form instanceof RequireContainerTag) continue;
            if (!in_array($form->itemTag, $item->item->tags, true)) continue;
            foreach ($containers as $container) {
                if (in_array($form->containerTag, $container->tags, true)) continue 2;
            }
            return [
                'policy_rule',
                $rule->citation() . ": requires a container tagged '{$form->containerTag}', "
                . 'which none of the containers offered carries',
            ];
        }
        return null;
    }

    private static function reject(PolicyRule $rule, string $why): ConstraintResult
    {
        return ConstraintResult::reject('policy_rule', $rule->citation() . ": {$why}");
    }

    /**
     * @param list<\Packvium\Domain\Placement> $placements
     * @return array<string,int>
     */
    private static function tagCounts(array $placements): array
    {
        $counts = [];
        foreach ($placements as $placement) {
            foreach ($placement->instance->item->tags as $tag) $counts[$tag] = ($counts[$tag] ?? 0) + 1;
        }
        return $counts;
    }
}
