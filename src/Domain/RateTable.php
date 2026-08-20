<?php
declare(strict_types=1);
namespace Packvium\Domain;

use InvalidArgumentException;

/**
 * A carrier rate card carried by the request rather than by a code plugin.
 *
 * `$weightBracketsG` is strictly ascending and the same length as `$pricesMinor`; the
 * price charged is the one at the first bracket at or above the billed weight, which is
 * how a carrier's own published table reads. Everything is an exact integer -- grams,
 * minor currency units, parts per thousand -- because a landed cost that participates in
 * the objective must be reproducible bit for bit across four engines, and a float is not.
 *
 * A price is *not* required to be non-decreasing. A promotional band that dips is a real
 * rate card, and the table is read by bracket rather than by comparing prices, so a dip
 * prices correctly instead of being rejected as malformed.
 */
final class RateTable
{
    /**
     * @param list<int> $weightBracketsG
     * @param list<int> $pricesMinor
     */
    public function __construct(
        public readonly array $weightBracketsG,
        public readonly array $pricesMinor,
        public readonly int $minimumChargeMinor = 0,
        public readonly int $fuelSurchargePermille = 0,
    ) {
        if ($weightBracketsG === []) {
            throw new InvalidArgumentException('rate_table requires at least one weight bracket');
        }
        if (count($weightBracketsG) !== count($pricesMinor)) {
            throw new InvalidArgumentException('rate_table weight_brackets_g and prices_minor must be the same length');
        }
        $previous = 0;
        foreach ($weightBracketsG as $bound) {
            if ($bound <= 0) {
                throw new InvalidArgumentException('rate_table weight brackets must be positive');
            }
            if ($bound <= $previous) {
                throw new InvalidArgumentException('rate_table weight brackets must be strictly ascending');
            }
            $previous = $bound;
        }
        foreach ($pricesMinor as $price) {
            if ($price < 0) {
                throw new InvalidArgumentException('rate_table prices cannot be negative');
            }
        }
        if ($minimumChargeMinor < 0) {
            throw new InvalidArgumentException('rate_table minimum_charge_minor cannot be negative');
        }
        if ($fuelSurchargePermille < 0) {
            throw new InvalidArgumentException('rate_table fuel_surcharge_permille cannot be negative');
        }
    }

    /**
     * The exact landed cost of one shipment at this billed weight, or null when the tariff
     * does not price it.
     *
     * The search needs to *compare* an unpriceable candidate rather than abort on one: a
     * container whose tariff runs out at this weight must lose to one that can price the
     * load, which it cannot do if asking the question throws. `chargeMinor` is the same
     * walk for callers who want the refusal.
     */
    public function chargeMinorOrNull(int $billedWeightG): ?int
    {
        foreach ($this->weightBracketsG as $index => $bound) {
            if ($billedWeightG <= $bound) {
                $base = max($this->pricesMinor[$index], $this->minimumChargeMinor);
                // Ceiling division, never a float: the surcharge is a share of the base,
                // and rounding down would let a fractional unit of revenue vanish.
                $surcharge = intdiv($base * $this->fuelSurchargePermille + 999, 1000);
                return $base + $surcharge;
            }
        }
        return null;
    }

    /**
     * The exact landed cost of one shipment at this billed weight, in minor units.
     *
     * Throws above the last bracket rather than clamping to the top price. Clamping would
     * quietly under-price every oversize shipment and, worse, make the objective prefer a
     * packing the caller cannot actually ship at that price.
     */
    public function chargeMinor(int $billedWeightG): int
    {
        $charge = $this->chargeMinorOrNull($billedWeightG);
        if ($charge === null) {
            $last = $this->weightBracketsG[count($this->weightBracketsG) - 1];
            throw new UnratedWeightException(
                "billed weight {$billedWeightG} g is above the rate table's last bracket "
                . "({$last} g); the shipment has no published price"
            );
        }

        return $charge;
    }

    /** The highest billed weight this table prices, in grams. */
    public function lastBracketG(): int
    {
        return $this->weightBracketsG[count($this->weightBracketsG) - 1];
    }
}
