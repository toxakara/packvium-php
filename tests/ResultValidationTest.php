<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Serialization\ArrayCodec;
use Packvium\Support\ResultValidation\Exact;
use Packvium\Support\ResultValidation\Geometry;
use Packvium\Support\ResultValidation\Json;
use Packvium\Support\ResultValidation\Label;
use Packvium\Support\ResultValidation\PyFormat;
use Packvium\Support\ResultValidation\Ratio;
use Packvium\Support\ResultValidation\Units;
use Packvium\Validation\MalformedResult;
use Packvium\Validation\ResultIssue;

use function Packvium\Validation\validate;

/**
 * `Packvium\Validation\validate`, the reference validator ported to PHP. Mirrors
 * `packvium-python/tests/test_result_validation.py`. Issue-for-issue agreement with
 * `packvium.validate` over the whole conformance corpus is `conformance/result_validation/run.py`'s
 * job; these pin the entry point, its independence from the engine, and the Python semantics
 * the issue details depend on.
 */
final class ResultValidationTest extends TestCase
{
    private const REQUEST = [
        'items' => [['id' => 'cube', 'quantity' => 2, 'dimensions' => ['length' => '10', 'width' => '10', 'height' => '10']]],
        'containers' => [['id' => 'box', 'inner_dimensions' => ['length' => '20', 'width' => '10', 'height' => '10']]],
    ];

    /** The engine packages the validator may use: integer primitives and its own public types. */
    private const ALLOWED = [
        'Packvium\\Support\\BigInt', 'Packvium\\Support\\SignedBigInt', 'Packvium\\Support\\ShortestFloat',
        'Packvium\\Validation\\MalformedResult', 'Packvium\\Validation\\ResultIssue',
    ];

    public static function testTheValidatorImportsNothingFromTheEngine(): void
    {
        $imported = [];
        foreach (\glob(\dirname(__DIR__) . '/src/Support/ResultValidation/*.php') ?: [] as $file) {
            \preg_match_all('/^use (Packvium\\\\[^;\s]+);/m', (string)\file_get_contents($file), $uses);
            foreach ($uses[1] as $class) {
                if (\strncmp($class, 'Packvium\\Support\\ResultValidation\\', 34) !== 0 && !\in_array($class, self::ALLOWED, true)) {
                    $imported[] = \basename($file) . ': ' . $class;
                }
            }
        }
        self::assertSame([], $imported);
    }

    public static function testAResultTheEnginePackedIsValid(): void
    {
        self::assertSame([], validate(self::REQUEST, ArrayCodec::pack(self::REQUEST)));
    }

    public static function testTwoItemsInOnePlaceAreACollisionNamedAsTheReferenceNamesIt(): void
    {
        $result = ArrayCodec::pack(self::REQUEST);
        $result['containers'][0]['placements'][1]['position'] = $result['containers'][0]['placements'][0]['position'];
        $issues = validate(self::REQUEST, $result);
        self::assertCount(1, $issues);
        self::assertTrue($issues[0] instanceof ResultIssue);
        self::assertSame('collision: cube#1 with cube#2 in box#1', (string)$issues[0]);
    }

    /**: the audit's scene, where B rests on exactly half its base at a ratio that rounds down to 500000 ppm. */
    public static function testTheAuditReproductionIsPackedAndValid(): void
    {
        $request = [
            'configuration' => ['minimum_support_ratio' => 0.5000001, 'solver_profile' => 'fast'],
            'items' => [
                ['id' => 'A', 'quantity' => 1, 'priority' => 10, 'allowed_rotations' => ['LWH'], 'dimensions' => ['length' => '100', 'width' => '50', 'height' => '50']],
                ['id' => 'B', 'quantity' => 1, 'allowed_rotations' => ['LWH'], 'dimensions' => ['length' => '100', 'width' => '100', 'height' => '50']],
            ],
            'containers' => [['id' => 'box', 'inner_dimensions' => ['length' => '100', 'width' => '100', 'height' => '100']]],
        ];
        $result = ArrayCodec::pack($request);
        self::assertSame('feasible', $result['status']);
        self::assertSame([], validate($request, $result));
    }

    /**
     * Support is judged by the solvers' ppm rule, `floor(base * ppm / 10^6)` with
     * `ppm = floor(ratio * 10^6 + 0.5)` in doubles, not by the exact rational. On a 10^7 tick
     * base each ratio's ppm floor is admitted and one tick less is refused.
     */
    public static function testSupportIsJudgedByTheSolversPpmRule(): void
    {
        $base = 10000000;
        $bar = ['length' => '1', 'width' => (string)$base, 'height' => '1'];
        $request = [
            'units' => ['length' => 'tick'],
            'items' => [
                ['id' => 'lower', 'quantity' => 1, 'allowed_rotations' => ['LWH'], 'dimensions' => $bar],
                ['id' => 'upper', 'quantity' => 1, 'allowed_rotations' => ['LWH'], 'dimensions' => $bar],
            ],
            'containers' => [['id' => 'box', 'inner_dimensions' => ['length' => '1', 'width' => (string)(2 * $base), 'height' => '2']]],
        ];
        $floor = ArrayCodec::pack($request);
        $insufficient = static function (float $ratio, int $supported) use ($request, $floor, $base): array {
            $result = $floor;
            foreach ($result['containers'][0]['placements'] as &$placement) {
                if ($placement['item_type'] === 'upper') {
                    $placement['position']['y'] = ['ticks' => $base - $supported, 'value' => (string)($base - $supported), 'unit' => 'tick'];
                    $placement['position']['z'] = ['ticks' => 1, 'value' => '1', 'unit' => 'tick'];
                }
            }
            unset($placement);
            $request['configuration'] = ['minimum_support_ratio' => $ratio];
            $details = [];
            foreach (validate($request, $result) as $issue) {
                if ($issue->code === 'insufficient_support') {
                    $details[] = $issue->detail;
                }
            }
            return $details;
        };
        // 0.0001245 is 124 ppm in doubles where the exact decimal rounds to 125.
        foreach ([[0.5000001, 5000000], [0.0001245, 1240], [0.9999996, 10000000], [0.25, 2500000]] as [$ratio, $threshold]) {
            self::assertSame([], $insufficient($ratio, $threshold), "ratio {$ratio}");
            self::assertCount(1, $insufficient($ratio, $threshold - 1), "ratio {$ratio}");
        }
        self::assertSame(['upper#1: 0.500000 < 0.500000'], $insufficient(0.5000001, 4999999));
    }

    /**
     * (audit F5): the void-fill reserve goes through the same conversion, reserving
     * `floor(volume * ppm / 10^6)`. On a 10^7 cubic tick container the engine reports that
     * reserve, a bar filling exactly the usable volume is valid and one tick wider is refused.
     */
    public static function testTheVoidFillReserveIsJudgedByTheSolversPpmRule(): void
    {
        $volume = 10000000;
        $request = static function (float $ratio, int $width) use ($volume): array {
            return [
                'units' => ['length' => 'tick'],
                'items' => [['id' => 'bar', 'quantity' => 1, 'allowed_rotations' => ['LWH'], 'dimensions' => ['length' => '1', 'width' => (string)$width, 'height' => '1']]],
                'containers' => [['id' => 'box', 'void_fill_reserve_ratio' => $ratio, 'inner_dimensions' => ['length' => '1', 'width' => (string)$volume, 'height' => '1']]],
            ];
        };
        $oneTick = ArrayCodec::pack($request(0.0, 1));
        $reserveIssues = static function (float $ratio, int $width, int $reserve) use ($request, $oneTick): array {
            $result = $oneTick;
            $result['containers'][0]['placements'][0]['dimensions']['width'] = ['ticks' => $width, 'value' => (string)$width, 'unit' => 'tick'];
            $result['containers'][0]['used_volume_ticks3'] = (string)$width;
            $result['containers'][0]['void_fill_reserve_ticks3'] = (string)$reserve;
            $issues = [];
            foreach (validate($request($ratio, $width), $result) as $issue) {
                if (\strncmp($issue->code, 'void_fill_reserve', 17) === 0) {
                    $issues[] = (string)$issue;
                }
            }
            return $issues;
        };
        // 0.0001245 is 124 ppm in doubles where the exact decimal rounds to 125.
        foreach ([[0.0001245, 1240], [0.0002445, 2440], [0.5000001, 5000000], [0.9999994, 9999990], [0.25, 2500000]] as [$ratio, $reserve]) {
            $packed = ArrayCodec::pack($request($ratio, 1));
            self::assertSame((string)$reserve, (string)$packed['containers'][0]['void_fill_reserve_ticks3'], "ratio {$ratio}");
            self::assertSame([], validate($request($ratio, 1), $packed), "ratio {$ratio}");
            self::assertSame([], $reserveIssues($ratio, $volume - $reserve, $reserve), "ratio {$ratio}");
            self::assertCount(1, $reserveIssues($ratio, $volume - $reserve + 1, $reserve), "ratio {$ratio}");
        }
        self::assertSame(['void_fill_reserve_exceeded: box#1: used 9998761 > usable 9998760'], $reserveIssues(0.0001245, 9998761, 1240));
    }

    /**
     * `compression_ratio` goes through the same conversion. A 5 kg brick holds a 1 m
     * compressible pad at half its 10 kPa limit, where one ppm moves the occupied height by
     * about eight ticks: the engine compresses by the double's ppm and the validator agrees.
     */
    public static function testCompressionIsJudgedByTheSolversPpmRule(): void
    {
        $request = static function (float $ratio): array {
            return [
                'items' => [
                    ['id' => 'pad', 'quantity' => 1, 'must_be_on_floor' => true, 'allowed_rotations' => ['LWH'], 'dimensions' => ['length' => '100', 'width' => '100', 'height' => '1000'], 'shape_type' => 'compressible', 'compression_ratio' => $ratio, 'max_compression_pressure_kpa' => 10],
                    ['id' => 'brick', 'quantity' => 1, 'allowed_rotations' => ['LWH'], 'dimensions' => ['length' => '100', 'width' => '100', 'height' => '10'], 'weight' => ['value' => '5', 'unit' => 'kg']],
                ],
                'containers' => [['id' => 'box', 'inner_dimensions' => ['length' => '100', 'width' => '100', 'height' => '2000']]],
            ];
        };
        $used = static function (float $ratio) use ($request): string {
            return (string)ArrayCodec::pack($request($ratio))['containers'][0]['used_volume_ticks3'];
        };
        // 0.0001245 is 124 ppm in doubles where the exact decimal rounds to 125.
        foreach ([[0.0001245, 0.000124], [0.0002445, 0.000244], [0.5000001, 0.5], [0.25, 0.25]] as [$ratio, $ppmRatio]) {
            $result = ArrayCodec::pack($request($ratio));
            self::assertSame([], validate($request($ratio), $result), "ratio {$ratio}");
            self::assertSame($used($ppmRatio), (string)$result['containers'][0]['used_volume_ticks3'], "ratio {$ratio}");
        }
        self::assertNotSame($used(0.000125), $used(0.0001245));
    }

    public static function testAResultOfTheWrongShapeIsRefusedRatherThanJudged(): void
    {
        try {
            validate(self::REQUEST, ['containers' => 'none']);
            self::assertTrue(false, 'a result without the documented shape must be refused');
        } catch (MalformedResult $refusal) {
            self::assertSame(MalformedResult::CONTRACT, $refusal->kind);
            self::assertSame("result is missing 'status'", $refusal->getMessage());
        }
    }

    public static function testIntegersStayExactPastSixtyFourBits(): void
    {
        $volume = Exact::mul(Exact::mul(9600000000, 9600000000), 9600000000);
        self::assertSame('884736000000000000000000000000', $volume);
        self::assertSame(-4, Exact::floorDiv(-7, 2));
        self::assertSame(2, Exact::mod(-7, 3));
        self::assertSame(4, Exact::ceilDiv(7, 2));
        self::assertSame(Exact::negate('884736000000000000'), Exact::floorDiv(Exact::negate($volume), '1000000000000'));
    }

    public static function testADocumentIntegerPastSixtyFourBitsIsRefusedNotJudged(): void
    {
        // `json_decode` has turned it into a float (or a string) before the validator sees it,
        // so it cannot be judged exactly; the reference would judge it, and the contract names
        // the departure (docs/VALIDATION-CONTRACT.md).
        $result = ArrayCodec::pack(self::REQUEST);
        $result['containers'][0]['placements'][0]['position']['x']['ticks'] = 'SEVENTY_BITS';
        $big = \str_replace('"SEVENTY_BITS"', '1180591620717411303424', (string)\json_encode($result));
        foreach ([0, \JSON_BIGINT_AS_STRING] as $flags) {
            $message = null;
            try {
                validate(self::REQUEST, \json_decode($big, true, 512, $flags));
            } catch (MalformedResult $refusal) {
                $message = $refusal->getMessage();
            }
            self::assertTrue(\is_string($message) && \str_contains($message, 'ticks must be an integer'),
                'a 70-bit tick must be refused, not judged');
        }
    }

    public static function testFractionsParseAsPythonReadsThem(): void
    {
        $cases = ['1_000' => [1000, 1], '.5' => [1, 2], '5.' => [5, 1], '1e3' => [1000, 1], '1E-2' => [1, 100],
            ' 3/16 ' => [3, 16], '+.5e1' => [5, 1], '1_0.0_1' => [1001, 100], '-0.25' => [-1, 4]];
        foreach ($cases as $text => [$numerator, $denominator]) {
            $ratio = Units::parseFraction((string)$text);
            self::assertSame([$numerator, $denominator], [$ratio->numerator, $ratio->denominator], (string)$text);
        }
        foreach (['1 / 2', '0x10', '', '.', '1__0', '_1', '1/0', 'None'] as $bad) {
            self::assertThrows(MalformedResult::class, static function () use ($bad): void { Units::parseFraction($bad); }, $bad);
        }
    }

    public static function testMeasuresRoundHalfToEven(): void
    {
        self::assertSame(152400 + 12 * 406400, Units::toTicks('12 3/8 in', 'mm', Units::LENGTH));
        self::assertSame(-24000, Units::toTicks('-1 1/2 mm', 'mm', Units::LENGTH));
        self::assertSame(1600000, Units::toTicks('10 CM', 'mm', Units::LENGTH));
        self::assertSame(0, Units::toTicks('0.00003125', 'mm', Units::LENGTH));
        self::assertSame(2, Units::toTicks('0.00009375', 'mm', Units::LENGTH));
    }

    public static function testValuesPrintAsPythonPrintsThem(): void
    {
        $floats = [[1e16, '1e+16'], [1e-5, '1e-05'], [0.0001, '0.0001'], [123456789012345678.0, '1.2345678901234568e+17'],
            [0.1, '0.1'], [-0.0, '-0.0'], [5.0, '5.0'], [1e15, '1000000000000000.0'], [2.5e-6, '2.5e-06']];
        foreach ($floats as [$value, $expected]) {
            self::assertSame($expected, PyFormat::floatRepr($value));
        }
        self::assertSame("\"it's\"", PyFormat::stringRepr("it's"));
        self::assertSame("'a\\nb\\\\'", PyFormat::stringRepr("a\nb\\"));
        self::assertSame("'\\x07é\\u200b'", PyFormat::stringRepr("\u{7}é\u{200b}"));
        self::assertSame("'\\U000e0001\\u0600'", PyFormat::stringRepr("\u{e0001}\u{600}"));
        self::assertSame("'\\u0378\\ue000é'", PyFormat::stringRepr("\u{378}\u{e000}é"));
        self::assertSame("{'a': [1, None, True], 'b': 'x'}", PyFormat::repr(['a' => [1, null, true], 'b' => 'x']));
        self::assertSame('(7,)', PyFormat::intTuple([7]));
        self::assertSame('0.000003', PyFormat::fixed6(Ratio::of(5, 2000000)->toFloat()));
    }

    public static function testFloatConversionIsCorrectlyRounded(): void
    {
        self::assertSame(2.5e-6, Ratio::of(5, 2000000)->toFloat());
        self::assertSame(2.0 ** 60, Ratio::integer((1 << 60) + 1)->toFloat());
        self::assertSame((float)((1 << 54) + 4), Ratio::integer((1 << 54) + 3)->toFloat());
        self::assertSame(1.0 / 3.0, Ratio::of('300000000000000000000', '900000000000000000000')->toFloat());
    }

    /**: the Python values the reference compares, orders and hashes, beyond what the corpus reaches. */
    public static function testJsonValuesCompareAsPythonComparesThem(): void
    {
        self::assertSame(1, Json::compare([1, 2], [1]));
        self::assertSame(-1, Json::compare([1], [1, 2]));
        self::assertSame(-1, Json::compare(1.5, 2));
        self::assertTrue(Json::equals([1, [2.0]], [true, [2]]));
        self::assertFalse(Json::equals([1, 2], [1, 3]));
        self::assertSame('null', Json::hashKey(null));
        self::assertSame(['null', 'a boolean', 'an integer', 'a list', 'an object'],
            [Json::kind(null), Json::kind(false), Json::kind(1), Json::kind([1]), Json::kind([])]);
        self::assertSame([2, -2, 1, '10000000000000000000'],
            [Json::pyInt(2.7, 'x'), Json::pyInt(-2.7, 'x'), Json::pyInt(true, 'x'), Json::pyInt(1e19, 'x')]);
        self::assertSame(1, Json::int(true, 'x'));
        self::assertFalse(Json::isList(5));
        $refusals = [
            static function (): void { Json::compare([1], 'a'); },
            static function (): void { Json::hashKey([1]); },
            static function (): void { Json::pyInt([], 'x'); },
            static function (): void { Json::get(5, 'x'); },
            static function (): void { Json::items('x', 'x'); },
            static function (): void { Json::text(1, 'x'); },
        ];
        foreach ($refusals as $index => $refusal) {
            self::assertThrows(MalformedResult::class, $refusal, "refusal {$index}");
        }
    }

    public static function testExactArithmeticCoversItsEdges(): void
    {
        self::assertSame(12, Exact::normalize('12'));
        self::assertSame('18446744073709551616', Exact::powerOfTwo(64));
        self::assertThrows(MalformedResult::class, static function (): void { Exact::floorDiv(1, 0); });
        $negative = Ratio::of(3, -4);
        self::assertSame([-3, 4], [$negative->numerator, $negative->denominator]);
        self::assertSame(1.0 / 3.0, Ratio::of(1, 3)->toFloat());
        self::assertSame(0.1, Ratio::of(1, 10)->toFloat());
        self::assertSame(3.0e-300, Ratio::of(3, Exact::normalize('1' . \str_repeat('0', 300)))->toFloat());
    }

    public static function testGeometryAndUnitsRefuseWhatTheyCannotRead(): void
    {
        self::assertSame([0, 0, 0], Geometry::primitiveAxis([0, 0, 0]));
        // WLH is an odd permutation, so the hull is mirrored back into a proper rotation
        // (`_rotate_hull` gives ((1, 0, 0), (1, 2, 0), (0, 0, 0)) for the same three vertices).
        self::assertSame([[1, 0, 0], [1, 2, 0], [0, 0, 0]],
            Geometry::rotateHull([[0, 0, 0], [2, 0, 0], [0, 1, 0]], Geometry::axes('WLH')));
        $refusals = [
            static function (): void { Geometry::axes('XYZ'); },
            static function (): void { Geometry::rotateHull([], [0, 1, 2]); },
            static function (): void { Units::perUnit(Units::LENGTH, 'furlong'); },
            static function (): void { Units::parseFraction('1e999'); },
        ];
        foreach ($refusals as $index => $refusal) {
            self::assertThrows(MalformedResult::class, $refusal, "refusal {$index}");
        }
    }

    /** The reference prints a missing id as `?`; the port refuses an issue it would have to name so. */
    public static function testAnIssueOnAContainerWithoutAnIdIsRefused(): void
    {
        $label = new Label(['container_type' => 'box']);
        self::assertSame('?', (string)$label);
        self::assertThrows(MalformedResult::class, static function () use ($label): void { $label->verify(); });
    }

    public static function testSpecialValuesPrintAsPythonPrintsThem(): void
    {
        self::assertSame(['nan', 'inf', '-inf', '1.5'],
            [PyFormat::floatRepr(\NAN), PyFormat::floatRepr(\INF), PyFormat::floatRepr(-\INF), PyFormat::floatRepr(1.5)]);
        self::assertSame("'a\\tb\\rc'", PyFormat::stringRepr("a\tb\rc"));
        self::assertSame("'a\\'b\"'", PyFormat::stringRepr("a'b\""));
        self::assertSame("'\\x85'", PyFormat::stringRepr("\u{85}"));
        self::assertSame("'\\U000f0000'", PyFormat::stringRepr("\u{f0000}"));
    }
}
