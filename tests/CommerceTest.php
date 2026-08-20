<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Commerce\Api;
use Packvium\Commerce\CommerceInputException;

use function Packvium\Commerce\canonicalJson;
use function Packvium\Commerce\catalogVersionInfo;
use function Packvium\Commerce\evaluatePolicy;
use function Packvium\Commerce\quote;

/**
 * The exported commercial and control-plane API.
 *
 * The equality half of this suite is deliberately not written out by hand: it replays
 * the shared cross-language fixtures against the committed golden documents the
 * reference implementation produced. Hand-written expectations would only prove this
 * port agrees with whoever typed them; the goldens prove it agrees with the other
 * implementation, which is the bar docs/COMMERCE-API.md sets for PHP.
 *
 * The rest covers what a shared fixture cannot: that a malformed input throws rather
 * than quietly returning a rejection, and that a rejection is returned rather than
 * thrown.
 */
final class CommerceTest extends TestCase
{
    public static function testEverySharedFixtureMatchesItsGoldenDocumentOrIsRefused(): void
    {
        $fixtures = self::fixtures();
        if ($fixtures === []) {
            self::skip('the shared cross-language commerce fixtures are not part of this package');
        }
        foreach ($fixtures as $name => $case) {
            if (($case['expects'] ?? 'result') === 'input_error') {
                self::assertThrows(
                    CommerceInputException::class,
                    static fn() => self::dispatch($case),
                    "fixture {$name} is malformed and must be refused, not answered",
                );
                continue;
            }
            $golden = self::golden($name);
            if ($golden === null) {
                self::assertTrue(false, "no golden document for fixture {$name}");
                continue;
            }
            $result = self::dispatch($case);
            self::assertSame($golden, canonicalJson($result), "fixture {$name} diverged from the golden document");
        }
    }

    public static function testEveryDocumentedRejectionCodeIsReachable(): void
    {
        $fixtures = self::fixtures();
        if ($fixtures === []) {
            self::skip('the shared cross-language commerce fixtures are not part of this package');
        }
        $produced = [];
        foreach ($fixtures as $case) {
            if (($case['expects'] ?? 'result') === 'input_error') { continue; }
            $result = self::dispatch($case);
            if ($result['status'] === 'rejected') { $produced[$result['error']['code']] = true; }
        }
        $codes = array_keys($produced);
        sort($codes, SORT_STRING);
        $expected = Api::REJECTION_CODES;
        sort($expected, SORT_STRING);
        self::assertSame($expected, $codes, 'the fixture set no longer covers every rejection code');
    }

    public static function testAMalformedRequestThrowsRatherThanRejecting(): void
    {
        $document = self::minimalDocument();
        foreach ([
            ['zone' => 7],
            ['actual_weight_g' => -1],
            ['actual_weight_g' => true],
            ['requested_accessorials' => ['liftgate', 'liftgate']],
            ['discount_code' => 'FREE'],
        ] as $overrides) {
            self::assertThrows(
                CommerceInputException::class,
                static fn() => quote($document, self::shipment($overrides)),
                'a malformed request must be a caller error, not a rejection',
            );
        }
    }

    public static function testExactlyOneVersionPinIsRequired(): void
    {
        $document = self::minimalDocument();
        self::assertThrows(
            CommerceInputException::class,
            static fn() => quote($document, self::shipment(['tariff_version' => null])),
        );
        self::assertThrows(
            CommerceInputException::class,
            static fn() => quote($document, self::shipment(['as_of' => 0])),
        );
    }

    public static function testAnUnsupportedPolicyOperatorFailsAdmission(): void
    {
        $document = ['policy_rules' => [['rule_id' => 'r', 'versions' => [[
            'scope' => 'hazmat', 'action' => 'reject', 'priority' => 1, 'effective_at' => 0,
            'predicates' => [['scope' => 'hazmat', 'field' => 'x', 'operator' => 'contains', 'value' => 1]],
        ]]]]];

        self::assertThrows(
            CommerceInputException::class,
            static fn() => evaluatePolicy($document, ['scope' => 'hazmat', 'context' => [], 'as_of' => 0]),
        );
    }

    public static function testAnUnpriceableZoneIsAnAnswerNotAnException(): void
    {
        $result = quote(self::minimalDocument(), self::shipment(['zone' => 'zone-nowhere']));

        self::assertSame('rejected', $result['status']);
        self::assertSame('unavailable_zone', $result['error']['code']);
        self::assertSame('zone-nowhere', $result['error']['fields']['zone']);
    }

    public static function testCatalogMetadataReportsWhatTheVersionContains(): void
    {
        $document = ['catalogs' => [['catalog_id' => 'dc-1', 'versions' => [
            ['effective_at' => 0, 'published_at' => 0, 'snapshot' => ['items' => [
                ['id' => 'sku-b', 'dimensions_mm' => [10, 10, 10], 'weight_g' => 5],
                ['id' => 'sku-a', 'dimensions_mm' => [10, 10, 10], 'weight_g' => 5],
            ]]],
        ]]]];

        $result = catalogVersionInfo($document, ['catalog_id' => 'dc-1', 'resolved_at' => 7]);

        self::assertSame(['sku-a', 'sku-b'], $result['catalog']['item_ids'], 'ids must be sorted, not map-ordered');
        self::assertSame(2, $result['catalog']['entry_counts']['items']);
        self::assertNull($result['catalog']['rolled_back_from']);
    }

    public static function testCanonicalJsonIsIndependentOfInputKeyOrder(): void
    {
        $document = self::minimalDocument();
        $forward = self::shipment([]);
        $reversed = array_reverse($forward, true);

        self::assertSame(
            canonicalJson(quote($document, $forward)),
            canonicalJson(quote($document, $reversed)),
        );
    }

    // ------------------------------------------------------------------------ helpers

    /** @return array<string,array<string,mixed>> */
    private static function fixtures(): array
    {
        $directory = self::sharedRoot() . '/fixtures';
        if (!is_dir($directory)) { return []; }
        $cases = [];
        foreach (glob($directory . '/*.json') ?: [] as $path) {
            $cases[basename($path, '.json')] = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }
        ksort($cases, SORT_STRING);
        return $cases;
    }

    private static function golden(string $name): ?string
    {
        $path = self::sharedRoot() . "/golden/{$name}.json";
        return is_file($path) ? trim((string)file_get_contents($path)) : null;
    }

    private static function sharedRoot(): string
    {
        return dirname(__DIR__, 2) . '/conformance/commerce';
    }

    /**
     * @param  array<string,mixed> $case
     * @return array<string,mixed>
     */
    private static function dispatch(array $case): array
    {
        return match ($case['operation']) {
            'quote' => quote($case['document'], $case['request']),
            'evaluate_policy' => evaluatePolicy($case['document'], $case['request']),
            'catalog_version_info' => catalogVersionInfo($case['document'], $case['request']),
            default => throw new \RuntimeException("unknown operation '{$case['operation']}'"),
        };
    }

    /** @return array<string,mixed> */
    private static function minimalDocument(): array
    {
        return ['tariffs' => [['carrier_id' => 'acme', 'service_id' => 'ground', 'versions' => [[
            'effective_at' => 0,
            'dimensional_weight_divisor' => 5000,
            'cost_per_dimensional_kg_minor' => ['zone-a' => 450],
            'minimum_charge_minor' => 900,
            'fuel_surcharge_permille' => 120,
            'accessorials' => [['accessorial_id' => 'liftgate', 'flat_charge_minor' => 250]],
        ]]]]];
    }

    /**
     * @param  array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function shipment(array $overrides): array
    {
        $request = [
            'carrier_id' => 'acme', 'service_id' => 'ground', 'tariff_version' => 1,
            'zone' => 'zone-a', 'actual_weight_g' => 1200, 'volume_mm3' => 6000000,
        ];
        return array_filter(array_merge($request, $overrides), static fn(mixed $value): bool => $value !== null);
    }
}
