<?php
declare(strict_types=1);

namespace Packvium\Tests;

use InvalidArgumentException;
use Packvium\Unit\Length;
use Packvium\Unit\Rounding;
use Packvium\Unit\Weight;

/**
 * Exact fixed-point arithmetic — the foundation every other guarantee rests on.
 *
 * One length tick is 1/16000 mm and one weight tick is 1/8 microgram. These constants
 * and the rounding rules must match the Python implementation exactly; a boundary that
 * moved by one tick between the two would make the same order pack differently.
 * See docs/UNITS-AND-NUMERICS.md.
 */
final class UnitTest extends TestCase
{
    public static function testLengthTickDefinitions(): void
    {
        self::assertSame(16_000, Length::mm(1)->ticks);
        self::assertSame(160_000, Length::of(1, 'cm')->ticks);
        self::assertSame(16_000_000, Length::of(1, 'm')->ticks);
        self::assertSame(406_400, Length::inches(1)->ticks);
        self::assertSame(406_400 * 12, Length::of(1, 'ft')->ticks);
    }

    public static function testInchIsExactlyTwentyFivePointFourMillimetres(): void
    {
        self::assertSame(Length::mm('25.4')->ticks, Length::inches(1)->ticks);
    }

    public static function testWeightTickDefinitions(): void
    {
        self::assertSame(8_000, Weight::of(1, 'mg')->ticks);
        self::assertSame(8_000_000, Weight::of(1, 'g')->ticks);
        self::assertSame(8_000_000_000, Weight::of(1, 'kg')->ticks);
        self::assertSame(3_628_738_960, Weight::parse('1 lb')->ticks);
    }

    public static function testImperialWeightConversionsAreExact(): void
    {
        self::assertSame(Weight::of(1, 'lb')->ticks, Weight::of(16, 'oz')->ticks);
        self::assertSame(Weight::of('453.59237', 'g')->ticks, Weight::of(1, 'lb')->ticks);
        self::assertSame(Weight::of('28.349523125', 'g')->ticks, Weight::of(1, 'oz')->ticks);
    }

    public static function testInchNotations(): void
    {
        self::assertSame(406_400, Length::inches('1')->ticks);
        self::assertSame(203_200, Length::inches('1/2')->ticks);
        self::assertSame(3_175, Length::inches('1/128')->ticks);
        self::assertSame(5_029_200, Length::inches('12 3/8')->ticks);
        self::assertSame(203_200, Length::inches('0.5')->ticks);
        self::assertSame(406_400, Length::inches('+1')->ticks);
    }

    public static function testUnitSuffixInTheString(): void
    {
        self::assertSame(Length::inches(4)->ticks, Length::parse('4 in')->ticks);
        self::assertSame(Length::mm(100)->ticks, Length::parse('100mm')->ticks);
        self::assertSame(Length::of(2, 'ft')->ticks, Length::parse('2 FT')->ticks);
        self::assertSame(Weight::of(1, 'kg')->ticks, Weight::parse('1 kg')->ticks);
        self::assertSame(Weight::of(1, 'lb')->ticks, Weight::parse('1lbs')->ticks);
    }

    public static function testArrayForm(): void
    {
        self::assertSame(Length::inches(4)->ticks, Length::parse(['value' => '4', 'unit' => 'in'])->ticks);
        self::assertSame(Length::inches(4)->ticks, Length::parse(['value' => '4'], 'in')->ticks);
        self::assertSame(Weight::of(2, 'kg')->ticks, Weight::parse(['value' => '2', 'unit' => 'kg'])->ticks);
    }

    public static function testDefaultUnitAppliesToABareNumber(): void
    {
        self::assertSame(Length::mm(100)->ticks, Length::parse('100')->ticks);
        self::assertSame(Length::inches(100)->ticks, Length::parse('100', 'in')->ticks);
    }

    public static function testAlreadyParsedValuesPassThrough(): void
    {
        $length = Length::mm(10);
        self::assertSame($length, Length::parse($length));
    }

    public static function testRoundingModes(): void
    {
        self::assertSame(1, Length::of('1.4', 'tick', Rounding::Floor)->ticks);
        self::assertSame(2, Length::of('1.4', 'tick', Rounding::Ceil)->ticks);
        self::assertSame(1, Length::of('1.4', 'tick', Rounding::Nearest)->ticks);
    }

    public static function testNearestBreaksTiesToEven(): void
    {
        // Half-to-even, so a long run of .5 values does not drift upwards.
        self::assertSame(0, Length::of('0.5', 'tick')->ticks);
        self::assertSame(2, Length::of('1.5', 'tick')->ticks);
        self::assertSame(2, Length::of('2.5', 'tick')->ticks);
        self::assertSame(4, Length::of('3.5', 'tick')->ticks);
    }

    public static function testExactValuesAreUntouchedByEveryMode(): void
    {
        foreach (Rounding::cases() as $rounding) {
            self::assertSame(112_000, Length::mm(7, $rounding)->ticks);
        }
    }

    public static function testUnsupportedUnitsAreRejected(): void
    {
        self::assertThrows(InvalidArgumentException::class, static fn() => Length::of(1, 'furlong'));
        self::assertThrows(InvalidArgumentException::class, static fn() => Weight::of(1, 'stone'));
    }

    public static function testNegativeMeasuresAreRejected(): void
    {
        self::assertThrows(InvalidArgumentException::class, static fn() => new Length(-1));
        self::assertThrows(InvalidArgumentException::class, static fn() => new Weight(-1));
    }

    public static function testUnparseableTextIsRejected(): void
    {
        foreach (['', 'abc', '1 2 3', '--1', '1/2/3'] as $text) {
            self::assertThrows(InvalidArgumentException::class, static fn() => Length::mm($text), "accepted '{$text}'");
        }
    }

    public static function testDecimalRenderingIsExact(): void
    {
        self::assertSame('25.4', Length::inches(1)->decimal('mm'));
        self::assertSame('100', Length::mm(100)->decimal('mm'));
        self::assertSame('0.0625', Length::mm('0.0625')->decimal('mm'));
        self::assertSame('10', Length::mm(100)->decimal('cm'));
    }

    public static function testToArrayCarriesTheExactTicksAlongsideTheDisplayValue(): void
    {
        self::assertSame(['ticks' => 406_400, 'value' => '25.4', 'unit' => 'mm'], Length::inches(1)->toArray('mm'));
        self::assertSame(['ticks' => 8_000_000_000, 'value' => '1000', 'unit' => 'g'], Weight::of(1, 'kg')->toArray('g'));
    }
}
