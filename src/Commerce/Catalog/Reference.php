<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/** A pinned pointer to one concrete, immutable catalog version. */
final readonly class Reference
{
    public function __construct(public string $catalogId, public int $version, public int $effectiveAt, public int $resolvedAt)
    {
        if ($catalogId === '') { throw new \InvalidArgumentException('catalog_id is required'); }
        if ($version <= 0) { throw new \InvalidArgumentException('version must be positive'); }
        if ($effectiveAt < 0) { throw new \InvalidArgumentException('effective_at cannot be negative'); }
        if ($resolvedAt < 0) { throw new \InvalidArgumentException('resolved_at cannot be negative'); }
    }
}
