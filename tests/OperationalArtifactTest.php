<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Artifacts\ArtifactExports;
use Packvium\Artifacts\OperationalArtifact;
use Packvium\Artifacts\OperationalArtifactException;
use Packvium\Execution\Plan;
use stdClass;

/**
 * The operational artifact and its exports in PHP.
 *
 * `docs/OPERATIONAL-ARTIFACTS.md` is the contract and `packvium.artifacts` the reference. The
 * assertions follow what the document promises the artifact will not do: re-derive the plan
 * or its order, address a placement by `item_id`, let wall-clock time in, claim an exact
 * replay it cannot give, or re-render a value the result already rendered. Byte equality with
 * Python is asserted by `conformance/artifacts/run.py`, not here.
 */
final class OperationalArtifactTest extends TestCase
{
    private const TICKS_PER_MM = 16000;

    private const REQUEST = [
        'items' => [[
            'id' => 'box',
            'dimensions' => ['length' => 10, 'width' => 10, 'height' => 10],
            'minimum_support_ratio' => 0.25,
        ]],
        'containers' => [['id' => 'crate', 'inner_dimensions' => ['length' => 100, 'width' => 100, 'height' => 100]]],
    ];

    /** @return array<string,mixed> */
    private static function scalar(int $ticks, string $unit = 'mm'): array
    {
        $perUnit = $unit === 'mm' ? self::TICKS_PER_MM : 8000;
        return ['ticks' => $ticks, 'unit' => $unit, 'value' => (string) \intdiv($ticks, $perUnit)];
    }

    /** @return array<string,mixed> */
    private static function placement(string $itemType, int $xMm, int $lengthMm = 10): array
    {
        return [
            'item_id' => $itemType . '#' . $xMm,
            'item_type' => $itemType,
            'orientation' => 'LWH',
            'position' => [
                'x' => self::scalar($xMm * self::TICKS_PER_MM),
                'y' => self::scalar(0),
                'z' => self::scalar(0),
            ],
            'dimensions' => [
                'length' => self::scalar($lengthMm * self::TICKS_PER_MM),
                'width' => self::scalar(10 * self::TICKS_PER_MM),
                'height' => self::scalar(10 * self::TICKS_PER_MM),
            ],
            'support_ratio' => '1.000000',
            'top_load' => self::scalar(0, 'g'),
        ];
    }

    /**
     * @param array<string,mixed>|string|null $algorithm 'default', null for none, or a record
     * @param list<array<string,mixed>>|null $placements
     * @param list<array<string,mixed>> $unpacked
     * @return array<string,mixed>
     */
    private static function result($algorithm = 'default', ?array $placements = null, array $unpacked = []): array
    {
        $result = [
            'status' => 'feasible',
            'objective' => 'default',
            'score' => [1, 0, 250],
            'feasibility' => ['code' => 'all_items_packed'],
            'optimality' => null,
            'containers' => [[
                'id' => 'crate#1',
                'container_type' => 'crate',
                'inner_dimensions' => [
                    'length' => self::scalar(100 * self::TICKS_PER_MM),
                    'width' => self::scalar(100 * self::TICKS_PER_MM),
                    'height' => self::scalar(100 * self::TICKS_PER_MM),
                ],
                'payload_weight' => self::scalar(8000, 'g'),
                'gross_weight' => self::scalar(16000, 'g'),
                'volume_utilization' => '0.002000',
                'placements' => $placements ?? [self::placement('box', 0), self::placement('tin', 10, 5)],
            ]],
            'unpacked_items' => $unpacked,
            'catalog_versions_used' => [['catalog_id' => 'cartons', 'version' => 3, 'effective_at' => 10, 'resolved_at' => 11]],
        ];
        if ($algorithm === 'default') {
            $result['algorithm'] = self::algorithm();
        } elseif ($algorithm !== null) {
            $result['algorithm'] = $algorithm;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private static function algorithm(): array
    {
        return [
            'profile' => 'balanced', 'solver' => 'extreme_point', 'seed' => 7, 'duration_ms' => 41,
            'time_limit_reached' => false, 'effort_limit_reached' => false,
        ];
    }

    /** @return array<string,mixed> */
    private static function artifact(?array $placements = null, array $unpacked = []): array
    {
        return OperationalArtifact::build(self::REQUEST, self::result('default', $placements, $unpacked), [0 => [1, 0]]);
    }

    private static function code(callable $refused): string
    {
        try {
            $refused();
        } catch (OperationalArtifactException $error) {
            return $error->errorCode();
        }
        self::fail('the artifact was built or exported anyway');
    }

    // ------------------------------------------------------------------------ the document

    public static function testThePlanInsideIsExactlyThePlanBuilderOutput(): void
    {
        $artifact = OperationalArtifact::build(self::REQUEST, self::result(), [0 => [1, 0]]);
        self::assertSame(OperationalArtifact::FORMAT, $artifact['format']);
        self::assertSame(OperationalArtifact::SUITE_VERSION, $artifact['suite_version']);
        self::assertSame(
            Plan::canonicalJson(Plan::build(self::REQUEST, self::result(), [0 => [1, 0]])),
            Plan::canonicalJson($artifact['plan'])
        );
    }

    public static function testTheRequestIsEmbeddedAsGivenWithItsFractionalNumbers(): void
    {
        $artifact = OperationalArtifact::build(self::REQUEST, self::result());
        self::assertSame(self::REQUEST, $artifact['provenance']['request']);
        self::assertTrue(\strpos(OperationalArtifact::canonicalJson($artifact), '"minimum_support_ratio":0.25') !== false);
    }

    public static function testJsonTextKeepsEmptyObjectsAndNumericNamesInTheRequestAndThePlan(): void
    {
        $result = self::result();
        $result['feasibility'] = new stdClass();
        $artifact = OperationalArtifact::fromJson(
            '{"items":[],"containers":[],"metadata":{},"configuration":{"0":"a","1":{}}}',
            (string) \json_encode($result)
        );
        $json = OperationalArtifact::canonicalJson($artifact);
        self::assertTrue(\strpos($json, '"configuration":{"0":"a","1":{}}') !== false);
        self::assertTrue(\strpos($json, '"metadata":{}') !== false);
        self::assertTrue(\strpos($json, '"feasibility":{}') !== false);
    }

    public static function testGeometryIsInResultOrderInTickStringsAddressedByPlacementReference(): void
    {
        $container = OperationalArtifact::build(self::REQUEST, self::result())['geometry']['containers'][0];
        self::assertSame(['length' => '1600000', 'width' => '1600000', 'height' => '1600000'], $container['inner_dimensions']);
        self::assertSame(['box', 'tin'], \array_map(static function (array $entry): string {
            return $entry['placement']['item_type'];
        }, $container['placements']));
        self::assertSame('80000', $container['placements'][1]['dimensions']['length']);
        self::assertFalse(\strpos((string) \json_encode($container), 'item_id') !== false);
    }

    public static function testWorkOrderLinesFollowThePlanStepsAndCopyRenderedValues(): void
    {
        $artifact = self::artifact();
        $workOrder = $artifact['work_order'];
        $container = $workOrder['containers'][0];
        self::assertSame(['mm', 'g'], [$workOrder['length_unit'], $workOrder['weight_unit']]);
        self::assertSame(['1', '2'], [$container['payload_weight'], $container['gross_weight']]);
        self::assertSame([[1, 'tin'], [2, 'box']], \array_map(static function (array $line): array {
            return [$line['sequence'], $line['placement']['item_type']];
        }, $container['lines']));
        self::assertSame(['x' => '10', 'y' => '0', 'z' => '0'], $container['lines'][0]['position']);
        self::assertSame(
            \array_column($artifact['plan']['containers'][0]['steps'], 'placement'),
            \array_column($container['lines'], 'placement')
        );
    }

    public static function testWithoutALoadingOrderNoLineIsNumbered(): void
    {
        foreach (OperationalArtifact::build(self::REQUEST, self::result())['work_order']['containers'][0]['lines'] as $line) {
            self::assertFalse(\array_key_exists('sequence', $line));
        }
    }

    public static function testAResultWithoutContainersHasNoUnitsAndNoLines(): void
    {
        $result = self::result();
        $result['containers'] = [];
        self::assertSame(
            ['length_unit' => null, 'weight_unit' => null, 'containers' => []],
            OperationalArtifact::build(self::REQUEST, $result)['work_order']
        );
    }

    // -------------------------------------------------------------------------- provenance

    public static function testTheSolverIsTheDeterministicPartOfTheAlgorithmAndWallClockNeverEnters(): void
    {
        $artifact = OperationalArtifact::build(self::REQUEST, self::result());
        self::assertSame([
            'profile' => 'balanced', 'solver' => 'extreme_point', 'seed' => 7,
            'time_limit_reached' => false, 'effort_limit_reached' => false,
        ], $artifact['provenance']['solver']);
        self::assertSame(['level' => 'exact', 'because' => null], $artifact['provenance']['replay']);
        self::assertSame('cartons', $artifact['provenance']['catalog_versions_used'][0]['catalog_id']);
        self::assertFalse(\strpos(OperationalArtifact::canonicalJson($artifact), 'duration_ms') !== false);
    }

    public static function testASearchStoppedByTheClockIsNotPromisedAnExactReplay(): void
    {
        $algorithm = ['time_limit_reached' => true] + self::algorithm();
        $replay = OperationalArtifact::build(self::REQUEST, self::result($algorithm))['provenance']['replay'];
        self::assertSame(['level' => 'not_guaranteed', 'because' => 'provenance.solver.time_limit_reached'], $replay);
    }

    public static function testAResultThatDoesNotSayHowItWasSolvedIsNotPromisedOneEither(): void
    {
        $provenance = OperationalArtifact::build(self::REQUEST, self::result(null))['provenance'];
        self::assertNull($provenance['solver']);
        self::assertSame(['level' => 'not_guaranteed', 'because' => 'provenance.solver'], $provenance['replay']);
    }

    // ---------------------------------------------------------------------------- refusals

    public static function testARequestOrResultThatIsNotAnObjectIsRefused(): void
    {
        foreach ([[], ['a'], 'text', null] as $request) {
            self::assertSame('invalid_request', self::code(static function () use ($request): void {
                OperationalArtifact::build($request, self::result());
            }));
        }
        self::assertSame('invalid_result', self::code(static function (): void {
            OperationalArtifact::build(self::REQUEST, [1, 2]);
        }));
    }

    public static function testALoadingOrderThatIsNotAPermutationIsRefusedByThePlan(): void
    {
        self::assertSame('invalid_plan_input', self::code(static function (): void {
            OperationalArtifact::build(self::REQUEST, self::result(), [0 => [0, 0]]);
        }));
    }

    public static function testValuesInTwoUnitsAreRefusedRatherThanPrintedAsOne(): void
    {
        $result = self::result();
        $result['containers'][0]['placements'][0]['position']['x']['unit'] = 'cm';
        self::assertSame('mixed_units', self::code(static function () use ($result): void {
            OperationalArtifact::build(self::REQUEST, $result);
        }));
    }

    public static function testTwoPlacementsWithOneReferenceAreRefused(): void
    {
        $result = self::result('default', [self::placement('box', 0), self::placement('box', 0)]);
        self::assertSame('invalid_result', self::code(static function () use ($result): void {
            OperationalArtifact::build(self::REQUEST, $result);
        }));
    }

    public static function testAContainerWithoutInnerDimensionsIsRefused(): void
    {
        $result = self::result();
        unset($result['containers'][0]['inner_dimensions']);
        self::assertSame('invalid_result', self::code(static function () use ($result): void {
            OperationalArtifact::build(self::REQUEST, $result);
        }));
    }

    public static function testAnAlgorithmRecordThatIsIncompleteOrMistypedIsRefused(): void
    {
        foreach ([['profile' => 'fast'], ['time_limit_reached' => 'no'] + self::algorithm(), 'fast'] as $algorithm) {
            self::assertSame('invalid_result', self::code(static function () use ($algorithm): void {
                OperationalArtifact::build(self::REQUEST, self::result($algorithm));
            }));
        }
    }

    public static function testARequestNumberJavascriptCannotHoldIsRefused(): void
    {
        $request = self::REQUEST + ['metadata' => ['order' => 9007199254740992]];
        self::assertSame('number_out_of_range', self::code(static function () use ($request): void {
            OperationalArtifact::build($request, self::result());
        }));
    }

    public static function testAContainerThatIsNotAnObjectIsRefusedByNameBeforeAnythingReadsIt(): void
    {
        $result = self::result();
        $result['containers'] = ['crate'];
        self::assertSame('invalid_result', self::code(static function () use ($result): void {
            OperationalArtifact::build(self::REQUEST, $result);
        }));
        $result = self::result();
        $result['containers'][0]['placements'][] = 'box';
        self::assertSame('invalid_result', self::code(static function () use ($result): void {
            OperationalArtifact::build(self::REQUEST, $result);
        }));
    }

    public static function testGeometryTicksJavascriptCannotHoldAreRefused(): void
    {
        // Geometry writes ticks as strings, so the canonical writer never sees them as numbers.
        $result = self::result();
        $result['containers'][0]['placements'][0]['dimensions']['length']['ticks'] = 9007199254740992;
        self::assertSame('number_out_of_range', self::code(static function () use ($result): void {
            OperationalArtifact::build(self::REQUEST, $result);
        }));
    }

    public static function testACatalogReferenceOfTheWrongTypeIsRefused(): void
    {
        foreach ([['version' => 1.5], ['version' => true], ['catalog_id' => 3]] as $change) {
            $result = self::result();
            $result['catalog_versions_used'][0] = $change + $result['catalog_versions_used'][0];
            self::assertSame('invalid_result', self::code(static function () use ($result): void {
                OperationalArtifact::build(self::REQUEST, $result);
            }));
        }
    }

    public static function testTextThatIsNotJsonIsRefused(): void
    {
        foreach (['{', '{"bad":"\ud800"}', "\xEF\xBB\xBF{}"] as $text) {
            self::assertSame('invalid_json', self::code(static function () use ($text): void {
                OperationalArtifact::fromJson($text, (string) \json_encode(self::result()));
            }));
        }
    }

    public static function testTheBuilderImportsNoSolverValidatorRendererOrClock(): void
    {
        $sources = [
            'Artifacts/OperationalArtifact.php', 'Artifacts/ArtifactExports.php', 'Support/CanonicalJson.php',
            'Support/JsonValue.php', 'Support/ShortestFloat.php',
        ];
        foreach ($sources as $source) {
            $code = (string) \file_get_contents(__DIR__ . '/../src/' . $source);
            foreach (['Packvium\\Algorithm', 'Packvium\\Validation', 'Packvium\\Packer', 'Packvium\\Commerce'] as $layer) {
                self::assertFalse(\strpos($code, 'use ' . $layer) !== false, "{$source} uses {$layer}");
            }
            foreach (['time(', 'hrtime(', 'date(', 'rand(', 'random_', 'uniqid('] as $clock) {
                self::assertFalse(\strpos($code, $clock) !== false, "{$source} calls {$clock}");
            }
        }
    }

    // ----------------------------------------------------------------------------- exports

    public static function testTheJsonExportIsTheCanonicalArtifact(): void
    {
        $artifact = self::artifact();
        self::assertSame(OperationalArtifact::canonicalJson($artifact), ArtifactExports::json($artifact));
        self::assertEquals(
            \json_decode((string) \json_encode($artifact), true),
            \json_decode(ArtifactExports::json($artifact), true)
        );
    }

    public static function testEveryExportRefusesADocumentItCannotRead(): void
    {
        $foreign = ['format' => 'packvium-operational-artifact/v2'] + self::artifact();
        foreach (['json', 'csv', 'workOrderHtml'] as $export) {
            foreach ([$foreign, ['not', 'an', 'artifact'], new stdClass()] as $document) {
                self::assertSame('unknown_format', self::code(static function () use ($export, $document): void {
                    ArtifactExports::$export($document);
                }));
            }
        }
    }

    public static function testAValueWithNoSingleRenderingIsRefusedRatherThanPrintedFourWays(): void
    {
        // Python would print `True`, PHP `1`, JavaScript `true`: none of them is the answer.
        foreach ([true, 1.5, ['a'], new stdClass()] as $containerType) {
            $result = self::result();
            $result['containers'][0]['container_type'] = $containerType;
            $artifact = OperationalArtifact::build(self::REQUEST, $result);
            foreach (['csv', 'workOrderHtml'] as $export) {
                self::assertSame('invalid_value', self::code(static function () use ($export, $artifact): void {
                    ArtifactExports::$export($artifact);
                }));
            }
        }
        $result = self::result();
        $result['feasibility'] = ['code' => true];
        self::assertSame('invalid_value', self::code(static function () use ($result): void {
            ArtifactExports::workOrderHtml(OperationalArtifact::build(self::REQUEST, $result));
        }));
    }

    public static function testAContainerNumberThatIsNotAnIntegerIsRefusedNotComputed(): void
    {
        // `true + 1` is 2 and `0.5 + 1` is 1.5 in PHP; neither is the reference's answer.
        foreach ([true, false, 0.5, '0', null] as $index) {
            $artifact = self::artifact();
            $artifact['work_order']['containers'][0]['container_index'] = $index;
            self::assertSame('invalid_value', self::code(static function () use ($artifact): void {
                ArtifactExports::workOrderHtml($artifact);
            }));
            $stored = OperationalArtifact::parse(ArtifactExports::json(self::artifact()));
            $stored->work_order->containers[0]->container_index = $index;
            self::assertSame('invalid_value', self::code(static function () use ($stored): void {
                ArtifactExports::workOrderHtml($stored);
            }));
        }
    }

    public static function testABooleanInAnyPrintedPositionIsRefused(): void
    {
        $artifact = self::artifact();
        $artifact['work_order']['containers'][0]['lines'][0]['placement']['container_index'] = true;
        self::assertSame('invalid_value', self::code(static function () use ($artifact): void {
            ArtifactExports::csv($artifact);
        }));
        $artifact = self::artifact();
        $artifact['work_order']['containers'][0]['lines'][0]['sequence'] = false;
        foreach (['csv', 'workOrderHtml'] as $export) {
            self::assertSame('invalid_value', self::code(static function () use ($export, $artifact): void {
                ArtifactExports::$export($artifact);
            }));
        }
    }

    public static function testAResultOfTheWrongShapeIsRefusedByName(): void
    {
        $containersAsObject = self::result();
        $containersAsObject['containers'] = ['crate' => $containersAsObject['containers'][0]];
        $textTicks = self::result();
        $textTicks['containers'][0]['placements'][0]['dimensions']['length']['ticks'] = '160000';
        $fractionalTicks = self::result();
        $fractionalTicks['containers'][0]['inner_dimensions']['width']['ticks'] = 1.5;
        $numericValue = self::result();
        $numericValue['containers'][0]['payload_weight']['value'] = 1;
        foreach ([$containersAsObject, $textTicks, $fractionalTicks, $numericValue] as $result) {
            self::assertSame('invalid_result', self::code(static function () use ($result): void {
                OperationalArtifact::build(self::REQUEST, $result);
            }));
        }
    }

    public static function testAnExportRefusesADocumentMissingWhatTheFormatRequires(): void
    {
        $missing = self::artifact();
        unset($missing['work_order']);
        $objectForList = self::artifact();
        $objectForList['work_order']['containers'] = ['first' => $objectForList['work_order']['containers'][0]];
        $stored = OperationalArtifact::parse(ArtifactExports::json(self::artifact()));
        $stored->plan->unplaced = new stdClass();
        $cases = [[$missing, 'csv'], [$missing, 'workOrderHtml'], [$objectForList, 'csv'], [$stored, 'workOrderHtml']];
        foreach ($cases as [$document, $export]) {
            self::assertSame('unknown_format', self::code(static function () use ($document, $export): void {
                ArtifactExports::$export($document);
            }));
        }
    }

    public static function testTheCsvHasAHeaderOneRowPerStepInOrderAndOnePerUnplacedItem(): void
    {
        $unpacked = [[
            'item_id' => 'jack#1', 'item_type' => 'jack', 'reason' => 'no_compatible_container_dimensions',
            'details' => [], 'proof' => ['level' => 'proven'],
        ]];
        $rows = \explode("\r\n", ArtifactExports::csv(self::artifact(null, $unpacked)));
        self::assertSame(\implode(',', ArtifactExports::CSV_COLUMNS), $rows[0]);
        self::assertSame('step,0,crate,1,tin,LWH,160000,0,0,10,0,0,5,10,10,mm,,', $rows[1]);
        self::assertSame('step,0,crate,2,box,LWH,0,0,0,0,0,0,10,10,10,mm,,', $rows[2]);
        self::assertSame('unplaced,,,,jack,,,,,,,,,,,,no_compatible_container_dimensions,proven', $rows[3]);
        self::assertSame('', $rows[4]);
        self::assertCount(5, $rows);
    }

    public static function testACsvFieldIsQuotedOnlyWhenItMustBeAndNeverRewritten(): void
    {
        $result = self::result('default', [
            self::placement('a,"b"', 0), self::placement('=SUM(A1)', 10), self::placement("two\nlines", 20),
        ]);
        $text = ArtifactExports::csv(OperationalArtifact::build(self::REQUEST, $result));
        self::assertTrue(\strpos($text, ',"a,""b""",') !== false);
        self::assertTrue(\strpos($text, ',=SUM(A1),') !== false);
        self::assertTrue(\strpos($text, ",\"two\nlines\",") !== false);
    }

    public static function testTheWorkOrderIsSelfContainedEscapedAndListsEveryStep(): void
    {
        $result = self::result('default', [self::placement("<b>&'\"", 0), self::placement('tin', 10, 5)]);
        $html = ArtifactExports::workOrderHtml(OperationalArtifact::build(self::REQUEST, $result, [0 => [1, 0]]));
        self::assertSame(0, \strpos($html, "<!DOCTYPE html>\n"));
        self::assertSame("</html>\n", \substr($html, -8));
        foreach (['<script', 'http', ' src=', "<b>&'"] as $forbidden) {
            self::assertFalse(\strpos($html, $forbidden) !== false, "the work order holds {$forbidden}");
        }
        self::assertTrue(\strpos($html, '&lt;b&gt;&amp;&#39;&quot;') !== false);
        self::assertSame(2, \substr_count($html, '&#9744;'));
        foreach (['Order: loading.', 'extreme_point', 'cartons v3'] as $expected) {
            self::assertTrue(\strpos($html, $expected) !== false, "the work order lacks {$expected}");
        }
        self::assertSame(0, \preg_match('/[^\x00-\x7F]/', $html));
    }

    public static function testTheWorkOrderSaysWhenThereIsNoOrderAndWhenEverythingWasPacked(): void
    {
        $html = ArtifactExports::workOrderHtml(OperationalArtifact::build(self::REQUEST, self::result()));
        self::assertTrue(\strpos($html, 'Order: unavailable.') !== false);
        self::assertTrue(\strpos($html, '<p>Every item was packed.</p>') !== false);
    }

    public static function testExportsAreDeterministicAndLeaveTheArtifactUntouched(): void
    {
        $artifact = self::artifact();
        $before = OperationalArtifact::canonicalJson($artifact);
        foreach (['json', 'csv', 'workOrderHtml'] as $export) {
            self::assertSame(ArtifactExports::$export($artifact), ArtifactExports::$export($artifact));
        }
        self::assertSame($before, OperationalArtifact::canonicalJson($artifact));
    }

    public static function testAnArtifactReadBackFromItsJsonExportsTheSameBytes(): void
    {
        $artifact = self::artifact(null, [[
            'item_id' => 'jack#1', 'item_type' => 'jack', 'reason' => 'too_heavy', 'details' => [], 'proof' => ['level' => 'observed'],
        ]]);
        $stored = OperationalArtifact::parse(ArtifactExports::json($artifact));
        foreach (['json', 'csv', 'workOrderHtml'] as $export) {
            self::assertSame(ArtifactExports::$export($artifact), ArtifactExports::$export($stored));
        }
    }

    // -------------------------------------------------------------------------- the corpus

    public static function testEveryGoldenResultWithItsRequestBuildsAndExports(): void
    {
        $root = \dirname(__DIR__, 2) . '/conformance';
        if (!\is_dir($root . '/golden') || !\is_dir($root . '/fixtures')) {
            self::skip('the corpus lives in the workspace only');
        }
        $built = 0;
        foreach (\glob($root . '/golden/*.json') ?: [] as $golden) {
            $resultText = (string) \file_get_contents($golden);
            $result = \json_decode($resultText, true, 512, \JSON_THROW_ON_ERROR);
            $fixture = $root . '/fixtures/' . \basename($golden);
            if (!\is_array($result) || !\array_key_exists('status', $result) || !\is_file($fixture)) {
                continue;
            }
            $orders = [];
            foreach ($result['containers'] ?? [] as $index => $container) {
                $orders[$index] = \array_reverse(\array_keys($container['placements'] ?? []));
            }
            $artifact = OperationalArtifact::fromJson((string) \file_get_contents($fixture), $resultText, $orders);
            self::assertSame(OperationalArtifact::FORMAT, \json_decode(ArtifactExports::json($artifact), true)['format']);
            ArtifactExports::csv($artifact);
            ArtifactExports::workOrderHtml($artifact);
            $built++;
        }
        self::assertGreaterThan(397, $built);
    }
}
