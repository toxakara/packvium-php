<?php
declare(strict_types=1);

namespace Packvium\Tests;

use JsonException;
use Packvium\Validation\MalformedResult;

use function Packvium\Validation\validate;

/**
 * The PHP validator agrees with the reference on every pair of the shared corpus.
 * Mirrors `packvium-python/tests/test_result_validation_corpus.py`, over the same
 * `conformance/result_validation/corpus.json` (written by
 * `scripts/generate_result_validation_corpus.py`): the subset of the cross-language corpus that
 * reaches every arc, issue code and refusal kind the full corpus reaches in the reference, each
 * pair with the reference's outcome.
 *
 * Each document is decoded as a PHP caller decodes one, and agreement is
 * `conformance/result_validation/run.py`'s rule: the same issues, code and detail, in order;
 * or, where the reference raised `MalformedResult`, a `contract` refusal with the same message
 * (up to a quoted Python exception); or, where it failed another way, any refusal.
 */
final class ResultValidationCorpusTest extends TestCase
{
    /** Reference refusals that end by quoting a Python exception compare up to the quote. */
    private const QUOTES_PYTHON_ERROR = '/^(placement is missing tick coordinates: |.*: lattice_summary is missing a canonical field: )/';

    public static function testEveryPairGetsTheReferenceVerdict(): void
    {
        $disagreements = [];
        foreach (self::corpus() as $index => $case) {
            $reason = self::disagreement($case['expected'], self::outcome($case['request'], $case['result']));
            if ($reason !== null) {
                $disagreements[] = "case {$index}: {$reason}";
            }
        }
        self::assertSame([], $disagreements);
    }

    public static function testTheCorpusReachesEveryIssueCodeAndRefusalKind(): void
    {
        $codes = [];
        $refusals = [];
        foreach (self::corpus() as $case) {
            foreach ($case['expected']['issues'] ?? [] as $issue) {
                $codes[$issue['code']] = true;
            }
            if (isset($case['expected']['refusal'])) {
                $refusals[$case['expected']['refusal']['kind']] = true;
            }
        }
        self::assertGreaterThan(70, \count($codes), 'the corpus quietly lost issue codes');
        self::assertSame(['contract', 'shape'], self::sortedKeys($refusals));
    }

    /** @return array{issues: list<array{code: string, detail: string}>}|array{refusal: array{kind: string, message: string}} */
    private static function outcome(string $requestText, string $resultText): array
    {
        try {
            $request = \json_decode($requestText, true, 512, \JSON_THROW_ON_ERROR);
            $result = \json_decode($resultText, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($request) || !\is_array($result)) {
                throw MalformedResult::shape('a document is not a JSON object');
            }
            $issues = [];
            foreach (validate($request, $result) as $issue) {
                $issues[] = ['code' => $issue->code, 'detail' => $issue->detail];
            }
            return ['issues' => $issues];
        } catch (MalformedResult $refusal) {
            return ['refusal' => ['kind' => $refusal->kind, 'message' => $refusal->getMessage()]];
        } catch (JsonException $refusal) {
            return ['refusal' => ['kind' => 'shape', 'message' => 'not JSON: ' . $refusal->getMessage()]];
        }
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private static function disagreement(array $expected, array $actual): ?string
    {
        if (isset($expected['issues'])) {
            if (($actual['issues'] ?? null) === $expected['issues']) {
                return null;
            }
            return isset($actual['refusal'])
                ? "refused ({$actual['refusal']['message']}) where the reference found issues"
                : 'a different issue list: ' . \json_encode($actual['issues']);
        }
        $reference = $expected['refusal'];
        if (!isset($actual['refusal'])) {
            return "answered where the reference refused ({$reference['message']})";
        }
        if ($reference['kind'] === 'contract' && !self::sameRefusal($reference, $actual['refusal'])) {
            return "refused with {$actual['refusal']['kind']}: {$actual['refusal']['message']} "
                . "where the reference raised MalformedResult({$reference['message']})";
        }
        return null;
    }

    /**
     * @param array{kind: string, message: string} $expected
     * @param array{kind: string, message: string} $actual
     */
    private static function sameRefusal(array $expected, array $actual): bool
    {
        if ($actual['kind'] !== $expected['kind']) {
            return false;
        }
        if (\preg_match(self::QUOTES_PYTHON_ERROR, $expected['message'], $quoted) === 1) {
            return \str_starts_with($actual['message'], $quoted[1]);
        }
        return $actual['message'] === $expected['message'];
    }

    /** @return list<array{request: string, result: string, expected: array<string, mixed>}> */
    private static function corpus(): array
    {
        $path = \dirname(__DIR__, 2) . '/conformance/result_validation/corpus.json';
        if (!\is_file($path)) {
            self::skip('the shared result-validation corpus is not part of this package');
        }
        $decoded = \json_decode((string)\file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('packvium-result-validation-corpus/v1', $decoded['format']);
        return $decoded['cases'];
    }

    /**
     * @param array<string, true> $set
     * @return list<string>
     */
    private static function sortedKeys(array $set): array
    {
        $keys = \array_keys($set);
        \sort($keys);
        return $keys;
    }
}
