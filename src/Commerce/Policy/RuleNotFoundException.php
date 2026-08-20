<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

/** No rule is registered under the given rule id. */
final class RuleNotFoundException extends PolicyException
{
    public function __construct(string $message, public readonly string $ruleId)
    {
        parent::__construct($message);
    }
}
