<?php
declare(strict_types=1);

namespace Packvium\Domain;

final readonly class ReasonProof
{
    /** @param list<RejectionObservation> $observations */
    public function __construct(public string $level, public array $observations) {}

    /** @param list<string> $details */
    public static function forReason(string $reason, array $details = []): self
    {
        $level = match (true) {
            // `policy_rule` is proven, not inferred: it is only ever reported when a rule
            // rules the item out of *every* offered container, which is a statement about
            // the request that no search outcome can change.
            in_array($reason, ['no_compatible_container_dimensions', 'payload_exceeded', 'no_eligible_container', 'rotation_restricted', 'policy_rule'], true) => 'proven',
            in_array($reason, ['time_limit', 'effort_limit'], true) => 'unknown_due_to_limit',
            in_array($reason, ['no_feasible_placement', 'search_exhausted', 'exact_search_incomplete', 'insufficient_support'], true) => 'observed',
            default => 'inferred',
        };
        return new self($level, [new RejectionObservation($reason, 1, $details)]);
    }

    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'observations' => array_map(
                static fn(RejectionObservation $observation): array => $observation->toArray(),
                $this->observations,
            ),
        ];
    }
}
