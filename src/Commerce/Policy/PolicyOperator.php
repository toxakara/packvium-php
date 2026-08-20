<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

/** The closed set of predicate comparisons. Anything outside it fails admission. */
enum PolicyOperator: string
{
    case EQUALS = 'equals';
    case NOT_EQUALS = 'not_equals';
    case IN = 'in';
    case NOT_IN = 'not_in';
    case EXISTS = 'exists';
    case ABSENT = 'absent';
}
