<?php
declare(strict_types=1);
namespace Packvium\Tests;

use function Packvium\Commerce\evaluatePolicy;

final class CommercePolicyValuesTest extends TestCase
{
    public static function testStructuredValuesKeepTheSameDecisionAcrossPorts(): void
    {
        foreach ([
            ['equals', ['a' => [1, ['flag' => true]], 'b' => null],
                ['b' => null, 'a' => [true, ['flag' => 1]]], false],
            ['not_equals', ['a' => null], ['b' => null], false],
            ['equals', ['a' => null], ['b' => null], true],
            ['equals', ['a' => [1, 2]], ['a' => [2, 1]], true],
            ['in', [['a' => 1, 'b' => true]], ['b' => 1, 'a' => true], false],
            ['not_in', [['a' => 1]], ['a' => 2], false],
            ['in', ['a' => null], 'a', false],
            ['in', ['a' => 'b'], 'b', true],
            ['not_in', ['a' => 1], 'missing', false],
            ['in', ['a' => 1], ['a'], true],
        ] as [$operator, $value, $actual, $allowed]) {
            $decision = self::decision($operator, $value, ['x' => $actual]);
            self::assertSame($allowed, $decision['allowed'], $operator . ' structured value');
            self::assertSame($allowed, $decision['citation'] === null);
        }
    }

    public static function testMissingFieldsNeverMatchBinaryOperators(): void
    {
        foreach (['equals', 'not_equals', 'in', 'not_in'] as $operator) {
            self::assertSame(null, self::decision($operator, ['a' => 1], [])['citation']);
        }
    }

    private static function decision(string $operator, mixed $value, array $context): array
    {
        $document = ['policy_rules' => [['rule_id' => 'restriction', 'versions' => [[
            'scope' => 'facility', 'action' => 'reject', 'priority' => 1, 'effective_at' => 0,
            'predicates' => [['scope' => 'facility', 'field' => 'x',
                'operator' => $operator, 'value' => $value]],
        ]]]]];
        return evaluatePolicy($document, ['scope' => 'facility', 'context' => $context, 'as_of' => 0])['decision'];
    }
}
