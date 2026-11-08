<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Support\MeasureReading;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;

/**
 * A measure past a native int is read exactly, to tell a negative one from one past the
 * admission ceiling. Python reads every one of these without overflowing; each verdict here is
 * the one its request check gives.
 */
final class MeasureReadingTest extends TestCase
{
    private const VAST = '10000000000000000000000000000000000000000';

    public static function testAVastLengthIsPastTheCeilingAndANegativeOneIsNegative(): void
    {
        self::assertSame(MeasureReading::TOO_LARGE, MeasureReading::verdict(self::VAST, Length::class, 'mm'));
        self::assertSame(MeasureReading::NEGATIVE, MeasureReading::verdict('-' . self::VAST, Length::class, 'mm'));
        self::assertSame(MeasureReading::NEGATIVE, MeasureReading::verdict('-' . self::VAST, Weight::class, 'g'));
    }

    public static function testAMixedFractionWhoseWholePartIsTheSmallestIntIsNegative(): void
    {
        // abs(PHP_INT_MIN) is a float equal to PHP_INT_MAX: it once passed the range check and
        // failed later as a type error, read as unreadable where Python says negative.
        foreach (['-9223372036854775808 0/1', '-9223372036854775808 1/2'] as $text) {
            self::assertSame(MeasureReading::NEGATIVE, MeasureReading::verdict($text, Length::class, 'ticks'), $text);
            self::assertSame(MeasureReading::NEGATIVE, MeasureReading::verdict($text, Weight::class, 'g'), $text);
        }
    }

    public static function testEverySpellingOfAVastLengthIsReadExactly(): void
    {
        foreach (['100000000000000000000 1/2', '922337203685477.5807', self::VAST . '/3', self::VAST . ' cm', self::VAST . 'ticks'] as $text) {
            self::assertSame(MeasureReading::TOO_LARGE, MeasureReading::verdict($text, Length::class, 'mm'), $text);
        }
        self::assertSame(MeasureReading::TOO_LARGE, MeasureReading::verdict(['value' => self::VAST, 'unit' => 'ticks'], Length::class, 'mm'));
    }

    public static function testAVanishingFractionIsNotPastTheCeiling(): void
    {
        foreach (['1/' . self::VAST, '0/' . self::VAST, self::VAST . '/0', '1/' . self::VAST . ' m'] as $text) {
            self::assertSame(MeasureReading::UNREADABLE, MeasureReading::verdict($text, Length::class, 'mm'), $text);
        }
    }

    public static function testAWholeNumberPastTheLargestExactIntegerIsWholeHoweverItWasDecoded(): void
    {
        self::assertTrue(MeasureReading::isWholeBeyondExact(9_007_199_254_740_992));
        self::assertTrue(MeasureReading::isWholeBeyondExact(1e20));
        self::assertTrue(MeasureReading::isWholeBeyondExact(-1e20));
        self::assertFalse(MeasureReading::isWholeBeyondExact(9_007_199_254_740_991));
        self::assertFalse(MeasureReading::isWholeBeyondExact('100000000000000000000'));
        self::assertFalse(MeasureReading::isWholeBeyondExact(1.5));
        self::assertFalse(MeasureReading::isWholeBeyondExact(\INF));
    }
}
