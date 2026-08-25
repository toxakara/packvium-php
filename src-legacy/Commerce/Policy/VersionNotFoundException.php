<?php
declare(strict_types=1);
namespace Packvium\Commerce\Policy;

/** An explicitly referenced rule version number does not exist in that rule's history. */
final class VersionNotFoundException extends PolicyException
{
    /**
     * @readonly
     * @var string
     */
    public $ruleId;
    /**
     * @readonly
     * @var int
     */
    public $version;
    public function __construct(string $message, string $ruleId, int $version)
    {
        $this->ruleId = $ruleId;
        $this->version = $version;
        parent::__construct($message);
    }
}
