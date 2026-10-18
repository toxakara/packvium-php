<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

use Packvium\Validation\MalformedResult;
use Packvium\Validation\ResultIssue;

/** The summary, the item accounting, and the proof behind every unpacked reason. */
final class Accounting
{
    private const PROVEN = ['no_compatible_container_dimensions', 'payload_exceeded', 'no_eligible_container', 'rotation_restricted', 'policy_rule'];
    private const LIMITED = ['time_limit', 'effort_limit'];
    private const OBSERVED = ['no_feasible_placement', 'search_exhausted', 'exact_search_incomplete', 'insufficient_support'];

    /** @param mixed $result @param list<ResultIssue> $issues */
    public static function checkStructure($result, array &$issues): void
    {
        $summary = Json::at($result, 'summary');
        $containers = Json::items(Json::at($result, 'containers'), 'result.containers');
        $packed = 0;
        foreach ($containers as $container) {
            $packed += \count(Json::items(Json::at($container, 'placements'), 'placements'));
        }
        $unpacked = \count(Json::items(Json::at($result, 'unpacked_items'), 'result.unpacked_items'));
        foreach (['container_count' => \count($containers), 'packed_item_count' => $packed, 'unpacked_item_count' => $unpacked] as $field => $actual) {
            $reported = Json::at($summary, $field);
            if (!Json::equalsInt($reported, $actual)) {
                $issues[] = new ResultIssue('summary_mismatch', "{$field} " . PyFormat::str($reported) . " != {$actual}");
            }
        }
        $complete = Json::at($result, 'complete');
        if (!Json::equalsInt($complete, $unpacked === 0 ? 1 : 0)) {
            $issues[] = new ResultIssue('complete_mismatch', 'complete=' . PyFormat::str($complete) . " with {$unpacked} unpacked");
        }
    }

    /**
     * Participating `require_container_tag` rules, resolved from the request alone -- a second
     * implementation of docs/POLICY-RULES.md, so a resolution bug shared with an engine cannot
     * hide behind agreement with it.
     *
     * @param mixed $request @return list<mixed>
     */
    public static function containerTagRules($request): array
    {
        $policy = Json::get($request, 'policy');
        if (!Json::isDict($policy)) {
            return [];
        }
        $declared = Json::get($policy, 'rules');
        if (!Json::isList($declared) || $declared === []) {
            return [];
        }
        $asOf = Json::intInstance(Json::get($policy, 'as_of'));
        if ($asOf === null) {
            return [];
        }
        $shipment = Json::get($policy, 'shipment');
        $shipment = Json::truthy($shipment) ? $shipment : [];
        $latest = new Ordered();
        foreach ($declared as $rule) {
            if (!Json::isDict($rule) || !Json::has($rule, 'require_container_tag')) {
                continue;
            }
            $appliesTo = Json::get($rule, 'applies_to');
            $appliesTo = Json::truthy($appliesTo) ? $appliesTo : [];
            if (!Json::isDict($appliesTo)) {
                throw MalformedResult::shape('applies_to must be an object');
            }
            foreach ($appliesTo as $fact => $value) {
                if (!Json::equals(Json::get($shipment, (string)$fact), $value)) {
                    continue 2;
                }
            }
            if (Json::compare(Json::get($rule, 'effective_at', 0), $asOf) > 0) {
                continue;
            }
            $id = Json::get($rule, 'id');
            $current = $latest->get($id);
            $newer = $current === null || Json::compare(
                [Json::get($rule, 'effective_at', 0), Json::get($rule, 'version', 0)],
                [Json::get($current, 'effective_at', 0), Json::get($current, 'version', 0)]
            ) > 0;
            if ($newer) {
                $latest->set($id, $rule);
            }
        }
        return $latest->values();
    }

    /** @param mixed $result @param list<ResultIssue> $issues */
    public static function checkAccounting($result, Context $context, array &$issues): void
    {
        $seen = new Ordered();
        $perType = new Ordered();
        foreach (Json::items(Json::at($result, 'containers'), 'result.containers') as $container) {
            foreach (Json::items(Json::at($container, 'placements'), 'placements') as $placement) {
                $itemId = Json::at($placement, 'item_id');
                if ($seen->has($itemId)) {
                    $issues[] = new ResultIssue('duplicate_item', PyFormat::str($itemId));
                }
                $seen->set($itemId, true);
                $itemType = Json::at($placement, 'item_type');
                $perType->set($itemType, $perType->get($itemType, 0) + 1);
            }
        }
        $algorithm = Json::at($result, 'algorithm');
        $timedOut = Json::truthy(Json::at($algorithm, 'time_limit_reached'));
        $effortLimited = Json::truthy(Json::at($algorithm, 'effort_limit_reached'));
        foreach (Json::items(Json::at($result, 'unpacked_items'), 'result.unpacked_items') as $unpacked) {
            $itemId = Json::at($unpacked, 'item_id');
            if ($seen->has($itemId)) {
                $issues[] = new ResultIssue('duplicate_item', PyFormat::str($itemId) . ' is both packed and unpacked');
            }
            $seen->set($itemId, true);
            $itemType = Json::at($unpacked, 'item_type');
            $perType->set($itemType, $perType->get($itemType, 0) + 1);
            if (!Json::truthy(Json::get($unpacked, 'reason'))) {
                $issues[] = new ResultIssue('missing_reason', PyFormat::str($itemId));
            }
            self::checkReasonProof($unpacked, $context->items->get($itemType), $context, $timedOut, $effortLimited, $issues);
        }
        foreach ($perType->entries() as [$itemType, $count]) {
            $spec = $context->items->get($itemType);
            if ($spec === null) {
                $issues[] = new ResultIssue('unknown_item', PyFormat::str($itemType));
            } elseif (!Json::equalsInt($spec->quantity, $count)) {
                $issues[] = new ResultIssue('quantity_mismatch', PyFormat::str($itemType) . ": {$count} accounted for, " . PyFormat::str($spec->quantity) . ' requested');
            }
        }
        foreach ($context->items->entries() as [$itemType, $spec]) {
            if (!$perType->has($itemType)) {
                $issues[] = new ResultIssue('quantity_mismatch', PyFormat::str($itemType) . ': 0 accounted for, ' . PyFormat::str($spec->quantity) . ' requested');
            }
        }
    }

    /**
     * Whether one envelope of this size fits the empty container: its frame and, for a flat bag,
     * both girth sums with the envelope alone at the origin.
     *
     * @param array<int,int|string> $size
     */
    public static function fitsEmpty(ContainerSpec $container, array $size): bool
    {
        for ($axis = 0; $axis < 3; ++$axis) {
            if (Exact::compare($size[$axis], $container->inner[$axis]) > 0) {
                return false;
            }
        }
        return !$container->flatBag
            || (Exact::compare(Exact::add($size[0], $size[2]), $container->inner[0]) <= 0
                && Exact::compare(Exact::add($size[1], $size[2]), $container->inner[1]) <= 0);
    }

    /**
     * `any(fits_empty(container, rotated envelope) for container ... for rotation ...)`, stopping
     * at the first fit as Python's generator does, so an unknown rotation is only an error when
     * the search reaches it.
     *
     * @param list<mixed> $rotations
     */
    private static function fitsSome(ItemSpec $spec, Context $context, array $rotations): bool
    {
        $margin = Exact::mul(2, $context->clearance);
        foreach ($context->containers->values() as $container) {
            foreach ($rotations as $rotation) {
                $axes = Geometry::axes(Json::text($rotation, 'a rotation'));
                $size = [
                    Exact::add($spec->dimensions[$axes[0]], $margin),
                    Exact::add($spec->dimensions[$axes[1]], $margin),
                    Exact::add($spec->dimensions[$axes[2]], $margin),
                ];
                if (self::fitsEmpty($container, $size)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** `value in tags` for a frozenset of strings; an unhashable value is Python's `TypeError`. @param array<string,true> $tags @param mixed $value */
    public static function holds(array $tags, $value): bool
    {
        Json::hashKey($value);
        return \is_string($value) && isset($tags[$value]);
    }

    /** @param mixed $unpacked @param list<ResultIssue> $issues */
    private static function checkReasonProof($unpacked, ?ItemSpec $spec, Context $context, bool $timedOut, bool $effortLimited, array &$issues): void
    {
        $itemId = PyFormat::str(Json::get($unpacked, 'item_id', '?'));
        $reason = Json::get($unpacked, 'reason', '');
        $proof = Json::get($unpacked, 'proof');
        if (!Json::isDict($proof)) {
            $issues[] = new ResultIssue('missing_reason_proof', $itemId);
            return;
        }
        Json::hashKey($reason);
        $is = static function (array $names) use ($reason): bool { return \is_string($reason) && \in_array($reason, $names, true); };
        $expected = $is(self::PROVEN) ? 'proven' : ($is(self::LIMITED) ? 'unknown_due_to_limit' : ($is(self::OBSERVED) ? 'observed' : 'inferred'));
        $level = Json::get($proof, 'level');
        if (!Json::isText($level, $expected)) {
            $issues[] = new ResultIssue('reason_proof_mismatch', "{$itemId}: " . PyFormat::repr($reason) . ' has proof ' . PyFormat::repr($level) . ', expected ' . PyFormat::stringRepr($expected));
        }
        if (Json::isText($reason, 'time_limit') && !$timedOut) {
            $issues[] = new ResultIssue('reason_limit_mismatch', "{$itemId}: time_limit reason without a reached time limit");
        }
        if (Json::isText($reason, 'effort_limit') && !$effortLimited) {
            $issues[] = new ResultIssue('reason_limit_mismatch', "{$itemId}: effort_limit reason without a reached effort limit");
        }
        if ($spec !== null) {
            self::checkProvenReason($unpacked, $reason, $spec, $context, $itemId, $issues);
        }
        self::checkObservations($proof, $reason, $itemId, $issues);
    }

    /** @param mixed $unpacked @param mixed $reason @param list<ResultIssue> $issues */
    private static function checkProvenReason($unpacked, $reason, ItemSpec $spec, Context $context, string $itemId, array &$issues): void
    {
        $every = \array_keys(Geometry::ROTATIONS);
        if (Json::isText($reason, 'no_compatible_container_dimensions')) {
            if (self::fitsSome($spec, $context, $spec->allowedRotations)) {
                $issues[] = new ResultIssue('invalid_proven_reason', "{$itemId}: at least one allowed orientation fits a container");
            }
            if (self::fitsSome($spec, $context, $every)) {
                $issues[] = new ResultIssue('invalid_proven_reason', "{$itemId}: fits at least one container once every rotation is tried, not only its own allowed set -- should be rotation_restricted");
            }
        }
        if (Json::isText($reason, 'rotation_restricted')) {
            if (self::fitsSome($spec, $context, $spec->allowedRotations)) {
                $issues[] = new ResultIssue('invalid_proven_reason', "{$itemId}: already fits a container within its own allowed rotations");
            }
            if (!self::fitsSome($spec, $context, $every)) {
                $issues[] = new ResultIssue('invalid_proven_reason', "{$itemId}: does not fit any container even with every rotation tried -- should be no_compatible_container_dimensions");
            }
        }
        if (Json::isText($reason, 'payload_exceeded')) {
            foreach ($context->containers->values() as $container) {
                if ($container->maxPayload === null || Exact::compare($spec->weight, $container->maxPayload) <= 0) {
                    $issues[] = new ResultIssue('invalid_proven_reason', "{$itemId}: its weight is within at least one container payload limit");
                    break;
                }
            }
        }
        if (Json::isText($reason, 'no_eligible_container')) {
            foreach ($context->containers->values() as $container) {
                if ($spec->eligibleContainerTags === [] || \array_intersect_key($spec->eligibleContainerTags, $container->tags) !== []) {
                    $issues[] = new ResultIssue('invalid_proven_reason', "{$itemId}: at least one container satisfies its eligible tags");
                    break;
                }
            }
        }
        if (Json::isText($reason, 'policy_rule')) {
            self::checkPolicyCitation($unpacked, $spec, $context, $itemId, $issues);
        }
    }

    /**
     * Two claims, both checked: that some rule rules this item out of every container, and that
     * the rule the result *names* is that rule.
     *
     * @param mixed $unpacked @param list<ResultIssue> $issues
     */
    private static function checkPolicyCitation($unpacked, ItemSpec $spec, Context $context, string $itemId, array &$issues): void
    {
        $blocking = [];
        foreach ($context->rules as $rule) {
            if (!self::holds($spec->tags, Json::get(Json::at($rule, 'require_container_tag'), 'item_tag'))) {
                continue;
            }
            $admitted = false;
            foreach ($context->containers->values() as $container) {
                if (self::holds($container->tags, Json::get(Json::at($rule, 'require_container_tag'), 'container_tag'))) {
                    $admitted = true;
                    break;
                }
            }
            if (!$admitted) {
                $blocking[] = $rule;
            }
        }
        if ($blocking === []) {
            $issues[] = new ResultIssue('invalid_proven_reason', "{$itemId}: no participating policy rule rules it out of every container");
            return;
        }
        $details = Json::get($unpacked, 'details');
        $cited = Json::truthy($details)
            ? \implode(' ', \array_map(static function ($detail): string { return Json::text($detail, 'details'); }, Json::items($details, 'details')))
            : '';
        foreach ($blocking as $rule) {
            $name = PyFormat::str(Json::at($rule, 'id')) . '@' . PyFormat::str(Json::at($rule, 'version'));
            if (\strpos($cited, $name) !== false) {
                return;
            }
        }
        $issues[] = new ResultIssue('invalid_proven_reason', "{$itemId}: cites " . PyFormat::stringRepr($cited) . ', which names no rule that actually blocks it');
    }

    /** @param mixed $proof @param mixed $reason @param list<ResultIssue> $issues */
    private static function checkObservations($proof, $reason, string $itemId, array &$issues): void
    {
        $observations = Json::get($proof, 'observations');
        if (!Json::isList($observations) || $observations === []) {
            $issues[] = new ResultIssue('missing_reason_observations', $itemId);
            return;
        }
        $observedReason = false;
        foreach ($observations as $observation) {
            if (!Json::isDict($observation)) {
                $issues[] = new ResultIssue('malformed_reason_observation', $itemId);
                continue;
            }
            $code = Json::get($observation, 'code');
            $count = Json::intInstance(Json::get($observation, 'count'));
            $details = Json::get($observation, 'details');
            if (!\is_string($code) || $code === '' || $count === null || $count < 1 || !Json::isList($details)) {
                $issues[] = new ResultIssue('malformed_reason_observation', $itemId);
                continue;
            }
            if (Json::equals($code, $reason)) {
                $observedReason = true;
            }
        }
        if (!$observedReason) {
            $issues[] = new ResultIssue('reason_observation_mismatch', "{$itemId}: " . PyFormat::str($reason));
        }
    }
}
