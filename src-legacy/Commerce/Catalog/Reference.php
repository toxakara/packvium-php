<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/** A pinned pointer to one concrete, immutable catalog version. */
final class Reference
{
    /**
     * @readonly
     * @var string
     */
    public $catalogId;
    /**
     * @readonly
     * @var int
     */
    public $version;
    /**
     * @readonly
     * @var int
     */
    public $effectiveAt;
    /**
     * @readonly
     * @var int
     */
    public $resolvedAt;
    public function __construct(string $catalogId, int $version, int $effectiveAt, int $resolvedAt)
    {
        $this->catalogId = $catalogId;
        $this->version = $version;
        $this->effectiveAt = $effectiveAt;
        $this->resolvedAt = $resolvedAt;
        if ($catalogId === '') { throw new \InvalidArgumentException('catalog_id is required'); }
        if ($version <= 0) { throw new \InvalidArgumentException('version must be positive'); }
        if ($effectiveAt < 0) { throw new \InvalidArgumentException('effective_at cannot be negative'); }
        if ($resolvedAt < 0) { throw new \InvalidArgumentException('resolved_at cannot be negative'); }
    }
}
