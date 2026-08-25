<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

class PolicyOperator
{
    public const EQUALS = 'equals';
    public const NOT_EQUALS = 'not_equals';
    public const IN = 'in';
    public const NOT_IN = 'not_in';
    public const EXISTS = 'exists';
    public const ABSENT = 'absent';
}
