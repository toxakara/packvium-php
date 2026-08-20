<?php
declare(strict_types=1);
namespace Packvium\Commerce\Rating;

use Packvium\Support\Arithmetic;

/**
 * Per-(carrier_id, service_id) append-only tariff history, and the rating itself.
 *
 * This module computes a rate breakdown from first-party tariff data a caller publishes
 * into the registry -- it does not fetch, scrape or embed any real carrier's published
 * rates. Complexity: publish O(1); every resolution is one O(h) scan of that pair's
 * history; rating one request is O(a) in requested accessorials.
 */
final class CarrierRegistry
{
    /** @var array<string,list<Tariff>> */
    private array $versions = [];

    /**
     * @param array<string,int>               $costPerDimensionalKgMinor
     * @param array<string,AccessorialCharge> $accessorials
     */
    public function publish(
        string $carrierId,
        string $serviceId,
        int $effectiveAt,
        int $dimensionalWeightDivisor,
        array $costPerDimensionalKgMinor,
        int $minimumChargeMinor = 0,
        int $fuelSurchargePermille = 0,
        array $accessorials = [],
    ): Tariff {
        $key = self::key($carrierId, $serviceId);
        $history = $this->versions[$key] ?? [];
        $tariff = new Tariff(
            $carrierId, $serviceId, count($history) + 1, $effectiveAt, $dimensionalWeightDivisor,
            $costPerDimensionalKgMinor, $minimumChargeMinor, $fuelSurchargePermille, $accessorials,
        );
        $history[] = $tariff;
        $this->versions[$key] = $history;
        return $tariff;
    }

    /** @return list<Tariff> */
    public function versions(string $carrierId, string $serviceId): array
    {
        $key = self::key($carrierId, $serviceId);
        if (!isset($this->versions[$key])) {
            throw new TariffNotFoundException("no tariff registered for {$carrierId}/{$serviceId}");
        }
        return $this->versions[$key];
    }

    public function tariff(string $carrierId, string $serviceId, int $version): Tariff
    {
        foreach ($this->versions($carrierId, $serviceId) as $tariff) {
            if ($tariff->version === $version) { return $tariff; }
        }
        throw new TariffNotFoundException("{$carrierId}/{$serviceId} has no version {$version}");
    }

    /**
     * The version effective at $asOf: the highest effective_at not after it, ties broken
     * by the higher (later-published) version number.
     */
    public function effectiveTariff(string $carrierId, string $serviceId, int $asOf): Tariff
    {
        $winner = null;
        foreach ($this->versions($carrierId, $serviceId) as $tariff) {
            if ($tariff->effectiveAt > $asOf) { continue; }
            if ($winner === null
                || $tariff->effectiveAt > $winner->effectiveAt
                || ($tariff->effectiveAt === $winner->effectiveAt && $tariff->version > $winner->version)) {
                $winner = $tariff;
            }
        }
        if ($winner === null) {
            throw new TariffNotFoundException("{$carrierId}/{$serviceId} has no tariff version effective as of {$asOf}");
        }
        return $winner;
    }

    public function rate(string $carrierId, string $serviceId, RatingRequest $request, int $asOf): RateBreakdown
    {
        return self::rateTariff($this->effectiveTariff($carrierId, $serviceId, $asOf), $request);
    }

    /**
     * The deterministic-offline-replay path: the same (carrier, service, version) and
     * the same request always reproduce the identical breakdown, whatever the history
     * has grown to since.
     */
    public function rateWithVersion(string $carrierId, string $serviceId, int $version, RatingRequest $request): RateBreakdown
    {
        return self::rateTariff($this->tariff($carrierId, $serviceId, $version), $request);
    }

    /** Rate a request against one already-resolved immutable tariff version. */
    public static function rateTariff(Tariff $tariff, RatingRequest $request): RateBreakdown
    {
        if (!array_key_exists($request->zone, $tariff->costPerDimensionalKgMinor)) {
            throw new UnavailableServiceException(
                "tariff {$tariff->carrierId}/{$tariff->serviceId} v{$tariff->version} has no rate for zone '{$request->zone}'",
                zone: $request->zone,
            );
        }
        $unknown = array_values(array_diff($request->requestedAccessorials, array_keys($tariff->accessorials)));
        if ($unknown !== []) {
            sort($unknown, SORT_STRING);
            throw new UnavailableServiceException(
                "tariff {$tariff->carrierId}/{$tariff->serviceId} v{$tariff->version} does not offer requested accessorial(s)",
                accessorialIds: $unknown,
            );
        }

        // 1 mm^3 of volume divided by the tariff's divisor gives dimensional weight in
        // grams, rounded up -- never down, never through a float.
        $dimensionalWeightG = Arithmetic::mulDivCeil($request->volumeMm3, 1, $tariff->dimensionalWeightDivisor);
        $billedWeightG = max($request->actualWeightG, $dimensionalWeightG);

        $rawBaseChargeMinor = Arithmetic::mulDivCeil($billedWeightG, $tariff->costPerDimensionalKgMinor[$request->zone], 1000);
        $minimumApplied = $rawBaseChargeMinor < $tariff->minimumChargeMinor;
        $baseChargeMinor = $minimumApplied ? $tariff->minimumChargeMinor : $rawBaseChargeMinor;

        $fuelSurchargeMinor = Arithmetic::mulDivCeil($baseChargeMinor, $tariff->fuelSurchargePermille, 1000);

        $accessorialCharges = [];
        $accessorialTotal = 0;
        foreach ($request->requestedAccessorials as $accessorialId) {
            $amount = $tariff->accessorials[$accessorialId]->chargeMinor($baseChargeMinor);
            $accessorialCharges[] = [$accessorialId, $amount];
            $accessorialTotal += $amount;
        }

        return new RateBreakdown(
            $tariff->carrierId, $tariff->serviceId, $tariff->version, $request->zone,
            $request->actualWeightG, $dimensionalWeightG, $billedWeightG, $baseChargeMinor,
            $minimumApplied, $fuelSurchargeMinor, $accessorialCharges,
            $baseChargeMinor + $fuelSurchargeMinor + $accessorialTotal,
        );
    }

    private static function key(string $carrierId, string $serviceId): string
    {
        return $carrierId . "\0" . $serviceId;
    }
}
