<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

/**
 * One condition over the caller-supplied context: context[field] <op> value (or, for
 * EXISTS/ABSENT, just whether field is present).
 */
final readonly class Predicate
{
    public function __construct(
        public PolicyScope $scope,
        public string $field,
        public PolicyOperator $operator,
        public mixed $value = null,
    ) {
        if ($field === '') { throw new \InvalidArgumentException('field is required'); }
        $unary = $operator === PolicyOperator::EXISTS || $operator === PolicyOperator::ABSENT;
        if (!$unary && $value === null) {
            throw new \InvalidArgumentException("operator '{$operator->value}' requires a value");
        }
    }

    /** @param array<string,mixed> $context */
    public function matches(array $context): bool
    {
        if ($this->operator === PolicyOperator::EXISTS) { return array_key_exists($this->field, $context); }
        if ($this->operator === PolicyOperator::ABSENT) { return !array_key_exists($this->field, $context); }
        if (!array_key_exists($this->field, $context)) { return false; }
        $actual = $context[$this->field];
        return match ($this->operator) {
            PolicyOperator::EQUALS => self::equals($actual, $this->value),
            PolicyOperator::NOT_EQUALS => !self::equals($actual, $this->value),
            PolicyOperator::IN => self::contains($this->value, $actual),
            PolicyOperator::NOT_IN => !self::contains($this->value, $actual),
            default => throw new UnsupportedPredicateException("unsupported policy operator '{$this->operator->value}'"),
        };
    }

    /**
     * Value equality over the JSON scalar types, matching the reference implementation's
     * semantics exactly -- including that a boolean equals the integer it stands for,
     * which is the one place a naive `===` would disagree and quietly change a decision.
     */
    private static function equals(mixed $left, mixed $right): bool
    {
        if (is_bool($left) || is_bool($right)) {
            if (is_bool($left) && is_bool($right)) { return $left === $right; }
            return (is_int($left) || is_int($right)) && (int)$left === (int)$right;
        }
        if (is_array($left) && is_array($right)) {
            if (count($left) !== count($right) || array_keys($left) !== array_keys($right)) { return false; }
            foreach ($left as $key => $entry) {
                if (!self::equals($entry, $right[$key])) { return false; }
            }
            return true;
        }
        if (is_int($left) && is_int($right)) { return $left === $right; }
        if (is_string($left) && is_string($right)) { return $left === $right; }
        return $left === null && $right === null;
    }

    private static function contains(mixed $haystack, mixed $needle): bool
    {
        if (is_string($haystack)) { return is_string($needle) && ($needle === '' || str_contains($haystack, $needle)); }
        if (!is_array($haystack)) {
            throw new UnsupportedPredicateException('an in/not_in predicate needs a list or string value');
        }
        foreach ($haystack as $candidate) {
            if (self::equals($candidate, $needle)) { return true; }
        }
        return false;
    }
}
