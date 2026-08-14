<?php
declare(strict_types=1);

namespace Packvium\Domain;

final readonly class UnpackedItem
{
    public ReasonProof $proof;

    /** @param list<string> $details */
    public function __construct(
        public ItemInstance $instance,
        public string $reason,
        public array $details = [],
        ?ReasonProof $proof = null,
    ) {
        $this->proof = $proof ?? ReasonProof::forReason($reason, $details);
    }
}
