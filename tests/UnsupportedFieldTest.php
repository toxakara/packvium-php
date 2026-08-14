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

    public static function testTheUnsupportedListsMatchWhatTheFieldMatrixRecords(): void
    {
        // Each name here must carry a rejected:unsupported_feature level for PHP in
        // conformance/public-field-matrix.json, which is what makes the corpus assert the
        // rejection rather than merely tolerate it.
        self::assertSame([], ArrayCodec::UNSUPPORTED_FIELDS['request']);
        self::assertSame([], ArrayCodec::UNSUPPORTED_FIELDS['configuration']);
        self::assertSame([], ArrayCodec::UNSUPPORTED_FIELDS['item']);
        self::assertSame([], ArrayCodec::UNSUPPORTED_FIELDS['container']);
    }
}
