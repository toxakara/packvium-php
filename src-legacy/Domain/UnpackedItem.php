<?php
declare(strict_types=1);

namespace Packvium\Domain;

final class UnpackedItem
{
    /**
     * @readonly
     * @var \Packvium\Domain\ItemInstance
     */
    public $instance;
    /**
     * @readonly
     * @var string
     */
    public $reason;
    /**
     * @var list<string>
     * @readonly
     */
    public $details = [];
    /**
     * @readonly
     * @var \Packvium\Domain\ReasonProof
     */
    public $proof;

    /** @param list<string> $details */
    public function __construct(
        ItemInstance $instance,
        string $reason,
        array $details = [],
        ?ReasonProof $proof = null
    ) {
        $this->instance = $instance;
        $this->reason = $reason;
        $this->details = $details;
        $this->proof = $proof ?? ReasonProof::forReason($reason, $details);
    }
}
