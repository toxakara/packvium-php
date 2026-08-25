<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

/**
 * The outcome of one evaluation: whether the context is admitted for the given scope,
 * and the single rule (if any) whose citation explains why.
 */
final class Decision
{
    /**
     * @readonly
     * @var \Packvium\Commerce\Policy\PolicyScope
     */
    public $scope;
    /**
     * @readonly
     * @var bool
     */
    public $allowed;
    /**
     * @readonly
     * @var \Packvium\Commerce\Policy\Citation|null
     */
    public $citation;
    /**
     * @param mixed $scope
     */
    public function __construct($scope, bool $allowed, ?Citation $citation = null)
    {
        $this->scope = $scope;
        $this->allowed = $allowed;
        $this->citation = $citation;
        if (!$allowed && $citation === null) { throw new \InvalidArgumentException('a REJECT decision must carry a citation'); }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope->value,
            'allowed' => $this->allowed,
            'citation' => ($nullsafeVariable1 = $this->citation) ? $nullsafeVariable1->toArray() : null,
        ];
    }
}
