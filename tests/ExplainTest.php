<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Domain\Dimensions;
use Packvium\Domain\Item;
use Packvium\Domain\ItemInstance;
use Packvium\Domain\ReasonProof;
use Packvium\Domain\RejectionObservation;
use Packvium\Domain\UnpackedItem;
use Packvium\Explain\Explain;
use Packvium\Explain\UnknownReasonException;

/**
 * Rendering an UnpackedItem into a human-readable sentence must be driven
 * entirely by its structured reason/proof, never by string-matching anything, and
 * must be deterministic -- the same item always explains the same way. The Python
 * suite runs the same shape of test in test_explain.py.
 */
final class ExplainTest extends TestCase
{
    private static function instance(string $id = 'a'): ItemInstance
    {
        $item = Item::create($id, Dimensions::mm(1, 1, 1));
        return $item->instances()[0];
    }

    // ------------------------------------------------------------- reason coverage

    public static function testEveryReasonThisLibraryCanProduceHasARegisteredMessage(): void
    {
        // The reason-code vocabulary, pinned here so a new reason code introduced
        // without a matching message fails a test instead of shipping a silently
        // mute explanation.
        $produced = [
            'no_compatible_container_dimensions', 'rotation_restricted', 'payload_exceeded',
            'no_eligible_container', 'time_limit', 'effort_limit', 'group_cannot_fit_together',
            'insufficient_support', 'no_feasible_placement',
        ];
        foreach ($produced as $reason) {
            self::assertTrue(array_key_exists($reason, Explain::REASON_MESSAGES), "missing message for {$reason}");
        }
    }

    public static function testAnUnregisteredReasonCodeThrowsRatherThanRenderingSilently(): void
    {
        self::assertThrows(UnknownReasonException::class, static fn() => Explain::reason('not_a_real_reason_code'));
    }

    // ---------------------------------------------------------------------- rendering

    public static function testExplanationNamesTheItemAndTheReasonSentence(): void
    {
        $item = new UnpackedItem(self::instance('crate'), 'payload_exceeded');
        $text = Explain::unpackedItem($item);
        self::assertTrue(str_starts_with($text, 'crate#1: '), $text);
        self::assertTrue(str_contains($text, Explain::REASON_MESSAGES['payload_exceeded']), $text);
    }

    public static function testExplanationCarriesTheProofLevelAsAPrefix(): void
    {
        $proven = new UnpackedItem(self::instance(), 'no_compatible_container_dimensions');
        self::assertSame('proven', $proven->proof->level);
        self::assertTrue(str_starts_with(Explain::unpackedItem($proven), 'a#1: Proven: '));

        $limited = new UnpackedItem(self::instance(), 'time_limit');
        self::assertSame('unknown_due_to_limit', $limited->proof->level);
        self::assertTrue(str_contains(Explain::unpackedItem($limited), Explain::LEVEL_PREFIXES['unknown_due_to_limit']));
    }

    public static function testAProofWithNoRegisteredLevelRendersWithNoPrefixRatherThanThrowing(): void
    {
        // Constructed directly, bypassing ReasonProof::forReason's classification, to
        // prove the renderer degrades gracefully instead of assuming every level it
        // might ever see is one of the four it currently knows about.
        $item = new UnpackedItem(
            self::instance(),
            'payload_exceeded',
            proof: new ReasonProof('a_future_level', [new RejectionObservation('payload_exceeded')]),
        );
        self::assertSame('a#1: ' . Explain::REASON_MESSAGES['payload_exceeded'], Explain::unpackedItem($item));
    }

    public static function testDetailsAlreadyAttachedAreAppendedVerbatimNotReformatted(): void
    {
        $item = new UnpackedItem(self::instance(), 'effort_limit', ['max_search_nodes', 'exhausted at node 4021']);
        $text = Explain::unpackedItem($item);
        self::assertTrue(str_ends_with($text, ' (max_search_nodes; exhausted at node 4021)'), $text);
    }

    public static function testNoDetailsProducesNoTrailingParenthetical(): void
    {
        $item = new UnpackedItem(self::instance(), 'no_feasible_placement');
        self::assertFalse(str_ends_with(Explain::unpackedItem($item), ')'));
    }

    // ------------------------------------------------------------- batch + determinism

    public static function testUnpackedItemsPreservesInputOrder(): void
    {
        $items = [
            new UnpackedItem(self::instance('first'), 'payload_exceeded'),
            new UnpackedItem(self::instance('second'), 'time_limit'),
        ];
        $explained = Explain::unpackedItems($items);
        self::assertTrue(str_starts_with($explained[0], 'first#1:'));
        self::assertTrue(str_starts_with($explained[1], 'second#1:'));
    }

    public static function testRenderingTheSameItemTwiceIsByteIdentical(): void
    {
        $item = new UnpackedItem(self::instance(), 'insufficient_support', ['x']);
        self::assertSame(Explain::unpackedItem($item), Explain::unpackedItem($item));
    }

    public static function testLocalizationDescriptorIsStableAndSeparateFromRendering(): void
    {
        $item = new UnpackedItem(self::instance('crate'), 'payload_exceeded', ['limit=1kg']);
        $descriptor = Explain::unpackedItemDescriptor($item);
        self::assertSame('packvium.unpacked.payload_exceeded', $descriptor['message_key']);
        self::assertSame('crate#1', $descriptor['arguments']['item_id']);
        self::assertSame('proven', $descriptor['arguments']['evidence_level']);
        self::assertSame('limit=1kg', $descriptor['arguments']['details']);
        self::assertSame(Explain::REASON_MESSAGES['payload_exceeded'], $descriptor['default_message']);
    }
}
