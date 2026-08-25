<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

/** No rule is registered under the given rule id. */
final class RuleNotFoundException extends PolicyException
{
    /**
     * @readonly
     * @var string
     */
    public $ruleId;
    public function __construct(string $message, string $ruleId)
    {
        $this->ruleId = $ruleId;
        parent::__construct($message);
    }
}
