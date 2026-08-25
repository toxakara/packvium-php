<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/**
 * One immutable, numbered entry in a catalog's append-only publication history.
 * A rollback is not a mutation: it is a new, higher-numbered version whose snapshot
 * equals a prior one's, recorded via $rolledBackFrom.
 */
final class Version
{
    /**
     * @readonly
     * @var int
     */
    public $number;
    /**
     * @readonly
     * @var \Packvium\Commerce\Catalog\Snapshot
     */
    public $snapshot;
    /**
     * @readonly
     * @var int
     */
    public $effectiveAt;
    /**
     * @readonly
     * @var int
     */
    public $publishedAt;
    /**
     * @readonly
     * @var int|null
     */
    public $rolledBackFrom;
    /**
     * @readonly
     * @var string
     */
    public $note = '';
    public function __construct(
        int $number,
        Snapshot $snapshot,
        int $effectiveAt,
        int $publishedAt,
        ?int $rolledBackFrom = null,
        string $note = ''
    ) {
        $this->number = $number;
        $this->snapshot = $snapshot;
        $this->effectiveAt = $effectiveAt;
        $this->publishedAt = $publishedAt;
        $this->rolledBackFrom = $rolledBackFrom;
        $this->note = $note;
        if ($number <= 0) { throw new \InvalidArgumentException('version number must be positive'); }
        if ($effectiveAt < 0) { throw new \InvalidArgumentException('effective_at cannot be negative'); }
        if ($publishedAt < 0) { throw new \InvalidArgumentException('published_at cannot be negative'); }
        if ($rolledBackFrom !== null && $rolledBackFrom <= 0) {
            throw new \InvalidArgumentException('rolled_back_from must reference a positive version number');
        }
    }
}
