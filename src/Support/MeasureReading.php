<?php
declare(strict_types=1);
namespace Packvium\Support;

use ArithmeticError;
use InvalidArgumentException;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;
use TypeError;
use ValueError;

/**
 * Whether a decoded JSON value reads as a length or weight, judged the way
 * `Length.parse` / `Weight.parse` judge it in the reference.
 *
 * The unit parsers take `int|string|array` and cast an object's `value` to a string, so a
 * float, a nested array or a null would reach them as a `TypeError` or an "Array to string"
 * warning. Everything is normalised here first, so a refusal is always a verdict and never a
 * notice on stdout. O(length of the value's text).
 */
final class MeasureReading
{
    public const READABLE = 'readable';
    public const NEGATIVE = 'negative';
    public const UNREADABLE = 'unreadable';

    /**
     * @param mixed $value
     * @param class-string<Length>|class-string<Weight> $kind
     * @return self::READABLE|self::NEGATIVE|self::UNREADABLE
     */
    public static function verdict($value, string $kind, string $unit): string
    {
        $normalised = self::normalised($value);
        if ($normalised === null) {
            return self::UNREADABLE;
        }
        try {
            $kind::parse($normalised, $unit);
        } catch (InvalidArgumentException $error) {
            return \str_contains($error->getMessage(), 'cannot be negative') ? self::NEGATIVE : self::UNREADABLE;
        } catch (TypeError | ValueError | ArithmeticError $error) {
            return self::UNREADABLE;
        }
        return self::READABLE;
    }

    /** @param class-string<Length>|class-string<Weight> $kind */
    public static function knowsUnit(string $kind, string $unit): bool
    {
        try {
            $kind::multiplier($unit);
        } catch (InvalidArgumentException $error) {
            return false;
        }
        return true;
    }

    /**
     * What the parser can be handed, or null when the reference could not read it either.
     *
     * @param mixed $value
     * @return int|string|array{value:int|string,unit?:string}|null
     */
    private static function normalised($value)
    {
        if (\is_int($value) || \is_string($value)) {
            return $value;
        }
        if (\is_float($value)) {
            // An integral float is the integer JavaScript sends for `100`; a fraction in
            // binary floating point is not the decimal the caller wrote.
            return JsonValue::integer($value);
        }
        if (!JsonValue::isObject($value) || !JsonValue::has($value, 'value')) {
            return null;
        }
        $amount = self::amount(JsonValue::get($value, 'value'));
        if ($amount === null) {
            return null;
        }
        if (!JsonValue::has($value, 'unit')) {
            return ['value' => $amount];
        }
        $unit = JsonValue::get($value, 'unit');
        return \is_string($unit) ? ['value' => $amount, 'unit' => $unit] : null;
    }

    /**
     * An object measure's `value`, which the reference reads through `str()`: a boolean is the
     * integer it is in Python, and a fractional float its shortest decimal text.
     *
     * @param mixed $amount
     * @return int|string|null
     */
    private static function amount($amount)
    {
        if (\is_int($amount) || \is_string($amount)) {
            return $amount;
        }
        if (\is_bool($amount)) {
            return (int) $amount;
        }
        if (\is_float($amount) && \is_finite($amount)) {
            return JsonValue::integer($amount) ?? (string) $amount;
        }
        return null;
    }
}
