<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Serialization\ArrayCodec;
use Packvium\Serialization\UnsupportedFeatureException;

/**
 * The staged-rollout guard.
 *
 * `ArrayCodec::pack()` reads the keys it knows and ignores the rest, so a public field
 * added to the schema before this engine implements it would otherwise produce a
 * confident answer computed as though the caller had never sent it. That is
 * indistinguishable from an engine that honoured the field, which is the exact failure
 * the public-field evidence audit exists to catch. The lists are empty today, so the
 * guard is exercised with injected ones -- against the real constant a passing test would
 * prove only that nothing is rejected, which is equally true of a guard that does nothing.
 */
final class UnsupportedFieldTest extends TestCase
{
    private const REQUEST = [
        'policy' => ['rules' => []],
        'configuration' => ['tariff' => []],
        'items' => [['id' => 'a', 'rate' => 1], ['id' => 'b', 'rate' => 2]],
        'containers' => [['id' => 'c', 'rate_table' => []]],
    ];

    public static function testALlistedFieldIsRejectedWhereverItAppears(): void
    {
        $message = null;
        try {
            ArrayCodec::rejectUnsupported(self::REQUEST, [
                'request' => ['policy'],
                'configuration' => ['tariff'],
                'item' => ['rate'],
                'container' => ['rate_table'],
            ]);
        } catch (UnsupportedFeatureException $error) {
            $message = $error->getMessage();
        }

        self::assertNotNull($message, 'every listed field should be refused');
        self::assertTrue(str_starts_with($message, 'unsupported_feature:'), $message);
        foreach (['policy', 'configuration.tariff', 'item.rate', 'container.rate_table'] as $expected) {
            self::assertTrue(str_contains($message, $expected), "{$expected} missing from {$message}");
        }
    }

    public static function testOneFieldOnSeveralEntriesIsNamedOnce(): void
    {
        $message = null;
        try {
            ArrayCodec::rejectUnsupported(self::REQUEST, ['item' => ['rate']]);
        } catch (UnsupportedFeatureException $error) {
            $message = $error->getMessage();
        }

        self::assertNotNull($message);
        self::assertSame(1, substr_count($message, 'item.rate'), $message);
    }

    public static function testARequestThatTouchesNothingListedIsAccepted(): void
    {
        ArrayCodec::rejectUnsupported(self::REQUEST, [
            'request' => ['unrelated'],
            'configuration' => ['other'],
            'item' => ['unrelated'],
            'container' => ['unrelated'],
        ]);

        self::assertTrue(true, 'no exception is the assertion here');
    }

    /**
     * Every refusal this engine makes is recorded in the matrix, and the reverse.
     *
     * The assertion used to be that all four lists are empty, which was the same thing
     * while they were -- and stopped being the same thing the moment one was populated.
     * What the coupling is for is that the corpus *asserts* each rejection instead of
     * merely tolerating it, so read the matrix and compare both directions.
     */
    public static function testTheUnsupportedListsMatchWhatTheFieldMatrixRecords(): void
    {
        $matrix = json_decode((string) file_get_contents(
            dirname(__DIR__, 2) . '/conformance/public-field-matrix.json'
        ), true);

        $rejectedByMatrix = [];
        foreach ($matrix['fields'] as $path => $row) {
            if (($matrix['support_sets'][$row['support']]['php'] ?? null) === 'rejected:unsupported_feature') {
                $rejectedByMatrix[$path] = true;
            }
        }

        $declared = [];
        foreach (ArrayCodec::UNSUPPORTED_FIELDS as $scope => $names) {
            foreach ($names as $name) {
                $declared[in_array($scope, ['item', 'container'], true) ? "{$scope}s.*.{$name}" : $name] = true;
            }
        }
        // A value-keyed refusal is one matrix row for the field itself. `hull_vertices` is
        // an array of points, so the schema's leaves -- and therefore its rows -- are the
        // three coordinates, not the array.
        if (ArrayCodec::UNSUPPORTED_SHAPE_TYPES !== []) {
            $declared['items.*.shape_type'] = true;
        }
        if (isset($declared['items.*.hull_vertices'])) {
            unset($declared['items.*.hull_vertices']);
            foreach (['x', 'y', 'z'] as $axis) {
                $declared["items.*.hull_vertices.*.{$axis}"] = true;
            }
        }

        $declaredNames = array_keys($declared);
        $matrixNames = array_keys($rejectedByMatrix);
        sort($declaredNames);
        sort($matrixNames);
        self::assertSame($matrixNames, $declaredNames,
            'the engine and the matrix disagree about what PHP refuses');
    }

    public static function testTheDefaultShapeTypeIsServedRatherThanRefused(): void
    {
        // `rigid_cuboid` is implemented, so spelling the default out must not be a
        // rejection. This is why `shape_type` is not in the presence-keyed table: that
        // table means "this engine does not implement the field at all", which is a
        // different claim from "not in every value".
        ArrayCodec::rejectUnsupported([
            'items' => [['id' => 'a', 'shape_type' => 'rigid_cuboid']],
        ]);

        self::assertTrue(true, 'no exception is the assertion here');
    }

    public static function testAnUnimplementedShapeTypeNamesTheValueItRefused(): void
    {
        try {
            ArrayCodec::rejectUnsupported(
                ['items' => [['id' => 'a', 'shape_type' => 'convex_hull']]],
                ['request' => [], 'configuration' => [], 'item' => [], 'container' => []],
                ['convex_hull'],
            );
            self::fail('expected an unsupported_feature rejection');
        } catch (UnsupportedFeatureException $error) {
            self::assertTrue(str_contains($error->getMessage(), 'item.shape_type=convex_hull'), $error->getMessage());
        }
    }
}
