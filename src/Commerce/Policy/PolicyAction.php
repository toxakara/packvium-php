<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

enum PolicyAction: string
{
    case ALLOW = 'allow';
    case REJECT = 'reject';
}
