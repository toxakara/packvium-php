<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Commerce\Catalog\CatalogRegistry;
use Packvium\Commerce\Catalog\CartonMaster;
use Packvium\Commerce\Catalog\ExclusionRule;
use Packvium\Commerce\Catalog\ExclusionScope;
use Packvium\Commerce\Catalog\ItemMaster;
use Packvium\Commerce\Catalog\PalletMaster;
use Packvium\Commerce\Catalog\Snapshot;
use Packvium\Commerce\Catalog\Version;
use Packvium\Commerce\Catalog\VersionNotFoundException;
use Packvium\Commerce\CommerceInputException;
use Packvium\Commerce\Policy\PolicyAction;
use Packvium\Commerce\Policy\PolicyOperator;
use Packvium\Commerce\Policy\PolicyRegistry;
use Packvium\Commerce\Policy\PolicyScope;
use Packvium\Commerce\Policy\Predicate;
use Packvium\Commerce\Policy\UnsupportedPredicateException;
use Packvium\Commerce\Rating\AccessorialCharge;
use Packvium\Commerce\Rating\CarrierRegistry;
use Packvium\Commerce\Rating\RatingRequest;
use Packvium\Commerce\Rating\Tariff;
use Packvium\Commerce\Rating\TariffNotFoundException;

use function Packvium\Commerce\catalogVersionInfo;
use function Packvium\Commerce\quote;

/**
 * The guards `CommerceTest` leaves unreached.
 *
 * `CommerceTest` replays the shared cross-language fixtures, which by construction only
 * contain inputs the reference implementation could answer or refuse in the same way.
 * The models underneath are public: a caller can build a `Tariff`, a `Snapshot` or a
 * `Predicate` by hand and skip the document parser entirely. Every guard below is on
 * that path, and each was reachable only from outside the fixture corpus.
 */
final class CommerceGuardsTest extends TestCase
{
    // ------------------------------------------------------------------ rating models

    public static function testAnAccessorialMustSetExactlyOneKindOfCharge(): void
    {
        foreach ([[null, null], [100, 50]] as [$flat, $permille]) {
            self::assertThrows(
                \InvalidArgumentException::class,
                static fn() => new AccessorialCharge('lift', $flat, $permille),
                'neither charge and both charges are the same mistake',
            );
        }
    }

    public static function testATariffRefusesAnAccessorialFiledUnderTheWrongKey(): void
    {
        self::assertThrows(
            \InvalidArgumentException::class,
            static fn() => self::tariff(['residential' => new AccessorialCharge('liftgate', 100)]),
            'a map key that disagrees with its own id would silently drop the accessorial',
        );
    }

    public static function testAnUnknownTariffVersionIsNamedInTheError(): void
    {
        $registry = new CarrierRegistry();
        $registry->publish('acme', 'ground', 0, 5000, ['zone-a' => 450], 900, 120);

        self::assertThrows(
            TariffNotFoundException::class,
            static fn() => $registry->tariff('acme', 'ground', 99),
        );
    }

    /**
     * Both convenience paths on the registry rate through a resolution step. They are
     * the entry points a caller reaches without touching a `Tariff` object at all.
     */
    public static function testTheRegistryRatesByEffectiveDateAndByPinnedVersion(): void
    {
        $registry = new CarrierRegistry();
        $registry->publish('acme', 'ground', 0, 5000, ['zone-a' => 450], 900, 120);
        $request = new RatingRequest('zone-a', 1000, 1);

        $byDate = $registry->rate('acme', 'ground', $request, 500);
        $byVersion = $registry->rateWithVersion('acme', 'ground', 1, $request);

        self::assertSame($byDate->totalMinor, $byVersion->totalMinor, 'one tariff, one price');
        self::assertGreaterThan(0, $byDate->totalMinor);
    }

    // ----------------------------------------------------------------- catalog models

    public static function testACatalogRegistryReportsItsPublishedVersions(): void
    {
        $registry = new CatalogRegistry('dc-1');
        self::assertSame([], $registry->versions());

        $registry->publish(new Snapshot(), 0, 0);
        $registry->publish(new Snapshot(), 10, 10);

        $numbers = array_map(static fn(Version $version): int => $version->number, $registry->versions());
        self::assertSame([1, 2], $numbers, 'numbering is 1-based position in the history');
    }

    public static function testAnEmptyCatalogHasNoVersionToResolve(): void
    {
        self::assertThrows(
            VersionNotFoundException::class,
            static fn() => (new CatalogRegistry('dc-1'))->resolve(1),
        );
    }

    public static function testARollbackMustNameAPositiveVersionNumber(): void
    {
        self::assertThrows(
            \InvalidArgumentException::class,
            static fn() => new Version(2, new Snapshot(), 0, 0, 0),
            'version 0 does not exist, so nothing can have been rolled back from it',
        );
    }

    public static function testAnExclusionRuleNeedsBothEndsOfTheRelation(): void
    {
        foreach ([['', 'c'], ['s', '']] as [$subject, $excluded]) {
            self::assertThrows(
                \InvalidArgumentException::class,
                static fn() => new ExclusionRule('x', ExclusionScope::ITEM_CARTON, $subject, $excluded),
            );
        }
    }

    /** Uniqueness is checked per kind, so a single shared check would pass all five. */
    public static function testDuplicateIdsAreRefusedWithinEachMasterDataKind(): void
    {
        $item = new ItemMaster('d', [1, 1, 1], 1);
        $carton = new CartonMaster('d', [1, 1, 1], 1);
        $pallet = new PalletMaster('d', [1, 1], 1);
        $exclusion = new ExclusionRule('d', ExclusionScope::ITEM_CARTON, 's', 'c');

        foreach ([
            static fn() => new Snapshot(items: [$item, $item]),
            static fn() => new Snapshot(cartons: [$carton, $carton]),
            static fn() => new Snapshot(pallets: [$pallet, $pallet]),
            static fn() => new Snapshot(exclusions: [$exclusion, $exclusion]),
        ] as $build) {
            self::assertThrows(\InvalidArgumentException::class, $build);
        }
    }

    // ------------------------------------------------------------------ policy models

    public static function testABinaryOperatorWithoutAValueIsRefusedAtConstruction(): void
    {
        self::assertThrows(
            \InvalidArgumentException::class,
            static fn() => new Predicate(PolicyScope::HAZMAT, 'f', PolicyOperator::EQUALS),
            'equals against nothing has no defined answer',
        );
    }

    public static function testTheNegatedOperatorsAreEvaluatedOnTheirOwnArms(): void
    {
        $notIn = new Predicate(PolicyScope::HAZMAT, 'f', PolicyOperator::NOT_IN, ['y', 'z']);

        self::assertTrue($notIn->matches(['f' => 'x']));
        self::assertFalse($notIn->matches(['f' => 'y']));
        self::assertFalse($notIn->matches([]), 'a missing field matches nothing, negation included');
    }

    /**
     * Value equality has one deliberate asymmetry with PHP's own `===`: a boolean equals
     * the integer it stands for. Every other cross-type pair must stay unequal, and two
     * nulls must stay equal.
     */
    public static function testValueEqualityCoversEveryScalarPairing(): void
    {
        $equals = static function (mixed $value, mixed $actual): bool {
            return (new Predicate(PolicyScope::HAZMAT, 'f', PolicyOperator::EQUALS, $value))
                ->matches(['f' => $actual]);
        };

        self::assertTrue($equals(true, true));
        self::assertFalse($equals(true, false));
        self::assertTrue($equals(1, true), 'a boolean equals the integer it stands for');
        self::assertTrue($equals(true, 1), 'and the comparison is symmetric');
        self::assertFalse($equals(2, true));
        self::assertTrue($equals([1, 'a'], [1, 'a']));
        self::assertFalse($equals([1], [1, 2]), 'length is compared before contents');
        self::assertFalse($equals('1', 1), 'a numeric string is not the number');
    }

    /**
     * Floats are outside the numeric contract: the document parser refuses a non-integer
     * number, so this comparator only ever sees one if a caller builds a `Predicate` by
     * hand. This port then answers "not equal" for two identical floats, where the
     * Python and Rust models answer "equal" -- pinned here as a known model-layer
     * difference, not as behaviour any documented input can reach.
     */
    public static function testFloatsAreOutsideTheComparableTypes(): void
    {
        $predicate = new Predicate(PolicyScope::HAZMAT, 'f', PolicyOperator::EQUALS, 1.5);

        self::assertFalse($predicate->matches(['f' => 1.5]));

        self::assertThrows(
            CommerceInputException::class,
            static fn() => quote(self::document(), [
                'carrier_id' => 'acme', 'service_id' => 'ground', 'tariff_version' => 1,
                'zone' => 'zone-a', 'actual_weight_g' => 1.5, 'volume_mm3' => 1,
            ]),
            'the parser is what keeps a float away from the comparator',
        );
    }

    public static function testTwoNullsAreEqualAndNullEqualsNothingElse(): void
    {
        // `value: null` is refused at construction, so the only way to compare against
        // null is to put one in the context and pin the other side to a list.
        $inNulls = new Predicate(PolicyScope::HAZMAT, 'f', PolicyOperator::IN, [null]);

        self::assertTrue($inNulls->matches(['f' => null]), 'null is a value, not an absence');
        self::assertFalse($inNulls->matches(['f' => 0]), 'null is not zero');
        self::assertFalse($inNulls->matches(['f' => '']), 'null is not the empty string');
    }

    public static function testMembershipIsDefinedOnlyOverListsAndStrings(): void
    {
        $inString = new Predicate(PolicyScope::HAZMAT, 'f', PolicyOperator::IN, 'haystack');
        self::assertTrue($inString->matches(['f' => 'stack']), 'a string haystack is a substring test');
        self::assertFalse($inString->matches(['f' => 7]), 'a non-string needle cannot be a substring');

        $inNumber = new Predicate(PolicyScope::HAZMAT, 'f', PolicyOperator::IN, 7);
        self::assertThrows(
            UnsupportedPredicateException::class,
            static fn() => $inNumber->matches(['f' => 7]),
            'a number is not a container, and guessing would change a decision',
        );
    }

    public static function testAPinnedPolicySnapshotIsOrderedByRuleIdNotByPinOrder(): void
    {
        $registry = new PolicyRegistry();
        foreach (['b', 'a', 'c'] as $id) {
            $registry->publish($id, PolicyScope::HAZMAT, PolicyAction::REJECT, [self::predicate()], 1, 0);
        }

        $resolved = $registry->resolveVersions([['c', 1], ['a', 1], ['b', 1]]);

        self::assertSame(['a', 'b', 'c'], array_map(static fn($rule): string => $rule->ruleId, $resolved));
    }

    public static function testAPinnedPolicySnapshotCannotNameTheSameRuleTwice(): void
    {
        $registry = new PolicyRegistry();
        $registry->publish('a', PolicyScope::HAZMAT, PolicyAction::REJECT, [self::predicate()], 1, 0);

        self::assertThrows(
            \InvalidArgumentException::class,
            static fn() => $registry->resolveVersions([['a', 1], ['a', 1]]),
        );
    }

    // ---------------------------------------------------------------- document parser

    /** Every override kind routes to its own master-data parser, and no other. */
    public static function testAFacilityOverrideAcceptsEachMasterDataKind(): void
    {
        foreach ([
            ['item', ['id' => 'e', 'dimensions_mm' => [1, 1, 1], 'weight_g' => 1]],
            ['carton', ['id' => 'e', 'inner_dimensions_mm' => [1, 1, 1], 'max_payload_g' => 1]],
            ['pallet', ['id' => 'e', 'deck_dimensions_mm' => [1, 1], 'max_payload_g' => 1]],
        ] as [$kind, $payload]) {
            $result = catalogVersionInfo(
                self::catalogWithOverride($kind, $payload),
                ['catalog_id' => 'dc-1', 'resolved_at' => 0, 'version' => 1],
            );

            self::assertSame('ok', $result['status'], "the '{$kind}' override kind must be accepted");
        }
    }

    public static function testAnUnknownFacilityOverrideKindIsRefused(): void
    {
        self::assertThrows(
            CommerceInputException::class,
            static fn() => catalogVersionInfo(
                self::catalogWithOverride('crate', ['id' => 'e']),
                ['catalog_id' => 'dc-1', 'resolved_at' => 0, 'version' => 1],
            ),
        );
    }

    public static function testAMissingRequiredKeyIsNamedAndSorted(): void
    {
        try {
            quote(['tariffs' => [['carrier_id' => 'acme']]], [
                'carrier_id' => 'acme', 'service_id' => 'ground', 'tariff_version' => 1,
                'zone' => 'z', 'actual_weight_g' => 1, 'volume_mm3' => 1,
            ]);
            self::assertTrue(false, 'a tariff history without versions must be refused');
        } catch (CommerceInputException $error) {
            self::assertTrue(
                str_contains($error->getMessage(), 'missing required key(s)'),
                "expected a named missing key, got: {$error->getMessage()}",
            );
        }
    }

    /**
     * An unknown carrier is a structured rejection on both pin forms. The fixtures cover
     * the `tariff_version` form; the effective-dated form takes a separate branch.
     */
    public static function testAnUnknownCarrierIsRejectedOnTheEffectiveDatedPinToo(): void
    {
        $result = quote(self::document(), [
            'carrier_id' => 'nobody', 'service_id' => 'ground', 'as_of' => 0,
            'zone' => 'zone-a', 'actual_weight_g' => 1000, 'volume_mm3' => 1,
        ]);

        self::assertSame('rejected', $result['status'], 'an unknown carrier is an answer, not a throw');
        self::assertSame('tariff_not_found', $result['error']['code']);
        self::assertSame(
            ['carrier_id', 'service_id'],
            array_keys($result['error']['fields']),
            'the unresolved form carries no pin at all',
        );
    }

    // ------------------------------------------------------------------------ helpers

    /** @param array<string,AccessorialCharge> $accessorials */
    private static function tariff(array $accessorials = []): Tariff
    {
        return new Tariff('acme', 'ground', 1, 0, 5000, ['zone-a' => 450], 900, 120, $accessorials);
    }

    private static function predicate(): Predicate
    {
        return new Predicate(PolicyScope::HAZMAT, 'f', PolicyOperator::EXISTS);
    }

    /** @return array<string,mixed> */
    private static function document(): array
    {
        return ['tariffs' => [['carrier_id' => 'acme', 'service_id' => 'ground', 'versions' => [[
            'effective_at' => 0,
            'dimensional_weight_divisor' => 5000,
            'cost_per_dimensional_kg_minor' => ['zone-a' => 450],
        ]]]]];
    }

    /**
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function catalogWithOverride(string $kind, array $payload): array
    {
        return ['catalogs' => [['catalog_id' => 'dc-1', 'versions' => [[
            'effective_at' => 0,
            'published_at' => 0,
            'snapshot' => ['overrides' => [[
                'id' => 'o', 'facility_id' => 'f', 'entry_id' => 'e',
                'kind' => $kind, 'override' => $payload,
            ]]],
        ]]]]];
    }
}
