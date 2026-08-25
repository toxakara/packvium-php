<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/**
 * The complete, immutable content of one catalog version. Immutability is what makes
 * concurrent publication safe: a snapshot already handed out can never be reached back
 * into by a later publish().
 */
final class Snapshot
{
    /**
     * @var list<ItemMaster>
     * @readonly
     */
    public $items = [];
    /**
     * @var list<CartonMaster>
     * @readonly
     */
    public $cartons = [];
    /**
     * @var list<PalletMaster>
     * @readonly
     */
    public $pallets = [];
    /**
     * @var list<ExclusionRule>
     * @readonly
     */
    public $exclusions = [];
    /**
     * @var list<FacilityOverride>
     * @readonly
     */
    public $overrides = [];
    /**
     * @param list<ItemMaster>       $items
     * @param list<CartonMaster>     $cartons
     * @param list<PalletMaster>     $pallets
     * @param list<ExclusionRule>    $exclusions
     * @param list<FacilityOverride> $overrides
     */
    public function __construct(
        array $items = [],
        array $cartons = [],
        array $pallets = [],
        array $exclusions = [],
        array $overrides = []
    ) {
        $this->items = $items;
        $this->cartons = $cartons;
        $this->pallets = $pallets;
        $this->exclusions = $exclusions;
        $this->overrides = $overrides;
        self::requireUniqueIds('item', $items);
        self::requireUniqueIds('carton', $cartons);
        self::requireUniqueIds('pallet', $pallets);
        self::requireUniqueIds('exclusion', $exclusions);
        self::requireUniqueIds('facility override', $overrides);
    }

    /** @return list<string> Item ids, sorted ascending by code point. */
    public function itemIds(): array { return self::sortedIds($this->items); }

    /** @return list<string> Carton ids, sorted ascending by code point. */
    public function cartonIds(): array { return self::sortedIds($this->cartons); }

    /** @return list<string> Pallet ids, sorted ascending by code point. */
    public function palletIds(): array { return self::sortedIds($this->pallets); }

    /**
     * @param  list<object> $entries
     * @return list<string>
     */
    private static function sortedIds(array $entries): array
    {
        $ids = array_map(static function (object $entry): string {
            return $entry->id;
        }, $entries);
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** @param list<object> $entries */
    private static function requireUniqueIds(string $label, array $entries): void
    {
        $ids = array_map(static function (object $entry): string {
            return $entry->id;
        }, $entries);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new \InvalidArgumentException("duplicate {$label} ids in catalog snapshot");
        }
    }
}
