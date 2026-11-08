<?php
declare(strict_types=1);
namespace Packvium\Revisions;

use JsonException;
use Packvium\Artifacts\OperationalArtifact;
use Packvium\Domain\MadeToMeasure;
use Packvium\Support\BigInt;
use Packvium\Support\CanonicalJson;
use Packvium\Support\CanonicalJsonException;
use Packvium\Support\FixedPlacementShape;
use Packvium\Support\JsonValue;
use Packvium\Support\MeasureReading;
use Packvium\Support\RequestRules;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;
use stdClass;
use Throwable;

/**
 * Plan revisions: an append-only chain of exceptions against approved plans.
 *
 * `docs/PLAN-REVISIONS.md` is the contract, and `packvium.revisions` the reference. A revision
 * records what differed from an approved plan -- a missing item, a substituted carton, a lock,
 * a verified placement -- and derives the request the next plan solves. It is a pure function
 * of its parent, the approved artifact and its events: it calls no solver, validator or clock,
 * so four builders emit the same bytes.
 *
 * Documents are handled as `stdClass` trees. PHP has one array type for two JSON types, and a
 * digest is taken over the canonical bytes, so `{}` read as `[]` would name a different
 * document. An associative array given as input is read as an object.
 */
final class PlanRevision
{
    public const FORMAT = 'packvium-plan-revision/v1';
    public const EVENT_TYPES = ['item_missing', 'container_substituted', 'placement_locked', 'placement_verified'];
    private const EVENT_FIELDS = [
        'item_missing' => ['item_type', 'quantity'],
        'container_substituted' => ['container_type', 'replacement'],
        'placement_locked' => ['placement'],
        'placement_verified' => ['placement'],
    ];
    private const PLACEMENT_FIELDS = ['item_type', 'container_type', 'container_instance', 'position', 'orientation', 'placement_id'];
    private const JSON_DEPTH = 512;
    private const CUT_SIDES = ['length', 'width', 'height'];

    /**
     * Revision 0: the request as first approved, with nothing recorded against it.
     *
     * @param stdClass|array<string,mixed> $request
     * @throws PlanRevisionException
     */
    public static function root($request): stdClass
    {
        if (!JsonValue::isObject($request)) {
            throw new PlanRevisionException('invalid_revision', 'a request is a JSON object');
        }
        $document = self::document(0, null, null, [], self::tree($request));
        self::canonicalJson($document);
        return $document;
    }

    /**
     * The next revision: `$events`, observed against `$approvedArtifact`, applied to `$parent`.
     *
     * Copies and hashes the request/artifact in their serialized size. Placement-only replay
     * indexes canonical keys; missing-item and carton events still scan request lists.
     *
     * @param stdClass|array<string,mixed> $parent
     * @param stdClass|array<string,mixed> $approvedArtifact
     * @param list<stdClass|array<string,mixed>>|mixed $events
     * @throws PlanRevisionException
     */
    public static function derive($parent, $approvedArtifact, $events): stdClass
    {
        $parent = self::tree($parent);
        $approvedArtifact = self::tree($approvedArtifact);
        self::requireRevision($parent);
        self::requireArtifact($approvedArtifact, $parent->request);
        if ($events === [] || !JsonValue::isList($events)) {
            throw new PlanRevisionException('invalid_event', 'a revision records at least one event');
        }
        $first = self::lastSequence($parent) + 1;
        $hulls = self::hullTypes($parent->request);
        $recorded = [];
        foreach ($events as $offset => $event) {
            $recorded[] = self::event(self::tree($event), $first + $offset, $hulls);
        }
        $number = self::number($parent) + 1;
        $request = self::stockCutBoxes(self::applyEvents($parent->request, $recorded), $approvedArtifact, $number);
        $approved = new stdClass();
        $approved->artifact = self::digest($approvedArtifact);
        $approved->replay = self::tree($approvedArtifact->provenance->replay);
        $document = self::document($number, self::digest($parent), $approved, $recorded, $request);
        self::canonicalJson($document);
        return $document;
    }

    /**
     * The request these events derive from `$request`. Refuses a contradiction, never physics:
     * whether the new fixed set can hold is the engine's admission check when it is solved.
     *
     * @param stdClass|array<string,mixed>|mixed $request
     * @param list<stdClass|array<string,mixed>>|mixed $events
     * @throws PlanRevisionException
     */
    public static function applyEvents($request, $events): stdClass
    {
        if (!JsonValue::isObject($request)) {
            throw new PlanRevisionException('invalid_revision', 'a request is a JSON object');
        }
        if (!JsonValue::isList($events)) {
            throw new PlanRevisionException('invalid_event', 'events are a JSON array');
        }
        $derived = self::tree($request);
        $hulls = self::hullTypes($request);
        $placementSession = ['ready' => false, 'keys' => null, 'indexable' => true];
        foreach ($events as $event) {
            $event = self::tree($event);
            self::requireShape($event, $hulls);
            $sequence = JsonValue::get($event, 'sequence');
            if (JsonValue::integer($sequence) === null) {
                throw new PlanRevisionException('invalid_event', 'event sequence ' . CanonicalJson::spelling($sequence) . ' is not an integer');
            }
            switch ($event->type) {
                case 'item_missing':
                    self::applyItemMissing($derived, $event);
                    break;
                case 'container_substituted':
                    self::applyContainerSubstituted($derived, $event);
                    break;
                default:
                    self::applyPlacement($derived, $event, $placementSession);
            }
        }
        return $derived;
    }

    /**
     * `$request` with every made-to-measure box that holds a fixed placement made a stock
     * container. The box was cut for the approved plan, so a replan cannot recut it:
     * instance `n` of made-to-measure type `T` -- the `n`-th container of type `T` in the
     * artifact, in result order -- becomes container `T@<revision>#<n>`, a copy of `T` without
     * `made_to_measure`, quantity 1, at the cut inner and outer size, price and tare, each in
     * ticks; it follows `T` in the container list. Its fixed placements move to its instance 1
     * and `T`'s own `quantity`, if stated, loses the boxes taken (`T` is dropped at zero). New
     * boxes are still cut to measure. Pure; O(C + F + A) for containers, fixed placements and
     * the artifact's containers.
     *
     * @param stdClass|array<string,mixed> $request
     * @param stdClass|array<string,mixed>|mixed $approvedArtifact
     * @throws PlanRevisionException
     */
    public static function stockCutBoxes($request, $approvedArtifact, int $revision): stdClass
    {
        if (!JsonValue::isObject($request)) {
            throw new PlanRevisionException('invalid_revision', 'a request is a JSON object');
        }
        return self::stockedRequest(self::tree($request), self::tree($approvedArtifact), $revision);
    }

    /**
     * Every way the chain fails to be what its root and events derive, without stopping at the
     * first. `$artifacts[$k]`, when given, is the artifact revision `$k` names as approved.
     *
     * A chain or an artifact list that is not a JSON array is refused rather than audited: there
     * is no position to anchor an issue on.
     *
     * @param list<stdClass|array<string,mixed>>|mixed $revisions
     * @param list<stdClass|array<string,mixed>|null>|mixed $artifacts
     * @return list<RevisionIssue>
     * @throws PlanRevisionException
     */
    public static function verifyChain($revisions, $artifacts = null): array
    {
        if (!JsonValue::isList($revisions)) {
            throw new PlanRevisionException('invalid_revision', 'a chain is a JSON array');
        }
        if ($artifacts !== null && !JsonValue::isList($artifacts)) {
            throw new PlanRevisionException('invalid_artifact', 'artifacts is a JSON array');
        }
        $issues = [];
        $lastSequence = 0;
        $trees = [];
        foreach ($revisions as $position => $revision) {
            $revision = self::tree($revision);
            $trees[] = $revision;
            try {
                self::requireRevision($revision);
            } catch (PlanRevisionException $error) {
                $issues[] = new RevisionIssue('invalid_revision', $position, $error->getMessage());
                return $issues;
            }
            $number = self::number($revision);
            if ($number !== $position) {
                $issues[] = new RevisionIssue('revision_number', $position, "revision {$number} at position {$position}");
            }
            $parent = $position > 0 ? $trees[$position - 1] : null;
            $expectedParent = $parent === null ? null : self::digest($parent);
            $recordedParent = JsonValue::get($revision, 'parent');
            if ($recordedParent !== $expectedParent) {
                $issues[] = new RevisionIssue('parent_mismatch', $position, 'parent ' . self::plain($recordedParent) . ', expected ' . self::plain($expectedParent));
            }
            $lastSequence = self::checkSequences($revision, $position, $lastSequence, $issues);
            $artifact = $artifacts[$position] ?? null;
            if ($parent !== null) {
                self::checkRequest($revision, $parent, $artifact === null ? null : self::tree($artifact), $position, $issues);
            }
            if ($artifact !== null && $parent !== null) {
                self::checkArtifact($revision, $parent, self::tree($artifact), $position, $issues);
            }
        }
        return $issues;
    }

    /**
     * `sha256:` and the hex SHA-256 of a document's canonical bytes.
     *
     * @param stdClass|array<string,mixed> $document
     */
    public static function digest($document): string
    {
        return 'sha256:' . \hash('sha256', self::canonicalJson(self::tree($document)));
    }

    /**
     * The RFC 8785 canonical form, the bytes a digest and four engines are compared on.
     *
     * @param stdClass|array<string,mixed> $document
     * @throws PlanRevisionException
     */
    public static function canonicalJson($document): string
    {
        try {
            return CanonicalJson::encode($document);
        } catch (CanonicalJsonException $error) {
            throw new PlanRevisionException($error->errorCode(), $error->getMessage(), $error);
        }
    }

    /**
     * Decode a stored revision, request or artifact with objects as `stdClass`.
     *
     * @return mixed
     * @throws PlanRevisionException `invalid_json`
     */
    public static function parse(string $json)
    {
        try {
            return \json_decode($json, false, self::JSON_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new PlanRevisionException('invalid_json', 'not JSON text: ' . $error->getMessage(), $error);
        }
    }

    // ------------------------------------------------------------------------- building

    /** @param list<stdClass> $events */
    private static function document(int $number, ?string $parent, ?stdClass $approved, array $events, stdClass $request): stdClass
    {
        $document = new stdClass();
        $document->format = self::FORMAT;
        $document->suite_version = OperationalArtifact::SUITE_VERSION;
        $document->revision = $number;
        $document->parent = $parent;
        $document->approved = $approved;
        $document->events = $events;
        $document->request = $request;
        return $document;
    }

    /** @param mixed $document */
    private static function requireRevision($document): void
    {
        if (!$document instanceof stdClass || JsonValue::get($document, 'format') !== self::FORMAT) {
            throw new PlanRevisionException('invalid_revision', 'not a ' . self::FORMAT . ' document');
        }
        $number = JsonValue::integer(JsonValue::get($document, 'revision'));
        if ($number === null || $number < 0) {
            throw new PlanRevisionException('invalid_revision', 'revision is a non-negative integer');
        }
        if (!JsonValue::get($document, 'request') instanceof stdClass) {
            throw new PlanRevisionException('invalid_revision', 'a revision carries its request');
        }
        $events = JsonValue::get($document, 'events');
        if (!JsonValue::isList($events)) {
            throw new PlanRevisionException('invalid_revision', 'a revision carries its events');
        }
        foreach ($events as $event) {
            if (!$event instanceof stdClass || JsonValue::integer(JsonValue::get($event, 'sequence')) === null) {
                throw new PlanRevisionException('invalid_revision', "a revision's events each carry an integer sequence");
            }
        }
    }

    /** A revision number `requireRevision` has already judged an integer. */
    private static function number(stdClass $revision): int
    {
        return (int) JsonValue::integer($revision->revision);
    }

    /** @param mixed $artifact */
    private static function requireArtifact($artifact, stdClass $request): void
    {
        if (!$artifact instanceof stdClass || JsonValue::get($artifact, 'format') !== OperationalArtifact::FORMAT) {
            throw new PlanRevisionException('invalid_artifact', 'not a ' . OperationalArtifact::FORMAT . ' document');
        }
        $provenance = JsonValue::get($artifact, 'provenance');
        if (!$provenance instanceof stdClass || !JsonValue::get($provenance, 'replay') instanceof stdClass) {
            throw new PlanRevisionException('invalid_artifact', 'the artifact carries no provenance.replay');
        }
        if (!self::same(JsonValue::get($provenance, 'request'), $request)) {
            throw new PlanRevisionException('invalid_artifact', "the artifact was built from a different request than the parent's");
        }
    }

    private static function lastSequence(stdClass $revision): int
    {
        $events = $revision->events;
        if ($events !== []) {
            return self::sequence($events[\count($events) - 1]);
        }
        if (self::number($revision) !== 0) {
            throw new PlanRevisionException('invalid_revision', 'only the root revision records no events');
        }
        return 0;
    }

    /** @param mixed $raw @param list<string> $hulls */
    private static function event($raw, int $sequence, array $hulls): stdClass
    {
        self::requireShape($raw, $hulls);
        $given = JsonValue::get($raw, 'sequence');
        if (JsonValue::integer($given) !== $sequence) {
            throw new PlanRevisionException('invalid_event', 'event sequence ' . CanonicalJson::spelling($given) . " does not continue the chain at {$sequence}");
        }
        return $raw;
    }

    /**
     * The request's hull item types, which alone may be placed in a signed orientation (1.10).
     *
     * @param mixed $request
     * @return list<string>
     */
    private static function hullTypes($request): array
    {
        return JsonValue::isObject($request) ? FixedPlacementShape::hullItemTypes(JsonValue::get($request, 'items')) : [];
    }

    /** @param mixed $raw @param list<string> $hulls */
    private static function requireShape($raw, array $hulls): void
    {
        if (!$raw instanceof stdClass) {
            throw new PlanRevisionException('invalid_event', 'an event is a JSON object');
        }
        $kind = JsonValue::get($raw, 'type');
        if (!\is_string($kind) || !isset(self::EVENT_FIELDS[$kind])) {
            throw new PlanRevisionException('invalid_event', 'unknown event type ' . CanonicalJson::spelling($kind));
        }
        $unknown = JsonValue::namesOutside($raw, \array_merge(['sequence', 'type'], self::EVENT_FIELDS[$kind]));
        if ($unknown !== []) {
            throw new PlanRevisionException('invalid_event', "{$kind} does not carry " . CanonicalJson::spelling($unknown));
        }
        $missing = \array_values(\array_filter(self::EVENT_FIELDS[$kind], static function (string $field) use ($raw): bool {
            return !JsonValue::has($raw, $field);
        }));
        if ($missing !== []) {
            throw new PlanRevisionException('invalid_event', "{$kind} needs " . CanonicalJson::spelling($missing));
        }
        if ($kind === 'item_missing') {
            self::validateItemMissing($raw);
        } elseif ($kind === 'container_substituted') {
            self::validateContainerSubstituted($raw);
        } else {
            self::validatePlacement($raw, $hulls);
        }
    }

    private static function validateItemMissing(stdClass $event): void
    {
        self::requireName($event->item_type, 'item_type');
        $quantity = JsonValue::integer($event->quantity);
        if ($quantity === null || $quantity < 1) {
            throw new PlanRevisionException('invalid_event', 'item_missing.quantity is a positive integer');
        }
    }

    private static function validateContainerSubstituted(stdClass $event): void
    {
        self::requireName($event->container_type, 'container_type');
        if (!$event->replacement instanceof stdClass) {
            throw new PlanRevisionException('invalid_event', 'a replacement is a container object');
        }
        self::requireName(JsonValue::get($event->replacement, 'id'), 'replacement.id');
    }

    /** @param list<string> $hulls */
    private static function validatePlacement(stdClass $event, array $hulls): void
    {
        $placement = $event->placement;
        if (!$placement instanceof stdClass) {
            throw new PlanRevisionException('invalid_event', 'a placement is a fixed-placement object');
        }
        $unknown = JsonValue::namesOutside($placement, self::PLACEMENT_FIELDS);
        if ($unknown !== []) {
            throw new PlanRevisionException('invalid_event', 'a placement does not carry ' . CanonicalJson::spelling($unknown));
        }
        self::requireName(JsonValue::get($placement, 'item_type'), 'placement.item_type');
        self::requireName(JsonValue::get($placement, 'container_type'), 'placement.container_type');
        $hull = \in_array(JsonValue::get($placement, 'item_type'), $hulls, true);
        $refusal = RequestRules::orientationRefusal(JsonValue::get($placement, 'orientation'), $hull, 'placement.orientation');
        if ($refusal !== null) {
            throw new PlanRevisionException('invalid_event', $refusal[1]);
        }
        $instance = JsonValue::has($placement, 'container_instance') ? JsonValue::integer($placement->container_instance) : 1;
        if ($instance === null || $instance < 1) {
            throw new PlanRevisionException('invalid_event', 'placement.container_instance counts from 1');
        }
        FixedPlacementShape::requirePoint(
            JsonValue::has($placement, 'position') ? $placement->position : new stdClass(),
            'placement.position',
            '/placement/position',
            static function (string $message): Throwable {
                return new PlanRevisionException('invalid_event', $message);
            }
        );
        // Only its type is the event's to judge: whether it names this placement needs the
        // request's unit, and is the engine's admission rule when the revision is solved (1.10).
        $identifier = JsonValue::get($placement, 'placement_id');
        if ($identifier !== null && !\is_string($identifier)) {
            throw new PlanRevisionException('invalid_event', 'placement.placement_id is a string');
        }
    }

    /** @param mixed $value */
    private static function requireName($value, string $field): void
    {
        if (!\is_string($value) || $value === '') {
            throw new PlanRevisionException('invalid_event', "{$field} is a non-empty string");
        }
    }

    // ------------------------------------------------------------------------- applying

    private static function applyItemMissing(stdClass $request, stdClass $event): void
    {
        $items = self::entries($request, 'items');
        $index = self::indexOf($items, $event->item_type, 'item');
        $quantity = JsonValue::has($items[$index], 'quantity') ? JsonValue::integer($items[$index]->quantity) : 1;
        if ($quantity === null) {
            throw new PlanRevisionException('invalid_revision', "request.items[{$index}].quantity is an integer");
        }
        $remaining = $quantity - (int) JsonValue::integer($event->quantity);
        $fixed = 0;
        foreach (self::fixedPlacements($request) as $entry) {
            if (JsonValue::get($entry, 'item_type') === $event->item_type) {
                $fixed++;
            }
        }
        if ($remaining < $fixed) {
            throw new PlanRevisionException(
                'event_conflict',
                'event ' . self::sequence($event) . ": {$fixed} {$event->item_type} are fixed, " . \max($remaining, 0) . ' would remain'
            );
        }
        if ($remaining > 0) {
            $items[$index]->quantity = $remaining;
            return;
        }
        if (\count($items) === 1) {
            throw new PlanRevisionException('event_conflict', 'event ' . self::sequence($event) . ': no item would remain to pack');
        }
        \array_splice($items, $index, 1);
        $request->items = $items;
    }

    private static function applyContainerSubstituted(stdClass $request, stdClass $event): void
    {
        $containers = self::entries($request, 'containers');
        $index = self::indexOf($containers, $event->container_type, 'container');
        foreach (self::fixedPlacements($request) as $entry) {
            if (JsonValue::get($entry, 'container_type') === $event->container_type) {
                throw new PlanRevisionException('event_conflict', 'event ' . self::sequence($event) . ": fixed placements are in {$event->container_type}");
            }
        }
        $replacementId = $event->replacement->id;
        foreach ($containers as $position => $container) {
            if ($position !== $index && JsonValue::get($container, 'id') === $replacementId) {
                throw new PlanRevisionException('event_conflict', 'event ' . self::sequence($event) . ": another container is already {$replacementId}");
            }
        }
        $containers[$index] = self::tree($event->replacement);
        $request->containers = $containers;
    }

    /** @param array{ready:bool,keys:?array<string,bool>,indexable:bool} $session */
    private static function applyPlacement(stdClass $request, stdClass $event, array &$session): void
    {
        if (!$session['ready']) {
            $request->fixed_placements = self::fixedPlacements($request);
            $session['ready'] = true;
            $session['keys'] = [];
            try {
                foreach ($request->fixed_placements as $entry) {
                    $session['keys'][self::canonicalJson(self::identity($entry))] = true;
                }
            } catch (PlanRevisionException $error) {
                $session['indexable'] = false;
            }
        }
        // One box cannot be two fixed items: a lock that is later verified in place is recorded
        // twice in the chain and once in the request.
        $placement = self::identity($event->placement);
        if ($session['indexable']) {
            try {
                $key = self::canonicalJson($placement);
            } catch (PlanRevisionException $error) {
                $session['indexable'] = false;
            }
            if ($session['indexable']) {
                if (isset($session['keys'][$key])) {
                    return;
                }
                $request->fixed_placements[] = self::tree($event->placement);
                $session['keys'][$key] = true;
                return;
            }
        }
        // Keep the old first-match/error order for values outside canonical JSON.
        foreach ($request->fixed_placements as $entry) {
            if (self::same(self::identity($entry), $placement)) {
                return;
            }
        }
        $request->fixed_placements[] = self::tree($event->placement);
    }

    /**
     * What makes two fixed entries one box: everything but `placement_id`, which only restates
     * the container and position (1.10), with the default instance spelled out.
     */
    private static function identity(stdClass $placement): stdClass
    {
        $copy = self::tree($placement);
        unset($copy->placement_id);
        if (!JsonValue::has($copy, 'container_instance')) {
            $copy->container_instance = 1;
        }
        return $copy;
    }

    /**
     * The request's fixed placements; absent or null is none, and anything else that is not a
     * list of objects is a request no event can safely edit.
     *
     * @return list<stdClass>
     */
    private static function fixedPlacements(stdClass $request): array
    {
        return JsonValue::get($request, 'fixed_placements') === null ? [] : self::entries($request, 'fixed_placements');
    }

    /** @return list<stdClass> */
    private static function entries(stdClass $request, string $key): array
    {
        $entries = JsonValue::get($request, $key);
        if (!JsonValue::isList($entries)) {
            throw new PlanRevisionException('invalid_revision', "request.{$key} is a list of objects");
        }
        foreach ($entries as $entry) {
            if (!$entry instanceof stdClass) {
                throw new PlanRevisionException('invalid_revision', "request.{$key} is a list of objects");
            }
        }
        return $entries;
    }

    /** @param list<stdClass> $entries */
    private static function indexOf(array $entries, string $identifier, string $kind): int
    {
        foreach ($entries as $index => $entry) {
            if (JsonValue::get($entry, 'id') === $identifier) {
                return $index;
            }
        }
        throw new PlanRevisionException('event_conflict', "the request has no {$kind} " . CanonicalJson::spelling($identifier));
    }

    /** An event's sequence, one `requireRevision` or `applyEvents` has already judged an integer. */
    private static function sequence(stdClass $event): int
    {
        return (int) JsonValue::integer($event->sequence);
    }

    // ---------------------------------------------------------------------------- audit

    /** @param list<RevisionIssue> $issues */
    private static function checkSequences(stdClass $revision, int $position, int $last, array &$issues): int
    {
        $events = $revision->events;
        if ($position === 0 && $events !== []) {
            $issues[] = new RevisionIssue('sequence_gap', 0, 'the root revision records no events');
        }
        if ($position > 0 && $events === []) {
            $issues[] = new RevisionIssue('sequence_gap', $position, 'a revision records at least one event');
        }
        foreach ($events as $event) {
            $sequence = self::sequence($event);
            if ($sequence !== $last + 1) {
                $issues[] = new RevisionIssue('sequence_gap', $position, "event {$sequence} where " . ($last + 1) . ' was next');
            }
            $last = $sequence;
        }
        return $last;
    }

    /**
     * @param mixed $artifact the artifact this revision approved, when the caller gave one
     * @param list<RevisionIssue> $issues
     */
    private static function checkRequest(stdClass $revision, stdClass $parent, $artifact, int $position, array &$issues): void
    {
        try {
            $derived = self::applyEvents($parent->request, $revision->events);
            if (self::holdsCutBoxes($derived)) {
                // The cut sizes live only in the approved artifact.
                if ($artifact === null) {
                    $issues[] = new RevisionIssue('artifact_required', $position, 'fixed items in a made-to-measure box are checked against the approved artifact');
                    return;
                }
                $derived = self::stockedRequest($derived, $artifact, self::number($revision));
            }
        } catch (PlanRevisionException $error) {
            $issues[] = new RevisionIssue('request_mismatch', $position, 'the events do not apply: ' . $error->getMessage());
            return;
        }
        if (!self::same($derived, $revision->request)) {
            $issues[] = new RevisionIssue('request_mismatch', $position, "the request is not what the parent's request and these events derive");
        }
    }

    /**
     * @param mixed $artifact whatever the caller's list held at this position
     * @param list<RevisionIssue> $issues
     */
    private static function checkArtifact(stdClass $revision, stdClass $parent, $artifact, int $position, array &$issues): void
    {
        $approved = JsonValue::get($revision, 'approved');
        $digest = self::digestOrNull($artifact);
        if ($digest === null || !$approved instanceof stdClass || JsonValue::get($approved, 'artifact') !== $digest) {
            $issues[] = new RevisionIssue('artifact_mismatch', $position, "the artifact's digest is not the one this revision approved");
            return;
        }
        $provenance = JsonValue::get($artifact, 'provenance');
        if (!$provenance instanceof stdClass || !self::same(JsonValue::get($provenance, 'request'), $parent->request)) {
            $issues[] = new RevisionIssue('artifact_mismatch', $position, "the artifact was built from a different request than the parent's");
        } elseif (!self::same(JsonValue::get($provenance, 'replay'), JsonValue::get($approved, 'replay'))) {
            $issues[] = new RevisionIssue('artifact_mismatch', $position, "approved.replay is not the artifact's provenance.replay");
        }
    }

    // ------------------------------------------------------------- cut-to-measure stock

    /**
     * `stockCutBoxes` on a tree already deep-copied, so it mutates freely.
     *
     * @param mixed $artifact
     * @throws PlanRevisionException
     */
    private static function stockedRequest(stdClass $derived, $artifact, int $revision): stdClass
    {
        $fixed = JsonValue::get($derived, 'fixed_placements');
        $containers = JsonValue::get($derived, 'containers');
        if (!JsonValue::isList($fixed) || !JsonValue::isList($containers)) {
            return $derived;
        }
        $machines = self::machines($containers);
        $stocked = [];
        $named = [];
        $cuts = null;
        foreach ($fixed as $entry) {
            $machineId = $entry instanceof stdClass ? JsonValue::get($entry, 'container_type') : null;
            if (!\is_string($machineId) || !isset($machines[$machineId])) {
                continue;
            }
            $instance = JsonValue::integer(JsonValue::has($entry, 'container_instance') ? $entry->container_instance : 1);
            $stockId = "{$machineId}@{$revision}#" . ($instance === null ? 'None' : $instance);
            if (!isset($named[$stockId])) {
                $cuts = $cuts ?? self::cutBoxes($artifact);
                $named[$stockId] = self::stockBox($derived, $machines[$machineId], $stockId, self::cutInner($cuts, $machineId, $instance));
                $stocked[$machineId][] = $named[$stockId];
            }
            $entry->container_type = $stockId;
            $entry->container_instance = 1;
            // A stated id named the cut box's instance of the machine, which this request no
            // longer has; admission would refuse it as a mismatch.
            unset($entry->placement_id);
        }
        if ($named === []) {
            return $derived;
        }
        self::refuseClash(\array_keys($named), $containers);
        $derived->containers = self::rebuilt($containers, $stocked);
        return $derived;
    }

    /** Whether a fixed placement names a made-to-measure container type. @param stdClass $request */
    private static function holdsCutBoxes(stdClass $request): bool
    {
        $containers = JsonValue::get($request, 'containers');
        $fixed = JsonValue::get($request, 'fixed_placements');
        if (!JsonValue::isList($containers) || !JsonValue::isList($fixed)) {
            return false;
        }
        $machines = self::machines($containers);
        foreach ($fixed as $entry) {
            $type = $entry instanceof stdClass ? JsonValue::get($entry, 'container_type') : null;
            if (\is_string($type) && isset($machines[$type])) {
                return true;
            }
        }
        return false;
    }

    /** @param list<mixed> $containers @return array<string,stdClass> */
    private static function machines(array $containers): array
    {
        $machines = [];
        foreach ($containers as $entry) {
            if ($entry instanceof stdClass && \is_string(JsonValue::get($entry, 'id')) && JsonValue::get($entry, 'made_to_measure') !== null) {
                $machines[$entry->id] = $entry;
            }
        }
        return $machines;
    }

    /** @param list<string> $names @param list<mixed> $containers */
    private static function refuseClash(array $names, array $containers): void
    {
        $existing = [];
        foreach ($containers as $container) {
            $id = $container instanceof stdClass ? JsonValue::get($container, 'id') : null;
            if (\is_string($id)) {
                $existing[$id] = true;
            }
        }
        $clash = null;
        foreach ($names as $name) {
            if (isset($existing[$name]) && ($clash === null || strcmp($name, $clash) < 0)) {
                $clash = $name;
            }
        }
        if ($clash !== null) {
            throw new PlanRevisionException('event_conflict', 'a container is already named ' . CanonicalJson::spelling($clash));
        }
    }

    /**
     * @param list<mixed> $containers @param array<string,list<stdClass>> $stocked
     * @return list<mixed>
     */
    private static function rebuilt(array $containers, array $stocked): array
    {
        $rebuilt = [];
        foreach ($containers as $container) {
            $id = $container instanceof stdClass ? JsonValue::get($container, 'id') : null;
            $boxes = \is_string($id) ? ($stocked[$id] ?? []) : [];
            if ($boxes === []) {
                $rebuilt[] = $container;
                continue;
            }
            $quantity = JsonValue::get($container, 'quantity') === null ? null : JsonValue::integer($container->quantity);
            if ($quantity === null || $quantity > \count($boxes)) {
                if ($quantity !== null) {
                    $container->quantity = $quantity - \count($boxes);
                }
                $rebuilt[] = $container;
            }
            \array_push($rebuilt, ...$boxes);
        }
        return $rebuilt;
    }

    /**
     * Index raw shapes in artifact order; only selected dimensions are parsed. O(A).
     *
     * @param mixed $artifact @return array<string,list<mixed>>
     */
    private static function cutBoxes($artifact): array
    {
        $work = JsonValue::isObject($artifact) ? JsonValue::get($artifact, 'work_order') : null;
        $geometry = JsonValue::isObject($artifact) ? JsonValue::get($artifact, 'geometry') : null;
        $orders = JsonValue::isObject($work) ? JsonValue::get($work, 'containers') : null;
        $shapes = JsonValue::isObject($geometry) ? JsonValue::get($geometry, 'containers') : null;
        $cuts = [];
        if (JsonValue::isList($orders) && JsonValue::isList($shapes)) {
            $count = \min(\count($orders), \count($shapes));
            for ($index = 0; $index < $count; ++$index) {
                $type = JsonValue::isObject($orders[$index]) ? JsonValue::get($orders[$index], 'container_type') : null;
                if (\is_string($type)) {
                    $cuts[$type][] = $shapes[$index];
                }
            }
        }
        return $cuts;
    }

    /** @param array<string,list<mixed>> $cuts @return array{0:int,1:int,2:int} */
    private static function cutInner(array $cuts, string $machineId, ?int $instance): array
    {
        $shapes = $cuts[$machineId] ?? [];
        if ($instance !== null && $instance >= 1 && $instance <= \count($shapes)) {
            $shape = $shapes[$instance - 1];
            return [self::artifactTicks($shape, 'length'), self::artifactTicks($shape, 'width'), self::artifactTicks($shape, 'height')];
        }
        throw new PlanRevisionException('invalid_artifact', "the approved plan holds no {$machineId} instance " . CanonicalJson::spelling($instance));
    }

    /** @param mixed $shape */
    private static function artifactTicks($shape, string $axis): int
    {
        $sides = JsonValue::isObject($shape) ? JsonValue::get($shape, 'inner_dimensions') : null;
        $value = JsonValue::isObject($sides) ? JsonValue::get($sides, $axis) : null;
        if (!\is_string($value) || \preg_match('/^[0-9]+$/', $value) !== 1
            || BigInt::compare(\ltrim($value, '0') ?: '0', (string) JsonValue::MAX_EXACT_MAGNITUDE) > 0) {
            throw new PlanRevisionException('invalid_artifact', "a cut box's {$axis} is not a tick count");
        }
        return (int) $value;
    }

    /**
     * The stock container a cut box becomes, priced as `Container::fabricate` prices it.
     *
     * @param array{0:int,1:int,2:int} $inner
     */
    private static function stockBox(stdClass $request, stdClass $machine, string $stockId, array $inner): stdClass
    {
        $units = self::stockObject(JsonValue::get($request, 'units'), 'units', true);
        $unit = JsonValue::get($units, 'length') ?? 'mm';
        if (!\is_string($unit)) {
            throw new PlanRevisionException('invalid_revision', "a made-to-measure container's units.length is not a string");
        }
        $spec = self::stockObject($machine->made_to_measure, 'made_to_measure');
        $allowance = self::stockObject(JsonValue::get($spec, 'outer_allowance'), 'outer_allowance', true);
        $outer = [];
        foreach (self::CUT_SIDES as $index => $axis) {
            $side = JsonValue::get($allowance, $axis);
            $outer[] = $inner[$index] + ($side === null ? 0 : self::stockMeasure($side, Length::class, $unit, 'outer_allowance'));
        }
        $area = MadeToMeasure::surfaceArea($inner);
        $price = self::stockInteger(JsonValue::get($spec, 'cost_per_square_metre_minor'), 'cost_per_square_metre_minor');
        $rate = JsonValue::get($spec, 'tare_per_square_metre');
        $tareRate = $rate === null ? 0 : self::stockMeasure($rate, Weight::class, 'g', 'tare_per_square_metre');
        $tare = JsonValue::get($machine, 'tare_weight');
        $declaredTare = $tare === null ? 0 : self::stockMeasure($tare, Weight::class, 'g', 'tare_weight');
        $box = new stdClass();
        foreach (JsonValue::members($machine) as [$name, $value]) {
            if ($name !== 'made_to_measure') {
                $box->{$name} = self::tree($value);
            }
        }
        $box->id = $stockId;
        $box->quantity = 1;
        $box->inner_dimensions = self::tickBox($inner);
        $box->outer_dimensions = self::tickBox($outer);
        $fee = self::stockInteger(JsonValue::get($machine, 'cost_minor'), 'cost_minor');
        $box->cost_minor = (int) BigInt::add((string) $fee, MadeToMeasure::perSquareMetre($price, $area));
        $box->tare_weight = (object) ['value' => BigInt::add((string) $declaredTare, MadeToMeasure::perSquareMetre($tareRate, $area)), 'unit' => 'ticks'];
        return $box;
    }

    /** @param mixed $value */
    private static function stockObject($value, string $name, bool $optional = false): stdClass
    {
        if ($value === null && $optional) {
            return new stdClass();
        }
        if (!$value instanceof stdClass) {
            throw new PlanRevisionException('invalid_revision', "a made-to-measure container's {$name} is not an object");
        }
        return $value;
    }

    /** @param mixed $value */
    private static function stockInteger($value, string $name): int
    {
        $integer = JsonValue::integer($value ?? 0);
        if ($integer === null) {
            throw new PlanRevisionException('invalid_revision', "a made-to-measure container's {$name} is not an integer");
        }
        return $integer;
    }

    /** @param mixed $value @param class-string<Length>|class-string<Weight> $kind */
    private static function stockMeasure($value, string $kind, string $unit, string $name): int
    {
        if (MeasureReading::verdict($value, $kind, $unit) !== MeasureReading::READABLE) {
            throw new PlanRevisionException('invalid_revision', "a made-to-measure container's {$name} is not a measure");
        }
        return MeasureReading::ticks($value, $kind, $unit);
    }

    /** @param list<int> $sides */
    private static function tickBox(array $sides): stdClass
    {
        $box = new stdClass();
        foreach (self::CUT_SIDES as $index => $axis) {
            $box->{$axis} = (object) ['value' => (string) $sides[$index], 'unit' => 'ticks'];
        }
        return $box;
    }

    // --------------------------------------------------------------------------- values

    /**
     * Equality as the canonical form sees it, so `1.0` and `1` are one value, as in every engine.
     *
     * @param mixed $left
     * @param mixed $right
     */
    private static function same($left, $right): bool
    {
        return self::canonicalJson($left) === self::canonicalJson($right);
    }

    /**
     * A deep copy with every JSON object as a `stdClass`, so a caller's tree is never mutated
     * and an associative array cannot be mistaken for a list.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function tree($value)
    {
        if ($value instanceof stdClass || (\is_array($value) && !JsonValue::isList($value))) {
            $object = new stdClass();
            foreach (JsonValue::members($value) as [$name, $member]) {
                $object->{$name} = self::tree($member);
            }
            return $object;
        }
        if (\is_array($value)) {
            return \array_map([self::class, 'tree'], $value);
        }
        return $value;
    }

    /**
     * An audited artifact's digest; one with no canonical form matches no approval.
     *
     * @param mixed $document
     */
    private static function digestOrNull($document): ?string
    {
        try {
            return self::digest($document);
        } catch (PlanRevisionException $error) {
            return null;
        }
    }

    /**
     * A digest as itself, and anything a tampered chain puts in its place as JSON.
     *
     * @param mixed $value
     */
    private static function plain($value): string
    {
        return \is_string($value) ? $value : CanonicalJson::spelling($value);
    }
}
