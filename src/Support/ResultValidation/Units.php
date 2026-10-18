<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

use Packvium\Validation\MalformedResult;

/**
 * Measures to exact ticks, re-derived from the unit tables rather than taken from the engine:
 * a validator built on the engine's unit parser could not catch a bug in it.
 */
final class Units
{
    /** Length ticks per unit, in the reference's order. One tick is 1/16000 mm. */
    public const LENGTH = [
        'mm' => 16000, 'cm' => 160000, 'm' => 16000000, 'in' => 406400, 'inch' => 406400,
        'inches' => 406400, 'ft' => 4876800, 'tick' => 1, 'ticks' => 1,
    ];

    /** Weight ticks per unit. One tick is 1/8 microgram. */
    public const WEIGHT = [
        'mg' => 8000, 'g' => 8000000, 'kg' => 8000000000, 'oz' => 226796185,
        'lb' => 3628738960, 'lbs' => 3628738960, 'tick' => 1, 'ticks' => 1,
    ];

    public const TICKS_PER_MM = 16000;
    public const TICKS_PER_GRAM = 8000000;
    public const TICKS_PER_KG = 8000000000;

    /** `table[unit]`, an exact key. @param array<string,int> $table */
    public static function perUnit(array $table, string $unit): int
    {
        if (!\array_key_exists($unit, $table)) {
            throw MalformedResult::shape(\sprintf('unknown unit "%s"', $unit));
        }
        return $table[$unit];
    }

    /**
     * A measure (a number, a string with an optional unit suffix, or `{value, unit}`) to exact
     * ticks, rounding halves to even.
     *
     * @param mixed $measure @param array<string,int> $table @return int|string
     */
    public static function toTicks($measure, string $defaultUnit, array $table)
    {
        $unit = $defaultUnit;
        $raw = $measure;
        if (Json::isDict($measure) && $measure !== []) {
            $raw = Json::at($measure, 'value');
            if (\array_key_exists('unit', $measure)) {
                $unit = Json::text($measure['unit'], "a measure's unit");
            }
        } elseif (\is_string($measure)) {
            $suffixed = self::unitSuffix(self::strip($measure), $table);
            if ($suffixed !== null) {
                [$raw, $unit] = $suffixed;
            }
        }
        $scaled = self::parseRational($raw)->mulInt(self::perUnit($table, \strtolower($unit)));
        return self::roundHalfEven($scaled);
    }

    /**
     * The longest unit suffix the text ends with, case-insensitively, that leaves a non-empty
     * number before it; ties in length go to the table's earlier entry, as Python's stable sort
     * over the table does.
     *
     * @param array<string,int> $table @return array{0:string,1:string}|null
     */
    private static function unitSuffix(string $stripped, array $table): ?array
    {
        $suffixes = \array_keys($table);
        $order = \array_flip($suffixes);
        \usort($suffixes, static function (string $a, string $b) use ($order): int {
            return [\strlen($b), $order[$a]] <=> [\strlen($a), $order[$b]];
        });
        $lowered = \strtolower($stripped);
        foreach ($suffixes as $suffix) {
            $length = \strlen($suffix);
            if (\strlen($lowered) < $length || \substr($lowered, -$length) !== $suffix) {
                continue;
            }
            $number = self::strip(\substr($stripped, 0, -$length));
            if ($number !== '') {
                return [$number, $suffix];
            }
        }
        return null;
    }

    /** @return int|string */
    private static function roundHalfEven(Ratio $scaled)
    {
        $floor = $scaled->floor();
        $remainder = Ratio::of(Exact::sub($scaled->numerator, Exact::mul($floor, $scaled->denominator)), $scaled->denominator);
        $order = $remainder->compare(Ratio::of(1, 2));
        if ($order > 0) {
            return Exact::add($floor, 1);
        }
        if ($order < 0) {
            return $floor;
        }
        return Exact::mod($floor, 2) === 0 ? $floor : Exact::add($floor, 1);
    }

    /**
     * `parse_rational`: an integer exactly, anything else through its `str()` as a fraction, a
     * mixed fraction (`12 3/8`) when the text holds a space.
     *
     * @param mixed $value
     */
    public static function parseRational($value): Ratio
    {
        $integer = Json::intInstance($value);
        if ($integer !== null) {
            return Ratio::integer($integer);
        }
        $stripped = self::strip(PyFormat::str($value));
        if (\strpos($stripped, ' ') === false) {
            return self::parseFraction($stripped);
        }
        $parts = \preg_split('/[\s\p{Z}]+/u', $stripped, 2);
        $fraction = self::parseFraction($parts[1] ?? '');
        if (\strncmp($parts[0], '-', 1) === 0) {
            $fraction = $fraction->negate();
        }
        return self::parseFraction($parts[0])->add($fraction);
    }

    /** `Fraction(str(value))`, the reference's reading of a ratio field. @param mixed $value */
    public static function ratioOf($value): Ratio
    {
        return self::parseFraction(PyFormat::str($value));
    }

    /**
     * `Fraction(text)` by the grammar of CPython 3.11's `fractions._RATIONAL_FORMAT`: optional
     * whitespace and sign, then `n/d`, or a decimal with an optional exponent; digit groups may
     * be split by single underscores. Only ASCII digits are read.
     */
    public static function parseFraction(string $text): Ratio
    {
        $digits = '\d+(?:_\d+)*';
        $pattern = "/^[\\s\\p{Z}]*([-+]?)(?=\\d|\\.\\d)({$digits})?(?:\\/({$digits})|(?:\\.({$digits})?)?(?:[eE]([-+]?{$digits}))?)[\\s\\p{Z}]*$/u";
        if (\preg_match($pattern, $text, $parts) !== 1) {
            throw MalformedResult::shape(\sprintf('Invalid literal for Fraction: %s', PyFormat::stringRepr($text)));
        }
        $clean = static function (string $group) {
            return Exact::normalize(\ltrim(\str_replace('_', '', $group), '0') ?: '0');
        };
        $numerator = $clean($parts[2] ?? '');
        $denominator = 1;
        if (($parts[3] ?? '') !== '') {
            $denominator = $clean($parts[3]);
        } else {
            $decimals = \str_replace('_', '', $parts[4] ?? '');
            if ($decimals !== '') {
                $scale = Exact::normalize('1' . \str_repeat('0', \strlen($decimals)));
                $numerator = Exact::add(Exact::mul($numerator, $scale), $clean($decimals));
                $denominator = $scale;
            }
            $exponent = $parts[5] ?? '';
            if ($exponent !== '') {
                $power = (int)\str_replace(['_', '+'], '', $exponent);
                if (\abs($power) > 400) {
                    throw MalformedResult::shape('arithmetic overflow: an exponent beyond what this validator computes');
                }
                $factor = Exact::normalize('1' . \str_repeat('0', \abs($power)));
                if ($power < 0) {
                    $denominator = Exact::mul($denominator, $factor);
                } else {
                    $numerator = Exact::mul($numerator, $factor);
                }
            }
        }
        if ($parts[1] === '-') {
            $numerator = Exact::negate($numerator);
        }
        return Ratio::of($numerator, $denominator);
    }

    /** Python's `str.strip()`: Unicode whitespace from both ends. */
    public static function strip(string $text): string
    {
        return \preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $text) ?? $text;
    }

    /** @param mixed $value @return array{0:int|string,1:int|string,2:int|string} */
    public static function dimensions($value, string $unit): array
    {
        return [
            self::toTicks(Json::at($value, 'length'), $unit, self::LENGTH),
            self::toTicks(Json::at($value, 'width'), $unit, self::LENGTH),
            self::toTicks(Json::at($value, 'height'), $unit, self::LENGTH),
        ];
    }
}
