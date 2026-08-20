<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/**
 * One immutable, numbered entry in a catalog's append-only publication history.
 * A rollback is not a mutation: it is a new, higher-numbered version whose snapshot
 * equals a prior one's, recorded via $rolledBackFrom.
 */
final readonly class Version
{
    public function __construct(
        public int $number,
        public Snapshot $snapshot,
        public int $effectiveAt,
        public int $publishedAt,
        public ?int $rolledBackFrom = null,
        public string $note = '',
    ) {
        if ($number <= 0) { throw new \InvalidArgumentException('version number must be positive'); }
        if ($effectiveAt < 0) { throw new \InvalidArgumentException('effective_at cannot be negative'); }
        if ($publishedAt < 0) { throw new \InvalidArgumentException('published_at cannot be negative'); }
        if ($rolledBackFrom !== null && $rolledBackFrom <= 0) {
            throw new \InvalidArgumentException('rolled_back_from must reference a positive version number');
        }
    }
}
