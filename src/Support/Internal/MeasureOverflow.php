<?php
declare(strict_types=1);
namespace Packvium\Support\Internal;

use Packvium\Unit\Length;
use Packvium\Unit\Weight;
use Packvium\Support\{BigInt,JsonValue,MeasureReading,RequestRules};

/**
 * `MeasureReading`'s exact reading of a measure the native parser overflowed on,
 * kept in its own file so PHP compiles it only for such a measure (1.9.0 release frontier).
 *
 * @internal
 */
final class MeasureOverflow
{
    /** The unit suffixes `Length::parse` and `Weight::parse` split off a string measure. */
    private const SUFFIXES = [
        Length::class => '/^(.+?)\s*(mm|cm|m|in|inch|inches|ft|ticks?)$/i',
        Weight::class => '/^(.+?)\s*(mg|kg|g|oz|lbs?|ticks?)$/i',
    ];

    /**
     * The verdict on text the native parser overflowed on, read exactly: negative, past the
     * tick ceiling of its kind, or -- a vanishing fraction with a vast denominator -- still
     * unreadable here.
     * Ticks round to nearest, so they pass `MAX` once `n x m / d >= MAX + 1/2`; `MAX` is odd,
     * so the tie itself rounds up to even. O(digits^2).
     *
     * @param int|string|array{value:int|string,unit?:string} $normalised
     * @param class-string<Length>|class-string<Weight> $kind
     */
    public static function beyondNativeInteger($normalised, string $kind, string $unit): string
    {
        $text = \is_array($normalised) ? (string) $normalised['value'] : (string) $normalised;
        $unit = \is_array($normalised) ? ($normalised['unit'] ?? $unit) : $unit;
        if (\is_string($normalised) && \preg_match(self::SUFFIXES[$kind], \trim($normalised), $m)) {
            [$text, $unit] = [\trim($m[1]), $m[2]];
        }
        $fraction = self::exactFraction(\trim(\str_replace("\u{00A0}", ' ', $text)));
        if ($fraction === null || $fraction[1] === '0') {
            return MeasureReading::UNREADABLE;
        }
        [$negative, $numerator, $denominator] = $fraction;
        if ($negative) {
            return MeasureReading::NEGATIVE;
        }
        $multiplier = $kind::multiplier($unit);
        $doubled = BigInt::multiply(BigInt::multiply($numerator, $multiplier), 2);
        $ceiling = $kind === Weight::class ? RequestRules::MAX_WEIGHT_TICKS : JsonValue::MAX_EXACT_MAGNITUDE;
        $threshold = BigInt::multiply($denominator, BigInt::add(BigInt::multiply($ceiling, 2), '1'));
        return BigInt::compare($doubled, $threshold) >= 0 ? MeasureReading::TOO_LARGE : MeasureReading::UNREADABLE;
    }

    /**
     * `RationalParser::parse` over decimal strings: an integer, decimal, fraction or mixed
     * fraction as its sign, numerator and denominator, or null for any other text.
     *
     * @return array{0:bool,1:string,2:string}|null
     */
    private static function exactFraction(string $text): ?array
    {
        $negative = \str_starts_with($text, '-');
        if (\preg_match('/^[+-]?(\d+)\s+(\d+)\s*\/\s*(\d+)$/', $text, $m)) {
            return self::signed($negative, BigInt::add(BigInt::multiply(self::digits($m[1]), self::digits($m[3])), self::digits($m[2])), $m[3]);
        }
        if (\preg_match('/^[+-]?(\d+)\s*\/\s*(\d+)$/', $text, $m)) {
            return self::signed($negative, $m[1], $m[2]);
        }
        if (\preg_match('/^[+-]?(\d+)(?:\.(\d+))?$/', $text, $m)) {
            $fraction = $m[2] ?? '';
            return self::signed($negative, $m[1] . $fraction, '1' . \str_repeat('0', \strlen($fraction)));
        }
        return null;
    }

    /** @return array{0:bool,1:string,2:string}|null */
    private static function signed(bool $negative, string $numerator, string $denominator): ?array
    {
        $numerator = self::digits($numerator);
        $denominator = self::digits($denominator);
        if ($denominator === '0') {
            return null;
        }
        return [$negative && $numerator !== '0', $numerator, $denominator];
    }

    private static function digits(string $digits): string
    {
        return \ltrim($digits, '0') ?: '0';
    }
}
