<?php
declare(strict_types=1);

namespace Packvium\Domain;

final readonly class RejectionObservation
{
    /** @param list<string> $details */
    public function __construct(
        public string $code,
        public int $count = 1,
        public array $details = [],
    ) {}

    public function toArray(): array
    {
        return ['code' => $this->code, 'count' => $this->count, 'details' => $this->details];
    }
}
