<?php
declare(strict_types=1);
namespace Packvium\Domain;

use InvalidArgumentException;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;

/**
 * Occupied height of a `compressible` item under the load resting on it.
 *
 * The model is fixed by docs/IRREGULAR-ITEMS.md. Pressure is an exact reduced rational
 * carried as a numerator and a denominator rather than a float: the crush limit is a
 * comparison, which cross multiplication answers without dividing at all, and the boundary is
 * inclusive, so a value one part in a million over the limit has to land on the right side
 * of it.
 *
 * The numerator reaches `load * 980665 * 16000000^2`, far past a 64-bit integer for any real
 * load, so it is carried as a decimal string. Only the comparison and the final height are
 * ever needed, and both come back to native integers.
 */
final class Compression
{
    /** Parts per million, the scale `compression_ratio` is carried at once parsed. */
    public const PPM = 1000000;

    /** Conventional standard gravity, exactly 9.80665 m/s^2, as a rational. */
    private const GRAVITY_NUMERATOR = 980665;
    private const GRAVITY_DENOMINATOR = 100000;

    private const PASCALS_PER_KILOPASCAL = 1000;

    /**
     * Exact pressure in kPa from the cumulative mass above an item, over its footprint.
     *
     * The item's own mass is excluded -- it is not a load on itself -- and the footprint is
     * the uncompressed one, which compression never changes.
     *
     * @return array{string,string} numerator and denominator, both non-negative
     */
    public static function pressure(int $topLoadTicks, int $footprintAreaTicks): array
    {
        if ($footprintAreaTicks <= 0) {
            throw new InvalidArgumentException('footprint area must be positive');
        }
        $metre = Length::TICKS_PER_MM * 1000;
        $numerator = \Packvium\Support\BigInt::multiply(
            \Packvium\Support\BigInt::multiply((string)$topLoadTicks, (string)self::GRAVITY_NUMERATOR),
            \Packvium\Support\BigInt::multiply((string)$metre, (string)$metre),
        );
        $denominator = \Packvium\Support\BigInt::multiply(
            \Packvium\Support\BigInt::multiply((string)Weight::TICKS_PER_KG, (string)self::GRAVITY_DENOMINATOR),
            \Packvium\Support\BigInt::multiply((string)self::PASCALS_PER_KILOPASCAL, (string)$footprintAreaTicks),
        );
        return [$numerator, $denominator];
    }

    /**
     * Is the applied pressure strictly above the declared limit?
     *
     * Cross multiplication, so the comparison never leaves exact integer arithmetic and no
     * division rounds the boundary in either direction.
     *
     * @param array{string,string} $pressure
     */
    public static function exceeds(array $pressure, int $limitKilopascals): bool
    {
        [$numerator, $denominator] = $pressure;
        $ceiling = \Packvium\Support\BigInt::multiply((string)$limitKilopascals, $denominator);
        return \Packvium\Support\BigInt::compare($numerator, $ceiling) > 0;
    }

    /**
     * Occupied height under load, rounded up, never below one tick.
     *
     * Rounding up keeps a discrete packer honest: it may never claim less space than the
     * continuous model allows. The one-tick floor stops a fully compressible item reaching
     * zero height, where it would slip past collision and support invariants entirely rather
     * than merely occupying very little.
     *
     * @param array{string,string} $pressure
     */
    public static function effectiveHeight(
        int $heightTicks,
        int $ratioPpm,
        int $limitKilopascals,
        array $pressure,
    ): int {
        if ($heightTicks <= 0) {
            throw new InvalidArgumentException('height must be positive');
        }
        if ($ratioPpm < 0 || $ratioPpm > self::PPM) {
            throw new InvalidArgumentException('compression ratio must be between zero and one');
        }
        if ($limitKilopascals < 0) {
            throw new InvalidArgumentException('maximum pressure cannot be negative');
        }
        // With no headroom declared the only admissible pressure is zero, so the item is
        // simply uncompressed. Returning here also keeps the divisor below non-zero.
        if ($limitKilopascals === 0) {
            return $heightTicks;
        }
        [$numerator, $denominator] = $pressure;
        $divisor = \Packvium\Support\BigInt::multiply(
            \Packvium\Support\BigInt::multiply((string)$limitKilopascals, (string)self::PPM),
            $denominator,
        );
        // Non-negative: the crush guard bounds the numerator by `limit * denominator` and the
        // ratio by PPM, so the product cannot exceed the divisor.
        $retained = \Packvium\Support\BigInt::subtract(
            $divisor,
            \Packvium\Support\BigInt::multiply((string)$ratioPpm, $numerator),
        );
        $scaled = \Packvium\Support\BigInt::multiply((string)$heightTicks, $retained);
        $rounded = \Packvium\Support\BigInt::divide(
            \Packvium\Support\BigInt::add($scaled, \Packvium\Support\BigInt::subtract($divisor, '1')),
            $divisor,
        );
        return max(1, (int)$rounded);
    }

    /**
     * The published ratio rule, `floor(ratio * 1000000 + 0.5)`, applied once at the boundary.
     *
     * Applied once and here, so the float a caller supplied never reaches the geometry.
     */
    public static function ratioToPpm(float $ratio): int
    {
        if ($ratio < 0.0 || $ratio > 1.0) {
            throw new InvalidArgumentException('compression_ratio must be between zero and one');
        }
        return (int)($ratio * self::PPM + 0.5);
    }
}
