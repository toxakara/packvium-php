<?php
declare(strict_types=1);

namespace Packvium\Domain;

final class RejectionObservation
{
    /**
     * @readonly
     * @var string
     */
    public $code;
    /**
     * @readonly
     * @var int
     */
    public $count = 1;
    /**
     * @var list<string>
     * @readonly
     */
    public $details = [];
    /** @param list<string> $details */
    public function __construct(string $code, int $count = 1, array $details = [])
    {
        $this->code = $code;
        $this->count = $count;
        $this->details = $details;
    }

    public function toArray(): array
    {
        return ['code' => $this->code, 'count' => $this->count, 'details' => $this->details];
    }
}
