<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/** A facility-specific override of a base master-data entry. */
final class FacilityOverride
{
    /**
     * @readonly
     * @var string
     */
    public $id;
    /**
     * @readonly
     * @var string
     */
    public $facilityId;
    /**
     * @readonly
     * @var string
     */
    public $entryId;
    /**
     * @readonly
     * @var \Packvium\Commerce\Catalog\ItemMaster|\Packvium\Commerce\Catalog\CartonMaster|\Packvium\Commerce\Catalog\PalletMaster
     */
    public $override;
    /**
     * @param \Packvium\Commerce\Catalog\ItemMaster|\Packvium\Commerce\Catalog\CartonMaster|\Packvium\Commerce\Catalog\PalletMaster $override
     */
    public function __construct(
        string $id,
        string $facilityId,
        string $entryId,
        $override
    ) {
        $this->id = $id;
        $this->facilityId = $facilityId;
        $this->entryId = $entryId;
        $this->override = $override;
        if ($id === '') { throw new \InvalidArgumentException('facility override id is required'); }
        if ($facilityId === '') { throw new \InvalidArgumentException('facility_id is required'); }
        if ($override->id !== $entryId) { throw new \InvalidArgumentException("a facility override's entry_id must match override.id"); }
    }
}
