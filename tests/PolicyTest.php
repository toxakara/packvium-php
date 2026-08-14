<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Explain\Explain;
use Packvium\Policy\PolicyException;
use Packvium\Policy\PolicyRuleSet;
use Packvium\Policy\ShipmentContext;
use Packvium\Serialization\ArrayCodec;

/**
 * Versioned eligibility rules compiled into the constraint pipeline.
 *
 * Every assertion here is about a *packing*, not about a parsed object: a rule that is
 * resolved correctly and then never consulted is indistinguishable, from the outside,
 * from one that was ignored. The Python suite runs the same shape of test in
 * tests/test_policy.py, and the wire-contract integration corpus holds every engine to
 * one committed golden result.
 */
final class PolicyTest extends TestCase
{
    private const AS_OF = 1704067200000;

    /** @return array<string,mixed> */
    private static function request(): array
    {
        return [
            'units' => ['length' => 'mm'],
            'policy' => [
                'as_of' => self::AS_OF,
                'shipment' => ['facility' => 'SEA1', 'carrier' => 'ups'],
                'rules' => [[
                    'id' => 'hazmat-food-segregation',
                    'version' => 1,
                    'effective_at' => self::AS_OF,
                    'priority' => 100,
                    'separate_tags' => ['tag' => 'hazmat', 'from_tag' => 'food'],
                ]],
            ],
            'items' => [
                ['id' => 'drum', 'quantity' => 1, 'tags' => ['hazmat'],
                 'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100']],
                ['id' => 'carton', 'quantity' => 1, 'tags' => ['food'],
                 'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100']],
            ],
            'containers' => [
                ['id' => 'pallet', 'quantity' => 4,
                 'inner_dimensions' => ['length' => '300', 'width' => '300', 'height' => '300']],
            ],
        ];
    }

    /** @param array<string,mixed> $request */
    private static function containersUsed(array $request): int
    {
        return ArrayCodec::pack($request)['summary']['container_count'];
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private static function withoutPolicy(array $request): array
    {
        unset($request['policy']);
        return $request;
    }

    public static function testASegregationRuleOpensAContainerTheGeometryDidNotNeed(): void
    {
        // Both items fit in one pallet by geometry and weight alone, so a second
        // container is the rule's doing and nothing else's.
        self::assertSame(1, self::containersUsed(self::withoutPolicy(self::request())));
        self::assertSame(2, self::containersUsed(self::request()));
    }

    public static function testAContainerTagRuleLeavesAnItemBehindRatherThanRoutingItWrongly(): void
    {
        $request = self::request();
        $request['policy']['rules'] = [[
            'id' => 'cold-chain', 'version' => 1, 'effective_at' => self::AS_OF, 'priority' => 10,
            'require_container_tag' => ['item_tag' => 'food', 'container_tag' => 'reefer'],
        ]];
        $result = ArrayCodec::pack($request);
        self::assertSame(['carton'], array_map(static fn(array $u): string => $u['item_type'], $result['unpacked_items']));

        $request['containers'][0]['tags'] = ['reefer'];
        self::assertSame(0, ArrayCodec::pack($request)['summary']['unpacked_item_count']);
    }

    public static function testATagCapSplitsAContainerTheCapWouldOtherwiseOverfill(): void
    {
        $request = self::request();
        $request['items'] = [[
            'id' => 'cell', 'quantity' => 3, 'tags' => ['lithium'],
            'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100'],
        ]];
        $request['policy']['rules'] = [[
            'id' => 'lithium-cap', 'version' => 1, 'effective_at' => self::AS_OF, 'priority' => 10,
            'limit_tag_per_container' => ['tag' => 'lithium', 'max' => 2],
        ]];
        self::assertSame(1, self::containersUsed(self::withoutPolicy($request)));
        self::assertSame(2, self::containersUsed($request));
    }

    public static function testARuleThatIsNotYetEffectiveDoesNotParticipate(): void
    {
        $request = self::request();
        $request['policy']['as_of'] = self::AS_OF - 1;
        self::assertSame(1, self::containersUsed($request));
    }

    public static function testARuleScopedToAnotherShipmentDoesNotParticipate(): void
    {
        foreach ([['facility', 'PDX9'], ['carrier', 'fedex']] as [$fact, $value]) {
            $request = self::request();
            $request['policy']['rules'][0]['applies_to'] = [$fact => $value];
            self::assertSame(1, self::containersUsed($request), $fact);
            $request['policy']['rules'][0]['applies_to'] = [$fact => $request['policy']['shipment'][$fact]];
            self::assertSame(2, self::containersUsed($request), $fact);
        }
    }

    public static function testARuleScopedToAFactTheShipmentNeverDeclaredDoesNotParticipate(): void
    {
        // An undeclared fact is not a wildcard. Treating it as one would let a rule
        // written for one customer silently apply to a shipment that never named one.
        $request = self::request();
        $request['policy']['rules'][0]['applies_to'] = ['customer' => 'acme'];
        self::assertSame(1, self::containersUsed($request));
    }

    public static function testTheHighestEffectiveVersionOfOneIdWins(): void
    {
        // Append-only per id: the later version replaces the earlier one rather than
        // both being enforced, which is what makes a published rule set replayable.
        $request = self::request();
        $superseded = $request['policy']['rules'][0];
        $superseded['version'] = 2;
        $superseded['separate_tags'] = ['tag' => 'hazmat', 'from_tag' => 'nothing-here'];
        $request['policy']['rules'][] = $superseded;
        self::assertSame(1, self::containersUsed($request));

        $request['policy']['rules'][1]['effective_at'] = self::AS_OF + 1;
        self::assertSame(2, self::containersUsed($request));
    }

    public static function testResolutionOrdersRulesByPriorityThenIdNeverByDeclarationOrder(): void
    {
        $rules = [
            ['id' => 'b', 'version' => 1, 'effective_at' => 0, 'priority' => 5,
             'separate_tags' => ['tag' => 'x', 'from_tag' => 'y']],
            ['id' => 'a', 'version' => 1, 'effective_at' => 0, 'priority' => 5,
             'separate_tags' => ['tag' => 'x', 'from_tag' => 'y']],
            ['id' => 'c', 'version' => 1, 'effective_at' => 0, 'priority' => 9,
             'separate_tags' => ['tag' => 'x', 'from_tag' => 'y']],
        ];
        $ids = static fn(array $declared): array => array_map(
            static fn($rule): string => $rule->id,
            PolicyRuleSet::fromArray(['as_of' => 0, 'rules' => $declared])->rules,
        );
        self::assertSame(['c', 'a', 'b'], $ids($rules));
        self::assertSame(['c', 'a', 'b'], $ids(array_reverse($rules)));
    }

    public static function testAnUndeclaredShipmentFactIsAbsentRatherThanMatchingAnything(): void
    {
        self::assertTrue((new ShipmentContext(facility: 'SEA1'))->satisfiedBy(new ShipmentContext(facility: 'SEA1')));
        self::assertFalse((new ShipmentContext(facility: 'SEA1'))->satisfiedBy(new ShipmentContext()));
        self::assertTrue((new ShipmentContext())->satisfiedBy(new ShipmentContext(facility: 'SEA1')));
    }

    public static function testAMalformedRuleFailsAdmissionRatherThanBeingDropped(): void
    {
        // A rule quietly dropped for being malformed would let a request pack in a way
        // its own policy forbids -- the failure the whole contract exists to prevent.
        $cases = [
            [['id' => '', 'version' => 1, 'effective_at' => 0, 'priority' => 0,
              'separate_tags' => ['tag' => 'a', 'from_tag' => 'b']], 'id must be a non-empty string'],
            [['id' => 'r', 'version' => 0, 'effective_at' => 0, 'priority' => 0,
              'separate_tags' => ['tag' => 'a', 'from_tag' => 'b']], 'version must be an integer >= 1'],
            [['id' => 'r', 'version' => true, 'effective_at' => 0, 'priority' => 0,
              'separate_tags' => ['tag' => 'a', 'from_tag' => 'b']], 'version must be an integer >= 1'],
            [['id' => 'r', 'version' => 1, 'effective_at' => -1, 'priority' => 0,
              'separate_tags' => ['tag' => 'a', 'from_tag' => 'b']], 'effective_at must be an integer >= 0'],
            [['id' => 'r', 'version' => 1, 'effective_at' => 0, 'priority' => 0], 'exactly one rule form'],
            [['id' => 'r', 'version' => 1, 'effective_at' => 0, 'priority' => 0,
              'separate_tags' => ['tag' => 'a', 'from_tag' => 'b'],
              'limit_tag_per_container' => ['tag' => 'a', 'max' => 1]], 'exactly one rule form'],
            [['id' => 'r', 'version' => 1, 'effective_at' => 0, 'priority' => 0,
              'separate_tags' => ['tag' => 'a']], 'is missing from_tag'],
            [['id' => 'r', 'version' => 1, 'effective_at' => 0, 'priority' => 0,
              'separate_tags' => ['tag' => 'a', 'from_tag' => 'b', 'extra' => 1]], 'unknown keys: extra'],
            [['id' => 'r', 'version' => 1, 'effective_at' => 0, 'priority' => 0,
              'limit_tag_per_container' => ['tag' => 'a', 'max' => -1]], 'max must be an integer >= 0'],
            [['id' => 'r', 'version' => 1, 'effective_at' => 0, 'priority' => 0,
              'applies_to' => ['region' => 'eu'],
              'separate_tags' => ['tag' => 'a', 'from_tag' => 'b']], 'unknown shipment facts: region'],
            // A tag is matched against item and container tags by identity, so a number
            // or an empty string would silently never match rather than fail loudly.
            [['id' => 'r', 'version' => 1, 'effective_at' => 0, 'priority' => 0,
              'separate_tags' => ['tag' => 5, 'from_tag' => 'b']], 'tag must be a non-empty string'],
            [['id' => 'r', 'version' => 1, 'effective_at' => 0, 'priority' => 0,
              'applies_to' => ['facility' => ''],
              'separate_tags' => ['tag' => 'a', 'from_tag' => 'b']], 'facility must be a non-empty string'],
        ];
        foreach ($cases as [$rule, $expected]) {
            $message = null;
            try {
                PolicyRuleSet::fromArray(['as_of' => 0, 'rules' => [$rule]]);
            } catch (PolicyException $error) {
                $message = $error->getMessage();
            }
            self::assertNotNull($message, $expected);
            self::assertTrue(str_contains($message, $expected), "{$expected} missing from {$message}");
        }
    }

    public static function testRulesWithoutAnAsOfFailAdmission(): void
    {
        $rule = ['id' => 'r', 'version' => 1, 'effective_at' => 0, 'priority' => 0,
                 'separate_tags' => ['tag' => 'a', 'from_tag' => 'b']];
        $message = null;
        try {
            PolicyRuleSet::fromArray(['rules' => [$rule]]);
        } catch (PolicyException $error) {
            $message = $error->getMessage();
        }
        self::assertNotNull($message);
        self::assertTrue(str_contains($message, 'as_of is required'), $message);

        // An empty rule set needs no instant, because nothing can be dated.
        self::assertSame([], PolicyRuleSet::fromArray(['rules' => []])->rules);
        self::assertSame([], PolicyRuleSet::fromArray(null)->rules);
    }

    public static function testAnUnknownPolicyKeyFailsAdmission(): void
    {
        $message = null;
        try {
            PolicyRuleSet::fromArray(['as_of' => 0, 'rules' => [], 'effect' => 'deny']);
        } catch (PolicyException $error) {
            $message = $error->getMessage();
        }
        self::assertNotNull($message);
        self::assertTrue(str_contains($message, 'unknown keys: effect'), $message);
    }

    // ---------------------------------------------------------------- citation

    public static function testARejectionNamesTheRuleAndVersionThatCausedIt(): void
    {
        $request = self::request();
        $request['policy']['rules'] = [[
            'id' => 'cold-chain', 'version' => 7, 'effective_at' => self::AS_OF, 'priority' => 10,
            'require_container_tag' => ['item_tag' => 'food', 'container_tag' => 'reefer'],
        ]];
        $result = ArrayCodec::pack($request);
        $item = $result['unpacked_items'][0];
        self::assertSame('carton', $item['item_type']);
        self::assertSame('policy_rule', $item['reason']);
        // Version, not just id: replaying a past decision needs to know which text was
        // in force, and two versions of one rule can forbid different things.
        self::assertSame(
            ["cold-chain@7: requires a container tagged 'reefer', which none of the containers offered carries"],
            $item['details'],
        );
        // Proven rather than observed: no search outcome can make the item placeable.
        self::assertSame('proven', $item['proof']['level']);
        self::assertSame('policy_rule', $item['proof']['observations'][0]['code']);
        self::assertTrue(str_contains(Explain::reason('policy_rule'), 'policy rule'));
    }

    public static function testACapThatLeavesAnItemBehindIsObservedNotProven(): void
    {
        // A per-container cap depends on what else was packed, so an item it leaves
        // behind was left behind by the search. Claiming `proven` would assert more than
        // the engine knows, so the generic observed reason stands and no rule is cited.
        $request = self::request();
        $request['items'] = [[
            'id' => 'cell', 'quantity' => 3, 'tags' => ['lithium'],
            'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100'],
        ]];
        $request['containers'] = [[
            'id' => 'pallet', 'quantity' => 1,
            'inner_dimensions' => ['length' => '300', 'width' => '300', 'height' => '300'],
        ]];
        $request['policy']['rules'] = [[
            'id' => 'lithium-cap', 'version' => 1, 'effective_at' => self::AS_OF, 'priority' => 10,
            'limit_tag_per_container' => ['tag' => 'lithium', 'max' => 2],
        ]];
        $result = ArrayCodec::pack($request);
        self::assertSame(1, $result['summary']['unpacked_item_count']);
        self::assertNotSame('policy_rule', $result['unpacked_items'][0]['reason']);
    }

    public static function testGeometryOutranksPolicyInTheReportedReason(): void
    {
        // An item too big for every container is impossible whatever a policy says, and
        // reporting the policy first would send a caller to fix the wrong thing.
        $request = self::request();
        $request['items'] = [[
            'id' => 'slab', 'quantity' => 1, 'tags' => ['food'],
            'dimensions' => ['length' => '9000', 'width' => '9000', 'height' => '9000'],
        ]];
        $request['policy']['rules'] = [[
            'id' => 'cold-chain', 'version' => 1, 'effective_at' => self::AS_OF, 'priority' => 10,
            'require_container_tag' => ['item_tag' => 'food', 'container_tag' => 'reefer'],
        ]];
        $result = ArrayCodec::pack($request);
        self::assertSame(
            ['no_compatible_container_dimensions'],
            array_map(static fn(array $u): string => $u['reason'], $result['unpacked_items']),
        );
    }
}
