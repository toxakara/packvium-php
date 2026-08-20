<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/** A facility-specific override of a base master-data entry. */
final readonly class FacilityOverride
{
    public function __construct(
        public string $id,
        public string $facilityId,
        public string $entryId,
        public ItemMaster|CartonMaster|PalletMaster $override,
    ) {
        if ($id === '') { throw new \InvalidArgumentException('facility override id is required'); }
        if ($facilityId === '') { throw new \InvalidArgumentException('facility_id is required'); }
        if ($override->id !== $entryId) { throw new \InvalidArgumentException("a facility override's entry_id must match override.id"); }
    }
}
