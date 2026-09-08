<?php
declare(strict_types=1);

namespace Packvium\Tests;

use InvalidArgumentException;
use Packvium\Domain\Container;
use Packvium\Domain\Dimensions;
use Packvium\Serialization\ArrayCodec;
use Packvium\Unit\Length;

/**
 * `container.access_directions` at its boundaries.
 *
 * The field was reserved at the 1.1.0 freeze and implemented in all four engines in one
 * change. What shipped alongside the packing rule is a validation and canonicalisation
 * path per engine, and coverage showed that path untested in every one of them: the happy
 * request was exercised by conformance, the refusals by nothing.
 *
 * Canonicalisation is the half a fixture cannot assert. Every fixture states its doors
 * once, in one order, so a canonicalisation that quietly stopped working leaves the whole
 * corpus green while making the engine order-sensitive -- a determinism break, which is a
 * correctness failure here rather than a preference.
 */
final class AccessDirectionsTest extends TestCase
{
    private static function crate(array $doors = []): Container
    {
        $side = new Dimensions(new Length(1600000), new Length(1600000), new Length(1600000));
        return Container::create('crate', $side, accessDirections: $doors);
    }

    public static function testDoorsAreDeduplicatedIntoTheCanonicalOrder(): void
    {
        self::assertSame(['-x', '+z'], self::crate(['+z', '-x', '+z', '-x'])->accessDirections);
        self::assertSame(['-x', '+z'], self::crate(['-x', '+z'])->accessDirections);
    }

    public static function testEveryLegalDirectionSurvivesCanonicalisation(): void
    {
        $reversed = ['-z', '+z', '-y', '+y', '-x', '+x'];
        self::assertSame(['+x', '-x', '+y', '-y', '+z', '-z'],
            self::crate($reversed)->accessDirections);
    }

    /** A container that names no doors is the pre-default: the rule is inert, not
     * the container sealed. Defaulting to all six would enforce a rule true of no real
     * vehicle and would change answers for every caller who never set the field. */
    public static function testAContainerStatesNoDoorsByDefault(): void
    {
        $side = new Dimensions(new Length(1600000), new Length(1600000), new Length(1600000));
        self::assertSame([], Container::create('crate', $side)->accessDirections);
    }

    public static function testAnUnknownDirectionIsRefusedRatherThanDropped(): void
    {
        foreach (['north', 'x', '+X', '+w', '', '-x '] as $direction) {
            $message = null;
            try {
                self::crate([$direction]);
            } catch (InvalidArgumentException $error) {
                $message = $error->getMessage();
            }
            self::assertNotNull($message, "{$direction} should be refused, not dropped");
            self::assertTrue(str_contains($message, 'unknown movement direction'), (string) $message);
        }
    }

    /** A partially honoured list is the worst outcome available: it validates and means
     * something the caller did not write. */
    public static function testOneBadDirectionRefusesTheWholeList(): void
    {
        $refused = false;
        try {
            self::crate(['-x', 'sideways', '+z']);
        } catch (InvalidArgumentException) {
            $refused = true;
        }
        self::assertTrue($refused, 'a list with one bad entry must be refused entirely');
    }

    // ------------------------------------------------ the same rules through a request

    private static function request(?array $doors): array
    {
        $container = ['id' => 'van',
                      'inner_dimensions' => ['length' => '200', 'width' => '100', 'height' => '100']];
        if ($doors !== null) {
            $container['access_directions'] = $doors;
        }
        return ['units' => ['length' => 'mm'],
                'items' => [['id' => 'cube', 'quantity' => 1,
                             'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100']]],
                'containers' => [$container]];
    }

    /** The decoder is a fourth way to name the doors and must not be a way around the
     * validation: docs/STOP-ACCESSIBILITY.md records that a rule no request can switch on
     * is untested along the path it will be switched on through. */
    public static function testARequestReachesTheSameValidationAsTheConstructor(): void
    {
        $refused = false;
        try {
            ArrayCodec::pack(self::request(['upwards']));
        } catch (InvalidArgumentException) {
            $refused = true;
        }
        self::assertTrue($refused, 'a bad door in a request must be refused');
    }

    /**
     * Everything the contract promises to reproduce.
     *
     * `algorithm.duration_ms` is wall clock and is the one field a determinism assertion
     * must not read: comparing whole documents passes or fails on how busy the machine is,
     * which reports the host rather than the engine.
     */
    private static function withoutWallClock(array $result): array
    {
        unset($result['algorithm']['duration_ms']);
        return $result;
    }

    public static function testARequestNamingDoorsInEitherOrderGivesOneAnswer(): void
    {
        self::assertEquals(
            self::withoutWallClock(ArrayCodec::pack(self::request(['-x', '+z']))),
            self::withoutWallClock(ArrayCodec::pack(self::request(['+z', '-x']))));
    }

    /** `[]` is a caller saying "no doors stated", not a malformed request; it has to
     * behave exactly like the absent field or the two spellings of the default diverge. */
    public static function testAnEmptyDoorListIsAcceptedAndInert(): void
    {
        self::assertEquals(
            self::withoutWallClock(ArrayCodec::pack(self::request([]))),
            self::withoutWallClock(ArrayCodec::pack(self::request(null))));
    }
}
