<?php
declare(strict_types=1);
namespace Packvium\Revisions;

use JsonException;
use Packvium\Artifacts\OperationalArtifact;
use Packvium\Support\CanonicalJson;
use Packvium\Support\CanonicalJsonException;
use Packvium\Support\FixedPlacementShape;
use Packvium\Support\JsonValue;
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
    private const PLACEMENT_FIELDS = ['item_type', 'container_type', 'container_instance', 'position', 'orientation'];
    private const JSON_DEPTH = 512;

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
        $recorded = [];
        foreach ($events as $offset => $event) {
            $recorded[] = self::event(self::tree($event), $first + $offset);
        }
        $request = self::applyEvents($parent->request, $recorded);
        $approved = new stdClass();
        $approved->artifact = self::digest($approvedArtifact);
        $approved->replay = self::tree($approvedArtifact->provenance->replay);
        $document = self::document(self::number($parent) + 1, self::digest($parent), $approved, $recorded, $request);
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
        $placementSession = ['ready' => false, 'keys' => null, 'indexable' => true];
        foreach ($events as $event) {
            $event = self::tree($event);
            self::requireShape($event);
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
            if ($parent !== null) {
                self::checkRequest($revision, $parent, $position, $issues);
            }
            $artifact = $artifacts[$position] ?? null;
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

    /** @param mixed $raw */
    private static function event($raw, int $sequence): stdClass
    {
        self::requireShape($raw);
        $given = JsonValue::get($raw, 'sequence');
        if (JsonValue::integer($given) !== $sequence) {
            throw new PlanRevisionException('invalid_event', 'event sequence ' . CanonicalJson::spelling($given) . " does not continue the chain at {$sequence}");
        }
        return $raw;
    }

    /** @param mixed $raw */
    private static function requireShape($raw): void
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
            self::validatePlacement($raw);
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

    private static function validatePlacement(stdClass $event): void
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
        if (!\in_array(JsonValue::get($placement, 'orientation'), FixedPlacementShape::ORIENTATIONS, true)) {
            throw new PlanRevisionException('invalid_event', 'placement.orientation is one of the six codes');
        }
        $instance = JsonValue::has($placement, 'container_instance') ? JsonValue::integer($placement->container_instance) : 1;
        if ($instance === null || $instance < 1) {
            throw new PlanRevisionException('invalid_event', 'placement.container_instance counts from 1');
        }
        FixedPlacementShape::requirePoint(
            JsonValue::has($placement, 'position') ? $placement->position : new stdClass(),
            'placement.position',
            '/placement/position',
            static fn(string $message): Throwable => new PlanRevisionException('invalid_event', $message)
        );
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
                    $session['keys'][self::canonicalJson(self::withDefaultInstance($entry))] = true;
                }
            } catch (PlanRevisionException $error) {
                $session['indexable'] = false;
            }
        }
        // One box cannot be two fixed items: a lock that is later verified in place is recorded
        // twice in the chain and once in the request.
        $placement = self::withDefaultInstance($event->placement);
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
            if (self::same(self::withDefaultInstance($entry), $placement)) {
                return;
            }
        }
        $request->fixed_placements[] = self::tree($event->placement);
    }

    private static function withDefaultInstance(stdClass $placement): stdClass
    {
        $copy = self::tree($placement);
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

    /** @param list<RevisionIssue> $issues */
    private static function checkRequest(stdClass $revision, stdClass $parent, int $position, array &$issues): void
    {
        try {
            $derived = self::applyEvents($parent->request, $revision->events);
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
