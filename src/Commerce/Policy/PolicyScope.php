<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

/** The named eligibility domains this engine understands. */
enum PolicyScope: string
{
    case FACILITY = 'facility';
    case CUSTOMER = 'customer';
    case CARRIER = 'carrier';
    case MATERIAL = 'material';
    case HAZMAT = 'hazmat';
    case TEMPERATURE = 'temperature';
    case SERVICE = 'service';
}
