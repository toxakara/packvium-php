<?php
declare(strict_types=1);

namespace Packvium\Domain;

final class ReasonProof
{
    /**
     * @readonly
     * @var string
     */
    public $level;
    /**
     * @var list<RejectionObservation>
     * @readonly
     */
    public $observations;
    /** @param list<RejectionObservation> $observations */
    public function __construct(string $level, array $observations)
    {
        $this->level = $level;
        $this->observations = $observations;
    }

    /** @param list<string> $details */
    public static function forReason(string $reason, array $details = []): self
    {
        switch (true) {
            case in_array($reason, ['no_compatible_container_dimensions', 'payload_exceeded', 'no_eligible_container', 'rotation_restricted', 'policy_rule'], true):
                $level = 'proven';
                break;
            case in_array($reason, ['time_limit', 'effort_limit'], true):
                $level = 'unknown_due_to_limit';
                break;
            case in_array($reason, ['no_feasible_placement', 'search_exhausted', 'exact_search_incomplete', 'insufficient_support'], true):
                $level = 'observed';
                break;
            default:
                $level = 'inferred';
                break;
        }
        return new self($level, [new RejectionObservation($reason, 1, $details)]);
    }

    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'observations' => array_map(
                static function (RejectionObservation $observation): array {
                    return $observation->toArray();
                },
                $this->observations,
            ),
        ];
    }
}
