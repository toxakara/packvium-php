<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Execution\Plan;
use Packvium\Support\CanonicalJson;
use Packvium\Support\CanonicalJsonException;
use stdClass;

/**
 * RFC 8785 canonical JSON in PHP, held to `packvium._canonical_json`.
 *
 * What is checked is where the language defaults disagree, because that is where a
 * cross-language byte comparison fails: integral floats, exponents, key order outside the
 * Basic Multilingual Plane, the characters JSON writers escape differently, the values no
 * engine can carry exactly -- and, for PHP alone, `{}` against `[]` and the ini settings that
 * steer `json_encode`. Where Node is installed its own `JSON.stringify` is the oracle for
 * numbers, since RFC 8785 defines them as ECMAScript writes them.
 */
final class CanonicalJsonTest extends TestCase
{
    private const NUMBERS = [
        [0.25, '0.25'], [1.0, '1'], [-0.0, '0'], [4.5, '4.5'], [0.1, '0.1'], [2e-3, '0.002'],
        [0.000001, '0.000001'], [1e-7, '1e-7'], [1.5e-7, '1.5e-7'], [1e-27, '1e-27'], [-1.5, '-1.5'],
        [123456789.5, '123456789.5'], [333333333.33333329, '333333333.3333333'],
        [9007199254740991.0, '9007199254740991'], [5e-324, '5e-324'], [100.0, '100'],
    ];

    public static function testShortestDigitsAreDefinedOnlyForAFinitePositiveDouble(): void
    {
        self::assertSame(['25', 0], \Packvium\Support\ShortestFloat::digits(0.25));
        self::assertSame(['1', -6], \Packvium\Support\ShortestFloat::digits(1e-7));
        foreach ([0.0, -0.0, -1.5, \INF, \NAN] as $value) {
            self::assertThrows(\InvalidArgumentException::class, static function () use ($value): void {
                \Packvium\Support\ShortestFloat::digits($value);
            });
        }
    }

    public static function testNumbersAreWrittenAsEcmascriptWritesThem(): void
    {
        foreach (self::NUMBERS as [$value, $spelled]) {
            self::assertSame($spelled, CanonicalJson::encode($value));
        }
    }

    public static function testNumbersDoNotFollowThePrecisionIniSettings(): void
    {
        $serialize = (string) \ini_get('serialize_precision');
        $precision = (string) \ini_get('precision');
        \ini_set('serialize_precision', '5');
        \ini_set('precision', '3');
        try {
            self::assertSame('[0.1,333333333.3333333,1e-27]', CanonicalJson::encode([0.1, 333333333.33333329, 1e-27]));
        } finally {
            \ini_set('serialize_precision', $serialize);
            \ini_set('precision', $precision);
        }
    }

    public static function testEveryNumberMatchesJavascriptItself(): void
    {
        $node = \trim((string) \shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::skip('node is not installed');
        }
        $values = self::hardDoubles();
        // Seventeen significant digits name each double exactly, whatever the ini settings.
        $text = '[' . \implode(',', \array_map(static function (float $value): string {
            return \sprintf('%.16e', $value);
        }, $values)) . ']';
        $script = "process.stdout.write(JSON.stringify(JSON.parse(require('fs').readFileSync(0,'utf8'))))";
        $process = \proc_open([$node, '-e', $script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertTrue(\is_resource($process), 'node could not be started');
        \fwrite($pipes[0], $text);
        \fclose($pipes[0]);
        $written = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        self::assertSame(0, \proc_close($process));
        self::assertSame($written, CanonicalJson::encode($values));
    }

    public static function testIntegersAndLiteralsKeepTheirSpelling(): void
    {
        self::assertSame(
            '[0,-7,9007199254740991,-9007199254740991,true,false,null]',
            CanonicalJson::encode([0, -7, 9007199254740991, -9007199254740991, true, false, null])
        );
    }

    public static function testKeysAreSortedByUtf16CodeUnitsNotCodePoints(): void
    {
        // U+FFFD sorts after U+1F600 by code point and before it by UTF-16 code unit, whose
        // first unit is the high surrogate U+D83D. JavaScript sorts the second way.
        self::assertSame(
            "{\"a\":0,\"\u{1F600}\":2,\"\u{E000}\":3,\"\u{FFFD}\":1}",
            CanonicalJson::encode(["\u{FFFD}" => 1, "\u{1F600}" => 2, 'a' => 0, "\u{E000}" => 3])
        );
    }

    public static function testOnlyTheCharactersRfc8785NamesAreEscaped(): void
    {
        $text = "\"\\\x08\t\n\x0C\r\x00\x1F/\x7F\u{2028}\u{2029}\u{00E9}";
        $expected = '"' . '\\"' . '\\\\' . '\\b' . '\\t' . '\\n' . '\\f' . '\\r' . '\\u0000' . '\\u001f'
            . "/\x7F\u{2028}\u{2029}\u{00E9}" . '"';
        self::assertSame($expected, CanonicalJson::encode($text));
    }

    public static function testThereIsNoWhitespaceAndNestingIsPreserved(): void
    {
        $value = ['b' => [1, ['d' => [], 'c' => new stdClass()]], 'a' => 'x'];
        self::assertSame('{"a":"x","b":[1,{"c":{},"d":[]}]}', CanonicalJson::encode($value));
    }

    public static function testDecodedObjectsKeepEmptyObjectsAndNumericNames(): void
    {
        // An associative array would turn `{}` into `[]` and `{"0":"a"}` into `["a"]`.
        $text = '{"0":"a","1":{},"e":[],"n":{"9":2,"10":1},"z":{"0":[{}]}}';
        $decoded = \json_decode($text, false, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('{"0":"a","1":{},"e":[],"n":{"10":1,"9":2},"z":{"0":[{}]}}', CanonicalJson::encode($decoded));
    }

    public static function testANumberNoEngineHoldsExactlyIsRefused(): void
    {
        foreach ([9007199254740992, -9007199254740992, \PHP_INT_MAX, 9007199254740992.0, \INF, -\INF, \NAN] as $value) {
            self::assertSame('number_out_of_range', self::code(['n' => $value]));
        }
    }

    public static function testAStringThatIsNotUtf8IsRefusedInAValueAndInAKey(): void
    {
        // PHP holds a lone surrogate only as bytes: an encoded surrogate, an overlong form, a
        // stray byte.
        foreach (["\xED\xA0\x80", "\xC0\xAF", "\xFF"] as $bytes) {
            self::assertSame('invalid_string', self::code($bytes));
            self::assertSame('invalid_string', self::code([$bytes => 1]));
        }
    }

    public static function testAValueJsonCannotSpellIsRefused(): void
    {
        $stream = \fopen('php://memory', 'rb');
        try {
            foreach ([new \DateTimeImmutable('@0'), static function (): void {
            }, $stream, ['a' => new \ArrayObject()]] as $value) {
                self::assertSame('invalid_value', self::code($value));
            }
        } finally {
            \fclose($stream);
        }
    }

    public static function testNoPlanAnEngineEmitsChangedItsBytesWhenTheSpellingBecameRfc8785(): void
    {
        // 1.2.0 published `json_encode` over sorted keys. For every golden result the two
        // spellings are the same bytes, so the switch fixes only inputs the engines disagreed on.
        $golden = \dirname(__DIR__, 2) . '/conformance/golden';
        if (!\is_dir($golden)) {
            self::skip('the golden corpus lives in the workspace only');
        }
        $compared = 0;
        foreach (\glob($golden . '/*.json') ?: [] as $path) {
            $result = \json_decode((string) \file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($result) || !\array_key_exists('status', $result)) {
                continue;
            }
            $plan = Plan::build([], $result);
            $published = \json_encode(self::sortKeys($plan), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
            self::assertSame($published, Plan::canonicalJson($plan), \basename($path));
            $compared++;
        }
        self::assertGreaterThan(390, $compared);
    }

    /** @param mixed $value */
    private static function code($value): string
    {
        try {
            CanonicalJson::encode($value);
        } catch (CanonicalJsonException $refused) {
            return $refused->errorCode();
        }
        self::fail('a value with no canonical spelling was written');
    }

    /**
     * Powers of two and their neighbours from the smallest subnormal to 2^53, where the
     * rounding interval is lopsided, plus exact decimal ties and a fixed pseudo-random spread.
     *
     * @return list<float>
     */
    private static function hardDoubles(): array
    {
        $values = [];
        foreach (self::NUMBERS as [$value]) {
            $values[] = $value;
        }
        for ($exponent = -1074; $exponent <= 52; $exponent++) {
            $power = 2.0 ** $exponent;
            $values[] = $power;
            $values[] = self::adjacent($power, 1);
            if ($exponent > -1074) {
                $values[] = self::adjacent($power, -1);
            }
        }
        $values[] = 1125899906842624.25;
        $values[] = 1125899906842624.75;
        // xorshift64: shifts and XOR only, so the state never overflows into a float.
        $state = 0x2545F4914F6CDD1D;
        for ($count = 0; $count < 2000; $count++) {
            $state ^= $state << 13;
            $state ^= ($state >> 7) & 0x01FFFFFFFFFFFFFF;
            $state ^= $state << 17;
            $candidate = \unpack('E', \pack('J', $state & 0x433FFFFFFFFFFFFF))[1];
            $values[] = \is_finite($candidate) && \abs($candidate) <= CanonicalJson::MAX_EXACT_MAGNITUDE ? $candidate : 0.5;
        }
        return $values;
    }

    private static function adjacent(float $value, int $step): float
    {
        return \unpack('E', \pack('J', \unpack('J', \pack('E', $value))[1] + $step))[1];
    }

    /**
     * The 1.2.0 spelling's key order, kept here only to prove the switch changed no bytes.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function sortKeys($value)
    {
        if (!\is_array($value)) {
            return $value;
        }
        if ($value !== [] && \array_keys($value) !== \range(0, \count($value) - 1)) {
            \ksort($value, \SORT_STRING);
        }
        return \array_map([self::class, 'sortKeys'], $value);
    }
}
