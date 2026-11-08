<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Config\PackingConfig;
use Packvium\Domain\Container;
use Packvium\Domain\Dimensions;
use Packvium\Domain\Item;
use Packvium\Domain\PackingRequest;
use Packvium\Domain\RateTable;
use Packvium\Packer;
use Packvium\Result\PackingResult;
use Packvium\Serialization\ArrayCodec;
use Packvium\Serialization\InvalidRequestException;

use function Packvium\Validation\validate;

/**
 * 1.10: a request may declare its currency, a rate table may say which it prices in, and the
 * result echoes the request's. Nothing converts, prices or ranks by it (docs/SERIALIZATION.md).
 * Mirrors `packvium-python/tests/test_currency.py`.
 */
final class CurrencyTest extends TestCase
{
    private const THREE_LETTERS = 'must be three capital letters (ISO 4217)';

    public static function testTheResultEchoesTheRequestCurrencyOnTheAnswerAndEveryAlternative(): void
    {
        $request = self::jsonRequest('EUR', 'EUR');
        $request['configuration']['alternatives'] = 3;
        $result = ArrayCodec::pack($request);

        self::assertSame('EUR', $result['currency']);
        foreach ($result['alternatives'] as $alternative) {
            self::assertSame('EUR', $alternative['currency']);
        }
        self::assertSame([], validate($request, $result));
    }

    public static function testTheCurrencySitsBetweenTheCatalogVersionsAndTheWarnings(): void
    {
        $keys = \array_keys(ArrayCodec::pack(self::jsonRequest('EUR')));
        $at = \array_search('currency', $keys, true);

        self::assertSame(['catalog_versions_used', 'currency', 'warnings'], \array_slice($keys, (int) $at - 1, 3));
    }

    public static function testARequestWithoutACurrencyKeepsItsBytes(): void
    {
        self::assertFalse(\array_key_exists('currency', ArrayCodec::pack(self::jsonRequest())));
        self::assertFalse(\array_key_exists('currency', ArrayCodec::pack(['currency' => null] + self::jsonRequest())));
    }

    public static function testTheCurrencyChangesNothingButTheEcho(): void
    {
        $plain = ArrayCodec::pack(self::jsonRequest());
        $declared = ArrayCodec::pack(self::jsonRequest('JPY'));
        $plain['algorithm']['duration_ms'] = 0;
        $declared['algorithm']['duration_ms'] = 0;
        unset($declared['currency']);

        self::assertSame($plain, $declared);
    }

    public static function testBothPathsRefuseABadCurrencyAlike(): void
    {
        $cases = [
            [978, null, 'wrong_type', '/currency', 'must be a string'],
            ['usd', null, 'invalid_value', '/currency', self::THREE_LETTERS],
            ["USD\n", null, 'invalid_value', '/currency', self::THREE_LETTERS],
            ['ÜSD', null, 'invalid_value', '/currency', self::THREE_LETTERS],
            ['USD', 840, 'wrong_type', '/containers/0/rate_table/currency', 'must be a string'],
            ['USD', 'EURO', 'invalid_value', '/containers/0/rate_table/currency', self::THREE_LETTERS],
            [null, 'USD', 'missing_field', '/currency', 'is required when a rate_table declares a currency'],
            ['USD', 'EUR', 'not_allowed', '/containers/0/rate_table/currency', 'must equal the request currency "USD"'],
        ];
        foreach ($cases as [$currency, $table, $reason, $field, $detail]) {
            $expected = [$reason, $field, $detail];
            self::assertSame($expected, self::refusal(static fn() => ArrayCodec::pack(self::jsonRequest($currency, $table))));
            // A `?string` cannot hold a number: the object path has no such request to refuse.
            if (\is_int($currency) || \is_int($table)) {
                continue;
            }
            self::assertSame($expected, self::refusal(static fn() => self::packObjects($currency, $table)));
        }
    }

    public static function testARateTableMayLeaveItsCurrencyToTheRequest(): void
    {
        self::assertSame('USD', self::packObjects('USD')->currency);
        self::assertSame('USD', self::packObjects('USD', 'USD')->currency);
        self::assertNull(self::packObjects()->currency);
    }

    public static function testTheObjectApiCarriesTheCurrencyLastAndOptional(): void
    {
        self::assertNull((new RateTable([1000], [500]))->currency);
        $request = new PackingRequest([Item::create('cube', Dimensions::mm(1, 1, 1))], [Container::create('box', Dimensions::mm(2, 2, 2))]);
        self::assertNull($request->currency);
        self::assertSame('EUR', (new PackingRequest($request->items, $request->containers, [], 'EUR'))->currency);
    }

    /**
     * @param mixed $currency
     * @param mixed $table
     * @return array<string,mixed>
     */
    private static function jsonRequest($currency = null, $table = null): array
    {
        $request = [
            'items' => [['id' => 'cube', 'quantity' => 2, 'weight' => '1000',
                'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100']]],
            'containers' => [['id' => 'box', 'inner_dimensions' => ['length' => '200', 'width' => '100', 'height' => '200'],
                'rate_table' => ['weight_brackets_g' => [5000], 'prices_minor' => [900]]]],
            'configuration' => ['solver_profile' => 'fast'],
        ];
        if ($currency !== null) {
            $request['currency'] = $currency;
        }
        if ($table !== null) {
            $request['containers'][0]['rate_table']['currency'] = $table;
        }
        return $request;
    }

    private static function packObjects(?string $currency = null, ?string $table = null): PackingResult
    {
        $rates = new RateTable([5000], [900], currency: $table);
        $items = [Item::create('cube', Dimensions::mm(100, 100, 100), weight: 1000, quantity: 2)];
        $containers = [Container::create('box', Dimensions::mm(200, 100, 200), rateTable: $rates)];
        return (new Packer(PackingConfig::fast()))->pack($items, $containers, [], $currency);
    }

    /** @return array{0:string,1:string,2:string} */
    private static function refusal(callable $pack): array
    {
        try {
            $pack();
        } catch (InvalidRequestException $error) {
            return [$error->reason(), $error->field(), $error->detail()];
        }
        self::assertTrue(false, 'the request was answered');
        return ['', '', ''];
    }
}
