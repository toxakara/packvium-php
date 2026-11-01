<?php
declare(strict_types=1);

namespace Packvium\Tests;

use function Packvium\Commerce\quote;

final class CommerceTariffIdentityTest extends TestCase
{
    public static function testAnUnregisteredPairCannotUseAnotherPairsTariff(): void
    {
        $document = ['tariffs' => [self::history("a\0b", 'c', 100)]];
        foreach ([['tariff_version' => 1], ['as_of' => 0]] as $pin) {
            $result = quote($document, self::request('a', "b\0c") + $pin);

            self::assertSame('rejected', $result['status']);
            self::assertSame('tariff_not_found', $result['error']['code']);
            self::assertSame(
                ['carrier_id' => 'a', 'service_id' => "b\0c"] + (isset($pin['tariff_version']) ? $pin : []),
                $result['error']['fields'],
            );
        }
    }

    public static function testDistinctPairsHaveIndependentHistories(): void
    {
        $document = ['tariffs' => [
            self::history("a\0b", 'c', 100),
            self::history('a', "b\0c", 700),
        ]];
        foreach ([['tariff_version' => 1], ['as_of' => 0]] as $pin) {
            foreach ([["a\0b", 'c', 100], ['a', "b\0c", 700]] as [$carrier, $service, $total]) {
                $result = quote($document, self::request($carrier, $service) + $pin);

                self::assertSame('ok', $result['status']);
                self::assertSame($carrier, $result['quote']['carrier_id']);
                self::assertSame($service, $result['quote']['service_id']);
                self::assertSame(1, $result['quote']['tariff_version']);
                self::assertSame($total, $result['quote']['total_minor']);
            }
        }
    }

    /** @return array<string,mixed> */
    private static function history(string $carrier, string $service, int $rate): array
    {
        return ['carrier_id' => $carrier, 'service_id' => $service, 'versions' => [[
            'effective_at' => 0,
            'dimensional_weight_divisor' => 5000,
            'cost_per_dimensional_kg_minor' => ['z' => $rate],
        ]]];
    }

    /** @return array<string,mixed> */
    private static function request(string $carrier, string $service): array
    {
        return [
            'carrier_id' => $carrier, 'service_id' => $service, 'zone' => 'z',
            'actual_weight_g' => 1000, 'volume_mm3' => 0,
        ];
    }
}
