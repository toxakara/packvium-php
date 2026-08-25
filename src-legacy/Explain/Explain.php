<?php
declare(strict_types=1);

namespace Packvium\Explain;

use Packvium\Domain\UnpackedItem;

/**
 * Deterministic, localization-ready human-readable explanations for why an
 * item was not packed.
 *
 * Rendering is driven entirely by `UnpackedItem::$reason` and `->proof` -- the
 * structured evidence already attached to every unpacked item -- never by
 * matching or parsing free text a caller would otherwise have to scrape out of a log.
 * `REASON_MESSAGES` is the one place English prose lives; a caller wanting another
 * locale swaps that map (or calls `reason()` against their own), not the code that
 * walks the evidence. The Python module runs the same shape of logic in explain.py.
 */
final class Explain
{
    /**
     * One deterministic English sentence per reason code -- the reason-code vocabulary
     * (`SolverOrchestrator::unpackedReason`/`supportIsTheBlocker`). Every reason this
     * library can actually produce must have an entry here: `reason()` throws rather
     * than silently falling back to the bare code, so a new reason code introduced
     * without a matching message is caught immediately, not shipped mute.
     *
     * @var array<string,string>
     */
    public const REASON_MESSAGES = [
        'no_compatible_container_dimensions' =>
            'does not fit inside any offered container in any rotation',
        'rotation_restricted' =>
            'would fit in some container with more rotations allowed, but not with the '
            . 'rotations this item permits',
        'payload_exceeded' =>
            'exceeds the maximum payload of every offered container',
        'policy_rule' =>
            'is forbidden from every offered container by a policy rule the request '
            . 'declared -- the rule and version are in the details',
        'no_eligible_container' =>
            'shares no eligible container tag with any offered container',
        'time_limit' =>
            'was not reached before the configured time limit expired',
        'effort_limit' =>
            'was not reached before the configured effort budget was exhausted',
        'group_cannot_fit_together' =>
            'belongs to a group that could not all be placed together',
        'insufficient_support' =>
            'would fit geometrically, but only by resting on support the minimum '
            . 'support ratio forbids',
        'no_feasible_placement' =>
            'found no feasible placement in the containers offered, for a reason the '
            . 'search could not further isolate',
        'search_exhausted' =>
            'was not placed before the configured search strategies were exhausted',
        'exact_search_incomplete' =>
            'was not placed because the exact search ended before proving a final answer',
        'container_inventory_exhausted' =>
            'requires another compatible container, but the declared inventory is exhausted',
    ];

    /**
     * Prefixes an explanation with what kind of evidence backs it, from
     * `ReasonProof::$level`: a caller deciding whether to trust "this will
     * never fit" (proven) versus "try again with more time" (unknown_due_to_limit)
     * needs this distinction as much as the sentence itself.
     *
     * @var array<string,string>
     */
    public const LEVEL_PREFIXES = [
        'proven' => 'Proven',
        'unknown_due_to_limit' => 'Unknown (limit reached)',
        'observed' => 'Observed',
        'inferred' => 'Inferred',
    ];

    /**
     * The bare English sentence for one reason code, with no item identity or
     * evidence-level prefix -- the building block `unpackedItem()` composes.
     *
     * @throws UnknownReasonException if no message is registered for `$reason`
     */
    public static function reason(string $reason): string
    {
        if (!isset(self::REASON_MESSAGES[$reason])) {
            throw new UnknownReasonException($reason);
        }
        return self::REASON_MESSAGES[$reason];
    }

    /**
     * One deterministic sentence naming the item, its evidence level, and why it was
     * not packed -- built entirely from `$item->reason` and `$item->proof->level`,
     * never by inspecting or parsing any log output. Any `$item->details` already
     * attached are appended verbatim, in order, not reformatted or re-derived.
     */
    public static function unpackedItem(UnpackedItem $item): string
    {
        $sentence = self::reason($item->reason);
        $prefix = self::LEVEL_PREFIXES[$item->proof->level] ?? '';
        $lead = $prefix !== '' ? $prefix . ': ' : '';
        $detail = $item->details !== [] ? ' (' . implode('; ', $item->details) . ')' : '';
        return $item->instance->id() . ': ' . $lead . $sentence . $detail;
    }

    /**
     * @return array{message_key:string,arguments:array{item_id:string,evidence_level:string,details:string},default_message:string}
     */
    public static function unpackedItemDescriptor(UnpackedItem $item): array
    {
        return [
            'message_key' => 'packvium.unpacked.' . $item->reason,
            'arguments' => [
                'item_id' => $item->instance->id(),
                'evidence_level' => $item->proof->level,
                'details' => implode('; ', $item->details),
            ],
            'default_message' => self::reason($item->reason),
        ];
    }

    /**
     * `unpackedItem()` over every item, in the order given -- deterministic because
     * the input order is, and this makes no ordering decision of its own.
     *
     * @param list<UnpackedItem> $items
     * @return list<string>
     */
    public static function unpackedItems(array $items): array
    {
        return array_map(\Closure::fromCallable([self::class, 'unpackedItem']), $items);
    }
}
