<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Serialization\ArrayCodec;
use Packvium\Support\PrettyJsonStream;
use stdClass;

/**
 * `bin/packvium` streams its result; the bytes must be the ones the one-shot
 * `json_encode(..., JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)` wrote.
 */
final class PrettyJsonStreamTest extends TestCase
{
    private const FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES;

    /** @param mixed $value */
    private static function streamed($value): string
    {
        $stream = \fopen('php://memory', 'w+b');
        self::assertTrue($stream !== false, 'php://memory did not open');
        PrettyJsonStream::write($value, $stream);
        \rewind($stream);
        return (string) \stream_get_contents($stream);
    }

    /**
     * Copies of four shared conformance scenes, inlined so the published package carries them:
     * alternatives, a made-to-measure box and an axle load each add a result shape.
     */
    private const RESULT_SHAPE_REQUESTS = [
        'gen-alternatives-00' => '{"items":[{"id":"a","dimensions":{"length":"40","width":"30","height":"20"},"quantity":5},{"id":"b","dimensions":{"length":"60","width":"50","height":"40"},"quantity":3}],"containers":[{"id":"c","inner_dimensions":{"length":"150","width":"150","height":"150"},"quantity":3}],"configuration":{"solver_profile":"balanced","alternatives":1,"time_limit_ms":300000,"effort_budget":{"max_candidates_evaluated":1000000,"max_placement_attempts":1000000,"max_search_nodes":1000000}}}',
        'gen-alternatives-03' => '{"items":[{"id":"a","dimensions":{"length":"40","width":"30","height":"20"},"quantity":5},{"id":"b","dimensions":{"length":"60","width":"50","height":"40"},"quantity":3}],"containers":[{"id":"c","inner_dimensions":{"length":"150","width":"150","height":"150"},"quantity":3}],"configuration":{"solver_profile":"balanced","alternatives":4,"time_limit_ms":300000,"effort_budget":{"max_candidates_evaluated":1000000,"max_placement_attempts":1000000,"max_search_nodes":1000000}}}',
        'feature-made-to-measure-multi-box' => '{"units":{"length":"mm"},"configuration":{"solver_profile":"balanced","time_limit_ms":300000,"minimum_support_ratio":1,"effort_budget":{"max_candidates_evaluated":1000000,"max_placement_attempts":1000000,"max_search_nodes":1000000}},"items":[{"id":"cube","quantity":30,"weight":"100","dimensions":{"length":"100","width":"100","height":"100"}}],"containers":[{"id":"machine","cost_minor":40,"inner_dimensions":{"length":"300","width":"300","height":"300"},"made_to_measure":{"minimum":{"length":"50","width":"50","height":"50"},"increment":{"length":"10","width":"10","height":"10"},"cost_per_square_metre_minor":1000,"outer_allowance":{"length":"3","width":"3","height":"3"}}}]}',
        'feature-axle-gross' => '{"units":{"length":"mm"},"configuration":{"solver_profile":"fast","time_limit_ms":300000,"effort_budget":{"max_candidates_evaluated":1000000,"max_placement_attempts":1000000,"max_search_nodes":1000000}},"items":[{"id":"payload","quantity":1,"dimensions":{"length":"10","width":"10","height":"10"},"weight":{"value":"1000","unit":"g"},"allowed_rotations":["LWH"]}],"containers":[{"id":"vehicle","inner_dimensions":{"length":"100","width":"10","height":"10"},"tare_weight":{"value":"1000","unit":"g"},"axles":[{"position":"0","max_load":{"value":"2000","unit":"g"}},{"position":"100","max_load":{"value":"2000","unit":"g"}}]}]}',
    ];

    /** @param mixed $value */
    private static function assertStreamsAsEncoded($value, string $message = ''): void
    {
        self::assertSame(\json_encode($value, self::FLAGS), self::streamed($value), $message);
    }

    public static function testAResultWithAlternativesStreamsAsItEncodes(): void
    {
        foreach (self::RESULT_SHAPE_REQUESTS as $name => $json) {
            $request = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            self::assertStreamsAsEncoded(ArrayCodec::pack($request), $name);
        }
    }

    public static function testEveryShapeJsonEncodeDistinguishesIsKept(): void
    {
        $leaf = ['path' => 'a/b', 'text' => "line\nbreak \u{2028} \u{e9}", 'float' => 0.1, 'whole' => 1.0, 'none' => null, 'yes' => true];
        $value = [
            'empty_list' => [],
            'empty_object' => new stdClass(),
            'object' => (object) ['k' => [1, 2]],
            'integer_keys' => [3 => 'c', 1 => 'a'],
            'numeric_string_key' => ['7' => ['x' => 1]],
            'gapped_list' => [0 => 'a', 2 => 'b'],
            'nested' => [[[], [[]]], \array_fill(0, 80, [])],
            'long' => \array_fill(0, 100, $leaf),
            'list_of_lists' => \array_fill(0, 30, \array_fill(0, 5, $leaf)),
            'unicode_key_é/' => \range(1, 70),
        ];
        self::assertStreamsAsEncoded($value);
        self::assertStreamsAsEncoded([]);
        self::assertStreamsAsEncoded('scalar/text');
        self::assertStreamsAsEncoded(\range(1, 65));
    }

    public static function testOutputLargerThanTheBufferIsWrittenWhole(): void
    {
        $value = ['rows' => \array_fill(0, 5000, ['id' => 'item-0001', 'size' => [1, 2, 3]])];
        $streamed = self::streamed($value);
        self::assertGreaterThan(65536 * 4, \strlen($streamed));
        self::assertSame(\json_encode($value, self::FLAGS), $streamed);
    }

    public static function testAStreamThatRefusesTheWriteIsAnError(): void
    {
        $path = (string) \tempnam(\sys_get_temp_dir(), 'packvium-json');
        $readOnly = \fopen($path, 'rb');
        try {
            self::assertThrows(\RuntimeException::class, static function () use ($readOnly): void {
                @PrettyJsonStream::write(['a' => 1], $readOnly);
            });
        } finally {
            \fclose($readOnly);
            \unlink($path);
        }
    }

    public static function testAValueJsonCannotHoldIsRefused(): void
    {
        self::assertThrows(\JsonException::class, static function (): void {
            self::streamed(['rows' => \array_fill(0, 70, 1.0), 'bad' => \NAN]);
        });
    }
}
