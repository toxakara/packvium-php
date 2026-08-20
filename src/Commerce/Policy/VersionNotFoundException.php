<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

/** An explicitly referenced rule version number does not exist in that rule's history. */
final class VersionNotFoundException extends PolicyException
{
    public function __construct(string $message, public readonly string $ruleId, public readonly int $version)
    {
        parent::__construct($message);
    }
}
