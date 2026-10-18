<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

use Packvium\Validation\MalformedResult;

/**
 * Python's `Fraction`: always reduced, the denominator always positive, so `numerator` and
 * `denominator` read the same numbers the reference reads.
 */
final class Ratio
{
    /** @var int|string */
    public $numerator;
    /** @var int|string */
    public $denominator;

    /** @param int|string $numerator @param int|string $denominator */
    private function __construct($numerator, $denominator)
    {
        $this->numerator = $numerator;
        $this->denominator = $denominator;
    }

    /** @param int|string $numerator @param int|string $denominator */
    public static function of($numerator, $denominator): self
    {
        if (Exact::sign($denominator) === 0) {
            throw MalformedResult::shape('Fraction with a zero denominator');
        }
        $divisor = Exact::gcd($numerator, $denominator);
        if (Exact::sign($divisor) === 0) {
            $divisor = 1;
        }
        $numerator = Exact::floorDiv($numerator, $divisor);
        $denominator = Exact::floorDiv($denominator, $divisor);
        if (Exact::sign($denominator) < 0) {
            return new self(Exact::negate($numerator), Exact::negate($denominator));
        }
        return new self($numerator, $denominator);
    }

    /** @param int|string $value */
    public static function integer($value): self
    {
        return new self($value, 1);
    }

    public function add(self $other): self
    {
        return self::of(
            Exact::add(Exact::mul($this->numerator, $other->denominator), Exact::mul($other->numerator, $this->denominator)),
            Exact::mul($this->denominator, $other->denominator)
        );
    }

    public function mul(self $other): self
    {
        return self::of(Exact::mul($this->numerator, $other->numerator), Exact::mul($this->denominator, $other->denominator));
    }

    /** @param int|string $value */
    public function mulInt($value): self
    {
        return $this->mul(self::integer($value));
    }

    public function negate(): self
    {
        return new self(Exact::negate($this->numerator), $this->denominator);
    }

    public function compare(self $other): int
    {
        return Exact::compare(
            Exact::mul($this->numerator, $other->denominator),
            Exact::mul($other->numerator, $this->denominator)
        );
    }

    /** @param int|string $value */
    public function compareInt($value): int
    {
        return $this->compare(self::integer($value));
    }

    /** `numerator // denominator`. @return int|string */
    public function floor()
    {
        return Exact::floorDiv($this->numerator, $this->denominator);
    }

    /**
     * Python's `float(fraction)`: the nearest double, ties to even. The quotient is taken to 53
     * significant bits exactly and the remainder decides the last one.
     */
    public function toFloat(): float
    {
        $numerator = Exact::abs($this->numerator);
        if (Exact::sign($numerator) === 0) {
            return 0.0;
        }
        $denominator = $this->denominator;
        $limit = 1 << 53;
        if (\is_int($numerator) && \is_int($denominator) && $numerator <= $limit && $denominator <= $limit) {
            $value = $numerator / $denominator;
        } else {
            $value = self::nearestDouble($numerator, $denominator);
        }
        return Exact::sign($this->numerator) < 0 ? -$value : $value;
    }

    /** @param int|string $numerator @param int|string $denominator */
    private static function nearestDouble($numerator, $denominator): float
    {
        // Scale so the quotient has exactly 53 bits; the float estimate only picks the start.
        $shift = 52 - (int)\floor(\log((float)$numerator, 2) - \log((float)$denominator, 2));
        for (;;) {
            [$scaledNumerator, $scaledDenominator] = $shift >= 0
                ? [Exact::mul($numerator, Exact::powerOfTwo($shift)), $denominator]
                : [$numerator, Exact::mul($denominator, Exact::powerOfTwo(-$shift))];
            $quotient = Exact::floorDiv($scaledNumerator, $scaledDenominator);
            if (Exact::compare($quotient, 1 << 52) < 0) {
                ++$shift;
                continue;
            }
            if (Exact::compare($quotient, 1 << 53) >= 0) {
                --$shift;
                continue;
            }
            break;
        }
        $remainder = Exact::sub($scaledNumerator, Exact::mul($quotient, $scaledDenominator));
        $order = Exact::compare(Exact::mul($remainder, 2), $scaledDenominator);
        if ($order > 0 || ($order === 0 && $quotient % 2 === 1)) {
            ++$quotient;
            if ($quotient === 1 << 53) {
                $quotient = 1 << 52;
                --$shift;
            }
        }
        return (float)$quotient * \pow(2.0, -$shift);
    }
}
