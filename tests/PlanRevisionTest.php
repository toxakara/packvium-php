<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Artifacts\OperationalArtifact;
use Packvium\Revisions\{PlanRevision, PlanRevisionException};
use Packvium\Serialization\ArrayCodec;
use stdClass;

/**
 * Plan revisions: an append-only, hash-chained record of exceptions (docs/PLAN-REVISIONS.md).
 * Mirrors `packvium-python/tests/test_revisions.py`; the cross-language byte comparison is
 * the conformance run's.
 */
final class PlanRevisionTest extends TestCase
{
    public static function testTheRootRecordsNothingAgainstTheRequest(): void
    {
        $root = PlanRevision::root(self::request());
        self::assertSame(PlanRevision::FORMAT, $root->format);
        self::assertSame(OperationalArtifact::SUITE_VERSION, $root->suite_version);
        self::assertSame(0, $root->revision);
        self::assertNull($root->parent);
        self::assertNull($root->approved);
        self::assertSame([], $root->events);
    }

    public static function testARevisionLinksItsParentAndApprovedArtifactByDigest(): void
    {
        [$revisions, $artifacts] = self::chain();
        self::assertSame(1, $revisions[1]->revision);
        self::assertSame(PlanRevision::digest($revisions[0]), $revisions[1]->parent);
        self::assertSame(PlanRevision::digest($artifacts[1]), $revisions[1]->approved->artifact);
        self::assertSame(1, preg_match('/^sha256:[0-9a-f]{64}$/', $revisions[1]->parent));
    }

    public static function testADigestIsSha256OverTheCanonicalBytes(): void
    {
        $root = PlanRevision::root(self::request());
        self::assertSame('sha256:' . hash('sha256', PlanRevision::canonicalJson($root)), PlanRevision::digest($root));
    }

    public static function testAnEmptyObjectStaysAnObjectThroughADigest(): void
    {
        $request = PlanRevision::parse('{"items":[],"containers":[],"output":{}}');
        $root = PlanRevision::root($request);
        self::assertTrue(str_contains(PlanRevision::canonicalJson($root), '"output":{}'));
        $reparsed = PlanRevision::parse(json_encode($root, JSON_PRETTY_PRINT));
        self::assertSame(PlanRevision::digest($root), PlanRevision::digest($reparsed));
    }

    public static function testAMissingItemLowersItsQuantityAndALastOneRemovesTheType(): void
    {
        $lowered = PlanRevision::applyEvents(self::request(), [self::missing('cube', 3)]);
        self::assertSame(1, $lowered->items[0]->quantity);
        $removed = PlanRevision::applyEvents(self::request(), [self::missing('slab', 1)]);
        self::assertSame(['cube'], array_map(static fn(stdClass $item): string => $item->id, $removed->items));
    }

    public static function testASubstitutedContainerIsReplacedWhereItStood(): void
    {
        $derived = PlanRevision::applyEvents(self::request(), [[
            'sequence' => 1, 'type' => 'container_substituted', 'container_type' => 'box',
            'replacement' => ['id' => 'box-b', 'inner_dimensions' => ['length' => '210', 'width' => '110', 'height' => '210']],
        ]]);
        self::assertSame(['box-b', 'crate'], array_map(static fn(stdClass $c): string => $c->id, $derived->containers));
    }

    public static function testALockThenAVerificationOfTheSameBoxFixesItOnce(): void
    {
        $derived = PlanRevision::applyEvents(self::request(), [self::locked(), self::locked('placement_verified', 2)]);
        self::assertCount(1, $derived->fixed_placements);
    }

    public static function testAFixedSetWithNoCanonicalFormStillDeduplicatesByFirstMatch(): void
    {
        $unholdable = self::locked()['placement'];
        $unholdable['position']['x'] = 2 ** 60;
        $request = self::request() + ['fixed_placements' => [self::locked()['placement'], $unholdable]];
        $derived = PlanRevision::applyEvents($request, [self::locked()]);
        self::assertCount(2, $derived->fixed_placements);
        self::assertSame(2 ** 60, $derived->fixed_placements[1]->position->x);
    }

    public static function testALockWithNoCanonicalFormIsRecordedForAdmissionToJudge(): void
    {
        $event = self::locked();
        $event['placement']['position']['x'] = 2 ** 60;
        $derived = PlanRevision::applyEvents(self::request(), [$event]);
        self::assertCount(1, $derived->fixed_placements);
        self::assertSame(2 ** 60, $derived->fixed_placements[0]->position->x);
    }

    public static function testADerivedRequestSolvesWithItsFixedItemsInPlace(): void
    {
        [$revisions] = self::chain();
        $request = json_decode(json_encode($revisions[2]->request), true);
        $result = ArrayCodec::pack($request);
        $fixed = [];
        foreach ($result['containers'] as $container) {
            foreach ($container['placements'] as $placement) {
                if (($placement['fixed'] ?? false) === true) {
                    $fixed[] = [$container['id'], $placement['item_id']];
                }
            }
        }
        self::assertSame([['box#1', 'cube#1']], $fixed);
    }

    public static function testContradictionsAreEventConflicts(): void
    {
        $fixed = PlanRevision::applyEvents(self::request(), [self::locked()]);
        foreach ([
            [self::request(), self::missing('pallet', 1)],
            [$fixed, self::missing('cube', 4, 2)],
            [$fixed, ['sequence' => 2, 'type' => 'container_substituted', 'container_type' => 'box', 'replacement' => ['id' => 'box-b']]],
            [self::request(), ['sequence' => 1, 'type' => 'container_substituted', 'container_type' => 'box', 'replacement' => ['id' => 'crate']]],
        ] as [$request, $event]) {
            self::assertSame('event_conflict', self::code(static fn() => PlanRevision::applyEvents($request, [$event])));
        }
    }

    public static function testMalformedEventsAreRefused(): void
    {
        $root = PlanRevision::root(self::request());
        $artifact = self::artifact($root->request);
        foreach ([
            [],
            [self::locked('placement_locked', 2)],
            [array_replace(self::locked(), ['type' => 'placement_moved'])],
            [array_replace(self::locked(), ['note' => 'strapped'])],
            [['sequence' => 1, 'type' => 'item_missing', 'item_type' => 'cube']],
            [['sequence' => 1, 'type' => 'item_missing', 'item_type' => 'cube', 'quantity' => 0]],
            [['sequence' => true, 'type' => 'item_missing', 'item_type' => 'cube', 'quantity' => 1]],
            [['sequence' => 1, 'type' => 'placement_locked', 'placement' => array_replace(self::locked()['placement'], ['orientation' => 'XYZ'])]],
        ] as $events) {
            self::assertSame('invalid_event', self::code(static fn() => PlanRevision::derive($root, $artifact, $events)));
        }
    }

    public static function testAnArtifactBuiltFromAnotherRequestIsRefused(): void
    {
        $root = PlanRevision::root(self::request());
        $other = self::request();
        $other['items'][0]['quantity'] = 3;
        self::assertSame('invalid_artifact', self::code(static fn() => PlanRevision::derive($root, self::artifact($other), [self::locked()])));
    }

    public static function testAnIntactChainVerifiesClean(): void
    {
        [$revisions, $artifacts] = self::chain();
        self::assertSame([], PlanRevision::verifyChain($revisions, $artifacts));
    }

    public static function testTamperingIsCaught(): void
    {
        [$revisions] = self::chain();
        $reordered = self::copy($revisions);
        [$reordered[1]->events[0], $reordered[1]->events[1]] = [$reordered[1]->events[1], $reordered[1]->events[0]];
        self::assertContains('sequence_gap', self::codes($reordered));

        $missing = self::copy($revisions);
        array_splice($missing[1]->events, 1, 1);
        self::assertContains('request_mismatch', self::codes($missing));

        $altered = self::copy($revisions);
        $altered[1]->request->items[0]->quantity = 9;
        self::assertContains('parent_mismatch', self::codes($altered));
        self::assertContains('request_mismatch', self::codes($altered));

        $dropped = self::copy($revisions);
        array_splice($dropped, 1, 1);
        self::assertContains('revision_number', self::codes($dropped));
    }

    public static function testASubstitutedArtifactIsCaught(): void
    {
        [$revisions, $artifacts] = self::chain();
        $artifacts[2] = $artifacts[1];
        self::assertContains('artifact_mismatch', array_map(static fn($issue): string => $issue->code, PlanRevision::verifyChain($revisions, $artifacts)));
    }

    public static function testMalformedParentsRequestsAndArtifactsAreRefused(): void
    {
        $artifact = self::artifact(self::request());
        self::assertSame('invalid_revision', self::code(static fn() => PlanRevision::root([1, 2])));
        self::assertSame('number_out_of_range', self::code(static fn() => PlanRevision::root(['configuration' => ['seed' => 2 ** 60]])));
        foreach ([
            self::request(),
            ['format' => PlanRevision::FORMAT, 'revision' => -1, 'request' => ['a' => 1], 'events' => []],
            ['format' => PlanRevision::FORMAT, 'revision' => 0, 'request' => [], 'events' => []],
            ['format' => PlanRevision::FORMAT, 'revision' => 0, 'request' => ['a' => 1], 'events' => ['x' => 1]],
            ['format' => PlanRevision::FORMAT, 'revision' => 2, 'request' => self::request(), 'events' => []],
        ] as $parent) {
            self::assertSame('invalid_revision', self::code(static fn() => PlanRevision::derive($parent, $artifact, [self::locked()])));
        }
        $root = PlanRevision::root(self::request());
        foreach ([['format' => 'packvium-execution-plan/v1'], ['format' => OperationalArtifact::FORMAT, 'provenance' => ['request' => []]]] as $bad) {
            self::assertSame('invalid_artifact', self::code(static fn() => PlanRevision::derive($root, $bad, [self::locked()])));
        }
        self::assertSame('invalid_json', self::code(static fn() => PlanRevision::parse('{')));
    }

    public static function testMalformedEventPayloadsAreRefused(): void
    {
        $root = PlanRevision::root(self::request());
        $artifact = self::artifact(self::request());
        $placement = self::locked()['placement'];
        foreach ([
            'item_missing',
            ['sequence' => 1, 'type' => 'container_substituted', 'container_type' => '', 'replacement' => ['id' => 'b']],
            ['sequence' => 1, 'type' => 'container_substituted', 'container_type' => 'box', 'replacement' => 'b'],
            ['sequence' => 1, 'type' => 'placement_locked', 'placement' => 'box#1'],
            ['sequence' => 1, 'type' => 'placement_locked', 'placement' => array_replace($placement, ['item_id' => 'cube#1'])],
            ['sequence' => 1, 'type' => 'placement_locked', 'placement' => array_replace($placement, ['container_instance' => 0])],
            ['sequence' => 1, 'type' => 'placement_locked', 'placement' => array_replace($placement, ['position' => [0, 0, 0]])],
        ] as $event) {
            self::assertSame('invalid_event', self::code(static fn() => PlanRevision::derive($root, $artifact, [$event])), json_encode($event));
        }
    }

    public static function testTheLastItemTypeCannotGoMissingAndEntriesMustBeObjects(): void
    {
        $request = self::request();
        $request['items'] = [$request['items'][0]];
        self::assertSame('event_conflict', self::code(static fn() => PlanRevision::applyEvents($request, [self::missing('cube', 4)])));
        $request['items'] = ['cube'];
        self::assertSame('invalid_revision', self::code(static fn() => PlanRevision::applyEvents($request, [self::missing('cube', 1)])));
        $request['items'] = 'cube';
        self::assertSame('invalid_revision', self::code(static fn() => PlanRevision::applyEvents($request, [self::missing('cube', 1)])));
    }

    public static function testTheAuditReportsEveryShapeOfDamage(): void
    {
        [$revisions, $artifacts] = self::chain();
        $noEvents = self::copy($revisions);
        $noEvents[0]->events = [(object) self::locked()];
        $noEvents[2]->events = [];
        $codes = array_map(static fn($issue): string => $issue->code . '@' . $issue->revision, PlanRevision::verifyChain($noEvents));
        self::assertContains('sequence_gap@0', $codes);
        self::assertContains('sequence_gap@2', $codes);

        $stale = self::copy($revisions);
        $stale[1]->events[1]->item_type = 'pallet';
        self::assertContains('request_mismatch', self::codes($stale));

        $notARevision = self::copy($revisions);
        $notARevision[1] = (object) ['format' => 'something-else'];
        self::assertSame(['invalid_revision'], self::codes($notARevision));

        $tampered = self::copy($revisions);
        $forged = PlanRevision::parse(json_encode($artifacts[1]));
        $forged->provenance->replay = (object) ['level' => 'not_guaranteed', 'because' => 'provenance.solver'];
        $tampered[1]->approved->artifact = PlanRevision::digest($forged);
        $issues = PlanRevision::verifyChain(array_slice($tampered, 0, 2), [null, $forged]);
        self::assertSame(['artifact_mismatch'], array_map(static fn($issue): string => $issue->code, $issues));
        $forged->provenance->request = (object) ['items' => []];
        $tampered[1]->approved->artifact = PlanRevision::digest($forged);
        $issues = PlanRevision::verifyChain(array_slice($tampered, 0, 2), [null, $forged]);
        self::assertSame(['artifact_mismatch'], array_map(static fn($issue): string => $issue->code, $issues));

        $orphan = self::copy($revisions);
        $orphan[1]->parent = null;
        $issue = PlanRevision::verifyChain(array_slice($orphan, 0, 2))[0];
        self::assertSame('parent_mismatch', $issue->code);
        self::assertTrue(str_starts_with($issue->detail, 'parent null, expected sha256:'), $issue->detail);
    }

    public static function testRefusalsQuoteValuesByCanonicalJson(): void
    {
        $root = PlanRevision::root(self::request());
        $artifact = self::artifact(self::request());
        $lock = self::locked();
        $placement = $lock['placement'];
        foreach ([
            'unknown event type "placement_moved"' => array_replace($lock, ['type' => 'placement_moved']),
            'unknown event type ["placement_locked"]' => array_replace($lock, ['type' => ['placement_locked']]),
            'unknown event type null' => ['sequence' => 1],
            'placement_locked does not carry ["5","note"]' => PlanRevision::parse('{"sequence":1,"type":"placement_locked","placement":{},"note":1,"5":2}'),
            'item_missing needs ["quantity"]' => ['sequence' => 1, 'type' => 'item_missing', 'item_type' => 'cube'],
            'event sequence true does not continue the chain at 1' => array_replace($lock, ['sequence' => true]),
            'event sequence "1" does not continue the chain at 1' => array_replace($lock, ['sequence' => '1']),
            'event sequence an out-of-range number does not continue the chain at 1' => array_replace($lock, ['sequence' => 2 ** 53]),
            'item_missing.quantity is a positive integer' => self::missing('cube', 2 ** 60),
            'a placement does not carry ["item_id"]' => array_replace($lock, ['placement' => array_replace($placement, ['item_id' => 'x'])]),
            'placement.container_instance counts from 1' => array_replace($lock, ['placement' => array_replace($placement, ['container_instance' => '1'])]),
            'placement.position is a point object' => array_replace($lock, ['placement' => array_replace($placement, ['position' => [0, 0, 0]])]),
            'placement.position does not carry ["w"]' => array_replace($lock, ['placement' => array_replace($placement, ['position' => ['w' => '5']])]),
            'placement.position.x is a measure' => array_replace($lock, ['placement' => array_replace($placement, ['position' => ['x' => true]])]),
            'placement.position.z is a measure' => array_replace($lock, ['placement' => array_replace($placement, ['position' => ['z' => null]])]),
            'placement.position.y is a measure' => array_replace($lock, ['placement' => array_replace($placement, ['position' => ['y' => ['1']]])]),
        ] as $message => $event) {
            self::assertSame(['invalid_event', $message], self::refusal(static fn() => PlanRevision::derive($root, $artifact, [$event])));
        }
        self::assertSame(['event_conflict', 'the request has no item "pallet"'], self::refusal(static fn() => PlanRevision::derive($root, $artifact, [self::missing('pallet', 1)])));
        self::assertSame(['event_conflict', 'the request has no container "pallet"'], self::refusal(static fn() => PlanRevision::applyEvents(self::request(), [
            ['sequence' => 1, 'type' => 'container_substituted', 'container_type' => 'pallet', 'replacement' => ['id' => 'x']],
        ])));
        self::assertSame(['invalid_event', 'a revision records at least one event'], self::refusal(static fn() => PlanRevision::derive($root, $artifact, 'x')));
    }

    public static function testIntegralFloatsAreTheIntegersTheySpell(): void
    {
        $artifact = self::artifact(self::request());
        $root = PlanRevision::parse(str_replace('"revision":0', '"revision":0.0', PlanRevision::canonicalJson(PlanRevision::root(self::request()))));
        self::assertSame(0.0, $root->revision);
        $placement = array_replace(self::locked()['placement'], ['container_instance' => 1.0]);
        $first = PlanRevision::derive($root, $artifact, [['sequence' => 1.0, 'type' => 'placement_locked', 'placement' => $placement], self::missing('cube', 1, 2)]);
        self::assertSame(1, $first->revision);
        self::assertSame(1, PlanRevision::applyEvents($first->request, [['sequence' => 3, 'type' => 'item_missing', 'item_type' => 'cube', 'quantity' => 2.0]])->items[0]->quantity);

        $chain = self::copy(self::chain()[0]);
        $chain[1]->revision = 1.0;
        $chain[1]->events[0]->sequence = 1.0;
        self::assertSame([], PlanRevision::verifyChain($chain));
        $chain[2]->revision = 5.0;
        $chain[2]->events[0]->sequence = 7.0;
        self::assertSame([['revision_number', 'revision 5 at position 2'], ['sequence_gap', 'event 7 where 3 was next']],
            self::issues(PlanRevision::verifyChain($chain)));
    }

    public static function testAParentWhoseEventsCarryNoIntegerSequenceIsRefused(): void
    {
        [$revisions, $artifacts] = self::chain();
        $artifact = self::artifact(json_decode(json_encode($revisions[1]->request), true));
        foreach (['1', true, null, 1.5, 'missing', 'not-an-object'] as $sequence) {
            $parent = self::copy([$revisions[1]])[0];
            if ($sequence === 'missing') {
                unset($parent->events[0]->sequence);
            } elseif ($sequence === 'not-an-object') {
                $parent->events = [5];
            } else {
                $parent->events[0]->sequence = $sequence;
            }
            self::assertSame(['invalid_revision', "a revision's events each carry an integer sequence"],
                self::refusal(static fn() => PlanRevision::derive($parent, $artifact, [self::locked('placement_verified', 3)])));
            $chain = self::copy($revisions);
            $chain[1] = $parent;
            self::assertSame([['invalid_revision', "a revision's events each carry an integer sequence"]], self::issues(PlanRevision::verifyChain($chain, $artifacts)));
        }
    }

    public static function testARequestWhoseFixedPlacementsOrQuantitiesAreMalformedIsRefused(): void
    {
        $nulled = array_replace(self::request(), ['fixed_placements' => null]);
        self::assertCount(1, PlanRevision::applyEvents($nulled, [self::locked()])->fixed_placements);
        self::assertSame(3, PlanRevision::applyEvents($nulled, [self::missing('cube', 1)])->items[0]->quantity);
        foreach ([(object) ['k' => 1], [5], 'x'] as $fixed) {
            $request = array_replace(self::request(), ['fixed_placements' => $fixed]);
            foreach ([self::locked(), self::missing('cube', 1), ['sequence' => 1, 'type' => 'container_substituted', 'container_type' => 'box', 'replacement' => ['id' => 'b']]] as $event) {
                self::assertSame(['invalid_revision', 'request.fixed_placements is a list of objects'], self::refusal(static fn() => PlanRevision::applyEvents($request, [$event])));
            }
        }
        foreach (['3', 2.5, null, 2 ** 53] as $quantity) {
            $request = self::request();
            $request['items'][1]['quantity'] = $quantity;
            self::assertSame(['invalid_revision', 'request.items[1].quantity is an integer'], self::refusal(static fn() => PlanRevision::applyEvents($request, [self::missing('slab', 1)])));
        }
        $request = self::request();
        $request['items'][0]['quantity'] = 3.0;
        self::assertSame(2, PlanRevision::applyEvents($request, [self::missing('cube', 1)])->items[0]->quantity);
        $request = self::request();
        unset($request['items'][1]['quantity']);
        self::assertSame(['cube'], array_map(static fn(stdClass $item): string => $item->id, PlanRevision::applyEvents($request, [self::missing('slab', 1)])->items));
    }

    public static function testAConflictNamesItsEventByInteger(): void
    {
        $fixed = PlanRevision::applyEvents(self::request(), [self::locked()]);
        self::assertSame(['event_conflict', 'event 2: 1 cube are fixed, 0 would remain'], self::refusal(static fn() => PlanRevision::applyEvents($fixed, [array_replace(self::missing('cube', 4), ['sequence' => 2.0])])));
    }

    public static function testApplyRefusesWhatIsNotARequestEventListOrIntegerSequence(): void
    {
        self::assertSame(['invalid_revision', 'a request is a JSON object'], self::refusal(static fn() => PlanRevision::applyEvents([1], [self::locked()])));
        self::assertSame(['invalid_event', 'events are a JSON array'], self::refusal(static fn() => PlanRevision::applyEvents(self::request(), (object) ['0' => self::locked()])));
        foreach (['event sequence null is not an integer' => null, 'event sequence "1" is not an integer' => '1',
            'event sequence true is not an integer' => true, 'event sequence 1.5 is not an integer' => 1.5] as $message => $sequence) {
            self::assertSame(['invalid_event', $message], self::refusal(static fn() => PlanRevision::applyEvents(self::request(), [array_replace(self::locked(), ['sequence' => $sequence])])));
        }
        $unsequenced = self::locked();
        unset($unsequenced['sequence']);
        self::assertSame(['invalid_event', 'event sequence null is not an integer'], self::refusal(static fn() => PlanRevision::applyEvents(self::request(), [$unsequenced])));
    }

    public static function testAChainOrArtifactListThatIsNotAnArrayIsRefused(): void
    {
        [$revisions, $artifacts] = self::chain();
        foreach ([(object) ['0' => $revisions[0]], ['a' => $revisions[0]], 'x', null] as $chain) {
            self::assertSame(['invalid_revision', 'a chain is a JSON array'], self::refusal(static fn() => PlanRevision::verifyChain($chain)));
        }
        foreach ([(object) ['1' => $artifacts[1]], 'x', 5] as $list) {
            self::assertSame(['invalid_artifact', 'artifacts is a JSON array'], self::refusal(static fn() => PlanRevision::verifyChain($revisions, $list)));
        }
        $mismatch = ['artifact_mismatch', "the artifact's digest is not the one this revision approved"];
        foreach (['x', 5, [1, 2], ['a' => 1e300]] as $artifact) {
            self::assertSame([$mismatch], self::issues(PlanRevision::verifyChain($revisions, [null, $artifact, $artifacts[2]])));
        }
    }

    public static function testAParentThatIsNotADigestIsQuotedAsJson(): void
    {
        $chain = self::copy(self::chain()[0]);
        $chain[0]->parent = 5;
        $chain[1]->parent = 'forged';
        $details = array_map(static fn(array $issue): string => $issue[1], self::issues(PlanRevision::verifyChain(array_slice($chain, 0, 2))));
        self::assertSame('parent 5, expected null', $details[0]);
        self::assertTrue(str_starts_with($details[1], 'parent forged, expected sha256:'), $details[1]);
    }

    /** @return array{0:list<stdClass>,1:list<?array<string,mixed>>} */
    private static function chain(): array
    {
        $root = PlanRevision::root(self::request());
        $firstArtifact = self::artifact(self::request());
        $first = PlanRevision::derive($root, $firstArtifact, [self::locked(), self::missing('cube', 1, 2)]);
        $firstRequest = json_decode(json_encode($first->request), true);
        $secondArtifact = self::artifact($firstRequest);
        $second = PlanRevision::derive($first, $secondArtifact, [self::locked('placement_verified', 3)]);
        return [[$root, $first, $second], [null, $firstArtifact, $secondArtifact]];
    }

    /** @return array<string,mixed> */
    private static function artifact(array|stdClass $request): array
    {
        $request = json_decode(json_encode($request), true);
        return OperationalArtifact::build($request, ArrayCodec::pack($request));
    }

    /** @return array<string,mixed> */
    private static function request(): array
    {
        return [
            'items' => [
                ['id' => 'cube', 'quantity' => 4, 'weight' => '1000', 'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100']],
                ['id' => 'slab', 'quantity' => 1, 'dimensions' => ['length' => '200', 'width' => '100', 'height' => '50']],
            ],
            'containers' => [
                ['id' => 'box', 'quantity' => 3, 'inner_dimensions' => ['length' => '200', 'width' => '100', 'height' => '200']],
                ['id' => 'crate', 'inner_dimensions' => ['length' => '300', 'width' => '200', 'height' => '200']],
            ],
            'configuration' => ['solver_profile' => 'balanced', 'minimum_support_ratio' => 1.0],
        ];
    }

    /** @return array<string,mixed> */
    private static function locked(string $type = 'placement_locked', int $sequence = 1): array
    {
        return ['sequence' => $sequence, 'type' => $type, 'placement' => [
            'item_type' => 'cube', 'container_type' => 'box', 'position' => ['x' => '0', 'z' => '0'], 'orientation' => 'LWH',
        ]];
    }

    /** @return array<string,mixed> */
    private static function missing(string $type, int $quantity, int $sequence = 1): array
    {
        return ['sequence' => $sequence, 'type' => 'item_missing', 'item_type' => $type, 'quantity' => $quantity];
    }

    private static function code(callable $call): ?string
    {
        try {
            $call();
        } catch (PlanRevisionException $error) {
            return $error->errorCode();
        }
        return null;
    }

    /** @return array{0:string,1:string}|null */
    private static function refusal(callable $call): ?array
    {
        try {
            $call();
        } catch (PlanRevisionException $error) {
            return [$error->errorCode(), $error->getMessage()];
        }
        return null;
    }

    /** @param list<\Packvium\Revisions\RevisionIssue> $issues @return list<array{0:string,1:string}> */
    private static function issues(array $issues): array
    {
        return array_map(static fn($issue): array => [$issue->code, $issue->detail], $issues);
    }

    /** @param list<stdClass> $revisions @return list<string> */
    private static function codes(array $revisions): array
    {
        return array_map(static fn($issue): string => $issue->code, PlanRevision::verifyChain($revisions));
    }

    /** @param list<stdClass> $revisions @return list<stdClass> */
    private static function copy(array $revisions): array
    {
        return array_map(static fn(stdClass $revision): stdClass => PlanRevision::parse(json_encode($revision)), $revisions);
    }
}
