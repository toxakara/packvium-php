<?php
declare(strict_types=1);

namespace Packvium\Tests;

use InvalidArgumentException;
use Packvium\Serialization\ArrayCodec;
use Packvium\Serialization\InvalidRequestException;
use Packvium\Serialization\UnsupportedFeatureException;
use Packvium\Support\RequestRules;
use Packvium\Validation\FixedPlacementException;
use RuntimeException;

/**
 * Structured request errors (docs/ERRORS.md). Mirrors `packvium-python/tests/test_request_errors.py`;
 * `conformance/request_errors/run.py` holds every case of the corpus to the reference, and
 * these pin the PHP-only concerns: the class hierarchy, the empty array standing for `{}`, and
 * what the fallback does and does not re-label.
 */
final class RequestErrorTest extends TestCase
{
    public static function testTheErrorCarriesItsCodeReasonFieldAndDetail(): void
    {
        $error = new InvalidRequestException('below_minimum', '/items/0/quantity', 'must be at least 1');
        self::assertTrue($error instanceof InvalidArgumentException);
        self::assertSame('invalid_request', $error->errorCode());
        self::assertSame('below_minimum', $error->reason());
        self::assertSame('/items/0/quantity', $error->field());
        self::assertSame('must be at least 1', $error->detail());
        self::assertSame('invalid_request: /items/0/quantity: must be at least 1', $error->getMessage());
        self::assertSame('invalid_request: must be an object',
            (new InvalidRequestException('wrong_type', '', 'must be an object'))->getMessage());
    }

    public static function testAFixedPlacementRefusalIsARequestErrorWithItsOwnCodeAndMessage(): void
    {
        $error = new FixedPlacementException('unknown item type "slab"');
        self::assertTrue($error instanceof InvalidRequestException);
        self::assertSame('invalid_fixed_placement', $error->errorCode());
        self::assertSame('cannot_hold', $error->reason());
        self::assertSame('/fixed_placements', $error->field());
        self::assertSame('invalid_fixed_placement: unknown item type "slab"', $error->getMessage());
    }

    public static function testEachRuleNamesTheValueItBroke(): void
    {
        $cases = [
            'items missing' => [static function (array &$r): void { unset($r['items']); },
                'missing_field', '/items', 'is required'],
            'item not an object' => [static function (array &$r): void { $r['items'][] = ['slab']; },
                'wrong_type', '/items/1', 'must be an object'],
            'quantity below its floor' => [static function (array &$r): void { $r['items'][0]['quantity'] = 0; },
                'below_minimum', '/items/0/quantity', 'must be at least 1'],
            'quantity past 2^53 - 1' => [static function (array &$r): void { $r['items'][0]['quantity'] = 9007199254740992; },
                'above_maximum', '/items/0/quantity', 'must be at most 9007199254740991'],
            'quantity past PHP_INT_MAX' => [static function (array &$r): void { $r['items'][0]['quantity'] = 1.0e19; },
                'above_maximum', '/items/0/quantity', 'must be at most 9007199254740991'],
            'quantity as text' => [static function (array &$r): void { $r['items'][0]['quantity'] = '2'; },
                'wrong_type', '/items/0/quantity', 'must be an integer'],
            'negative side' => [static function (array &$r): void { $r['items'][0]['dimensions']['width'] = '-1'; },
                'negative_measure', '/items/0/dimensions/width', 'cannot be negative'],
            'fractional side' => [static function (array &$r): void { $r['items'][0]['dimensions']['length'] = 10.5; },
                'wrong_type', '/items/0/dimensions/length', 'must be a measure'],
            'unknown unit' => [static function (array &$r): void { $r['items'][0]['weight'] = ['value' => '1', 'unit' => 'stone']; },
                'invalid_unit', '/items/0/weight', 'has an unknown unit "stone"'],
            'ratio above one' => [static function (array &$r): void { $r['configuration']['minimum_support_ratio'] = 1.5; },
                'above_maximum', '/configuration/minimum_support_ratio', 'must be at most 1'],
            'unknown profile' => [static function (array &$r): void { $r['configuration']['solver_profile'] = 7; },
                'not_allowed', '/configuration/solver_profile', 'must be one of ["fast","balanced","quality","exact_small"]'],
            'configuration a list' => [static function (array &$r): void { $r['configuration'] = [1]; },
                'wrong_type', '/configuration', 'must be an object'],
            'tag escaped' => [static function (array &$r): void { $r['containers'][0]['tag_limits'] = ['a/b~c' => 0]; },
                'below_minimum', '/containers/0/tag_limits/a~1b~0c', 'must be at least 1'],
            'repeated container id' => [static function (array &$r): void { $r['containers'][] = $r['containers'][0]; },
                'duplicate_id', '/containers/1/id', 'repeats the id "box"'],
            'tags in code-point order' => [static function (array &$r): void { $r['containers'][0]['tag_limits'] = ['b' => 0, '10' => 0]; },
                'below_minimum', '/containers/0/tag_limits/10', 'must be at least 1'],
            'unknown length unit' => [static function (array &$r): void { $r['units'] = ['length' => 'furlong']; },
                'invalid_unit', '/units/length', 'has an unknown unit "furlong"'],
            'items an object' => [static function (array &$r): void { $r['items'] = ['cube' => $r['items'][0]]; },
                'wrong_type', '/items', 'must be a list'],
            'id a number' => [static function (array &$r): void { $r['items'][0]['id'] = 7; },
                'wrong_type', '/items/0/id', 'must be a string'],
            'huge negative stop' => [static function (array &$r): void { $r['items'][0]['stop_index'] = -1.0e19; },
                'below_minimum', '/items/0/stop_index', 'must be at least 0'],
            'ratio as text' => [static function (array &$r): void { $r['items'][0]['compression_ratio'] = '0.5'; },
                'wrong_type', '/items/0/compression_ratio', 'must be a number'],
            'negative ratio' => [static function (array &$r): void { $r['containers'][0]['void_fill_reserve_ratio'] = -0.5; },
                'below_minimum', '/containers/0/void_fill_reserve_ratio', 'must be at least 0'],
            'measure a boolean' => [static function (array &$r): void { $r['items'][0]['weight'] = true; },
                'wrong_type', '/items/0/weight', 'must be a measure'],
            'measure without a value' => [static function (array &$r): void { $r['items'][0]['weight'] = ['unit' => 'kg']; },
                'wrong_type', '/items/0/weight', 'must be a measure'],
            'measure value null' => [static function (array &$r): void { $r['items'][0]['weight'] = ['value' => null]; },
                'wrong_type', '/items/0/weight', 'must be a measure'],
            'outer side missing' => [static function (array &$r): void { $r['containers'][0]['outer_dimensions'] = ['length' => '1', 'width' => '1']; },
                'missing_field', '/containers/0/outer_dimensions/height', 'is required'],
            'bracket below its floor' => [static function (array &$r): void { $r['containers'][0]['rate_table'] = ['weight_brackets_g' => [1000, 0], 'prices_minor' => [1, 2]]; },
                'below_minimum', '/containers/0/rate_table/weight_brackets_g/1', 'must be at least 1'],
            'brackets not a list' => [static function (array &$r): void { $r['containers'][0]['rate_table'] = ['weight_brackets_g' => 1000]; },
                'wrong_type', '/containers/0/rate_table/weight_brackets_g', 'must be a list'],
            'negative surcharge' => [static function (array &$r): void { $r['containers'][0]['rate_table'] = ['fuel_surcharge_permille' => -1]; },
                'below_minimum', '/containers/0/rate_table/fuel_surcharge_permille', 'must be at least 0'],
            'catalog reference not an object' => [static function (array &$r): void { $r['catalog_versions_used'] = [5]; },
                'invalid_value', '', 'catalog_versions_used[0] must be an object'],
        ];
        foreach ($cases as $name => [$edit, $reason, $field, $detail]) {
            $request = self::request();
            $edit($request);
            $error = self::refusal($request, $name);
            self::assertSame('invalid_request', $error->errorCode(), $name);
            self::assertSame([$reason, $field, $detail], [$error->reason(), $error->field(), $error->detail()], $name);
        }
    }

    public static function testARequestThatIsNotAnObjectIsTheWrongType(): void
    {
        foreach (['a list' => [self::request()], 'a number' => 5, 'a string' => 'box'] as $name => $request) {
            $error = self::refusal($request, $name);
            self::assertSame(['wrong_type', ''], [$error->reason(), $error->field()], $name);
            self::assertSame('invalid_request: must be an object', $error->getMessage(), $name);
        }
    }

    public static function testAnEmptyArrayStandsForAnEmptyObject(): void
    {
        $request = self::request();
        $request['configuration'] = [];
        $request['items'][0]['tag_limits'] = [];
        $request['containers'][0]['tag_limits'] = [];
        $request['units'] = [];
        // Measures the reference reads too: an integral float, an object with no unit, and an
        // object whose value is a boolean or a fractional float.
        $request['items'][0]['dimensions'] = ['length' => '100', 'width' => ['value' => '100'], 'height' => ['value' => 100.0, 'unit' => 'mm']];
        $request['items'][0]['max_top_load'] = ['value' => true, 'unit' => 'kg'];
        $request['items'][0]['nesting_height'] = ['value' => 0.5];
        self::assertContains(ArrayCodec::pack($request)['status'], ['feasible', 'optimal']);
    }

    public static function testAnIntegralFloatPassesTheRulesAsTheIntegerItIs(): void
    {
        $request = self::request();
        $request['items'][0]['dimensions']['length'] = 100.0;
        $request['items'][0]['quantity'] = 2.0;
        RequestRules::check($request);
        self::assertTrue(true);
    }

    public static function testWhatTheRulesDoNotNameIsStillARequestError(): void
    {
        $request = self::request();
        $request['items'][0]['allowed_rotations'] = ['XYZ'];
        $error = self::refusal($request, 'unknown rotation');
        self::assertSame(['invalid_value', ''], [$error->reason(), $error->field()]);
        self::assertTrue(str_starts_with($error->getMessage(), 'invalid_request: '), $error->getMessage());
    }

    public static function testARefusalWithItsOwnCodeIsNotRelabelled(): void
    {
        $request = self::request();
        $request['containers'][0]['pallet_overhang_limit'] = '10';
        self::assertThrows(UnsupportedFeatureException::class, static fn() => ArrayCodec::pack($request));

        $request = self::request();
        $request['fixed_placements'] = [['item_type' => 'slab', 'container_type' => 'box', 'orientation' => 'LWH']];
        $error = self::refusal($request, 'unknown fixed item');
        self::assertTrue($error instanceof FixedPlacementException);
        self::assertSame(['cannot_hold', '/fixed_placements'], [$error->reason(), $error->field()]);
    }

    public static function testAMalformedFixedPlacementNamesTheBadValue(): void
    {
        $cases = [
            ['container_instance counts from 1', ['container_instance' => '1'], '/fixed_placements/0/container_instance'],
            ['position.y cannot be negative', ['position' => ['y' => '-1']], '/fixed_placements/0/position/y'],
            ['position.z is a measure', ['position' => ['z' => 'ten']], '/fixed_placements/0/position/z'],
            ['position.x is a measure', ['position' => ['x' => true]], '/fixed_placements/0/position/x'],
            ['position.y is a measure', ['position' => ['y' => ['unit' => 'mm']]], '/fixed_placements/0/position/y'],
            ['position.z is a measure', ['position' => ['z' => ['value' => '1', 'unit' => 5]]], '/fixed_placements/0/position/z'],
        ];
        foreach ($cases as [$detail, $fields, $field]) {
            $request = self::request();
            $request['fixed_placements'] = [array_replace(['item_type' => 'cube', 'container_type' => 'box', 'orientation' => 'LWH'], $fields)];
            $error = self::refusal($request, $detail);
            self::assertTrue($error instanceof FixedPlacementException, $detail);
            self::assertSame(['malformed', $field], [$error->reason(), $error->field()], $detail);
            self::assertSame("invalid_fixed_placement: fixed_placements[0].{$detail}", $error->getMessage());
        }
    }

    public static function testTheSolveItselfIsNotWrapped(): void
    {
        $request = self::request();
        $request['items'][0]['quantity'] = 10;
        $request['containers'][0]['quantity'] = 10;
        $request['configuration'] = ['solvers' => ['exact_small'], 'exact_item_limit' => 7];
        $message = null;
        try {
            ArrayCodec::pack($request);
        } catch (InvalidRequestException $error) {
            throw new RuntimeException("a solver refusal was dressed up as a request error: {$error->getMessage()}");
        } catch (InvalidArgumentException $error) {
            $message = $error->getMessage();
        }
        self::assertNotNull($message);
        self::assertTrue(stripos((string) $message, 'exact-small item limit exceeded') !== false, (string) $message);
    }

    /** @param mixed $request */
    private static function refusal($request, string $name): InvalidRequestException
    {
        try {
            ArrayCodec::pack($request);
        } catch (InvalidRequestException $error) {
            return $error;
        }
        throw new RuntimeException("{$name}: the request was packed");
    }

    /** @return array<string,mixed> */
    private static function request(): array
    {
        return [
            'items' => [['id' => 'cube', 'quantity' => 2, 'weight' => '1000',
                'dimensions' => ['length' => '100', 'width' => '100', 'height' => '100']]],
            'containers' => [['id' => 'box', 'quantity' => 2,
                'inner_dimensions' => ['length' => '200', 'width' => '100', 'height' => '200']]],
            'configuration' => ['solver_profile' => 'fast'],
        ];
    }
}
