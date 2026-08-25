<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

/**
 * One condition over the caller-supplied context: context[field] <op> value (or, for
 * EXISTS/ABSENT, just whether field is present).
 */
final class Predicate
{
    /**
     * @readonly
     * @var \Packvium\Commerce\Policy\PolicyScope
     */
    public $scope;
    /**
     * @readonly
     * @var string
     */
    public $field;
    /**
     * @readonly
     * @var \Packvium\Commerce\Policy\PolicyOperator
     */
    public $operator;
    /**
     * @readonly
     * @var mixed
     */
    public $value = null;
    /**
     * @param mixed $value
     * @param mixed $scope
     * @param \Packvium\Commerce\Policy\PolicyOperator::* $operator
     */
    public function __construct(
        $scope,
        string $field,
        $operator,
        $value = null
    ) {
        $this->scope = $scope;
        $this->field = $field;
        $this->operator = $operator;
        $this->value = $value;
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
        switch ($this->operator) {
            case PolicyOperator::EQUALS:
                return self::equals($actual, $this->value);
            case PolicyOperator::NOT_EQUALS:
                return !self::equals($actual, $this->value);
            case PolicyOperator::IN:
                return self::contains($this->value, $actual);
            case PolicyOperator::NOT_IN:
                return !self::contains($this->value, $actual);
            default:
                throw new UnsupportedPredicateException("unsupported policy operator '{$this->operator->value}'");
        }
    }

    /**
     * Value equality over the JSON scalar types, matching the reference implementation's
     * semantics exactly -- including that a boolean equals the integer it stands for,
     * which is the one place a naive `===` would disagree and quietly change a decision.
     * @param mixed $left
     * @param mixed $right
     */
    private static function equals($left, $right): bool
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

    /**
     * @param mixed $haystack
     * @param mixed $needle
     */
    private static function contains($haystack, $needle): bool
    {
        if (is_string($haystack)) { return is_string($needle) && ($needle === '' || strpos($haystack, $needle) !== false); }
        if (!is_array($haystack)) {
            throw new UnsupportedPredicateException('an in/not_in predicate needs a list or string value');
        }
        foreach ($haystack as $candidate) {
            if (self::equals($candidate, $needle)) { return true; }
        }
        return false;
    }
}
