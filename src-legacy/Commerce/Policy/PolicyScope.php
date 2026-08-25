<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

class PolicyScope
{
    public const FACILITY = 'facility';
    public const CUSTOMER = 'customer';
    public const CARRIER = 'carrier';
    public const MATERIAL = 'material';
    public const HAZMAT = 'hazmat';
    public const TEMPERATURE = 'temperature';
    public const SERVICE = 'service';
}
