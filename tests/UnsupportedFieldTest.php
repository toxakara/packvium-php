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
        // A cross-language fixture kept one level above this package; a published copy
        // does not carry it.
        $shared = dirname(__DIR__, 2) . '/conformance/public-field-matrix.json';
        if (!is_file($shared)) {
            self::skip('the shared public field matrix is not part of this package');
        }
        $matrix = json_decode((string) file_get_contents($shared), true);

        // An engine refuses a field by name; the matrix is keyed on the schema's leaves, so
        // one refused field is several rows. The matrix's own `rejection_name` -- the name
        // the conformance harness demands in the diagnostic -- ties the rows to the field,
        // so the comparison is made on that and never inferred from the spelling of a path.
        $rows = [];
        foreach ($matrix['fields'] as $path => $row) {
            $rows[$path] = [$row['rejection_name'] ?? null, $matrix['support_sets'][$row['support']]['php'] ?? null];
        }
        $rejectedByMatrix = [];
        foreach ($rows as [$name, $support]) {
            if ($support === 'rejected:unsupported_feature') {
                $rejectedByMatrix[self::fieldOf((string) $name)] = true;
            }
        }

        $declared = [];
        foreach (ArrayCodec::UNSUPPORTED_FIELDS as $scope => $names) {
            foreach ($names as $name) {
                $declared[$scope === 'request' ? $name : "{$scope}.{$name}"] = true;
            }
        }
        if (ArrayCodec::UNSUPPORTED_SHAPE_TYPES !== []) {
            $declared['item.shape_type'] = true;
        }

        $declaredNames = array_keys($declared);
        $matrixNames = array_keys($rejectedByMatrix);
        sort($declaredNames);
        sort($matrixNames);
        self::assertSame($matrixNames, $declaredNames,
            'the engine and the matrix disagree about what PHP refuses');
        // A field refused by name is refused on every one of its leaves: a row that names a
        // refused field while recording this engine as implementing it is a matrix error.
        $halfRecorded = [];
        foreach ($rows as $path => [$name, $support]) {
            if ($name !== null && isset($declared[self::fieldOf($name)]) && $support !== 'rejected:unsupported_feature') {
                $halfRecorded[] = $path;
            }
        }
        self::assertSame([], $halfRecorded, 'rows recorded as implemented for a field PHP refuses');
    }

    /** A value-keyed template such as `item.shape_type={value}` names the field before `=`. */
    private static function fieldOf(string $rejectionName): string
    {
        return explode('=', $rejectionName, 2)[0];
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
