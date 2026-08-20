<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/**
 * The complete, immutable content of one catalog version. Immutability is what makes
 * concurrent publication safe: a snapshot already handed out can never be reached back
 * into by a later publish().
 */
final readonly class Snapshot
{
    /**
     * @param list<ItemMaster>       $items
     * @param list<CartonMaster>     $cartons
     * @param list<PalletMaster>     $pallets
     * @param list<ExclusionRule>    $exclusions
     * @param list<FacilityOverride> $overrides
     */
    public function __construct(
        public array $items = [],
        public array $cartons = [],
        public array $pallets = [],
        public array $exclusions = [],
        public array $overrides = [],
    ) {
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
        $ids = array_map(static fn(object $entry): string => $entry->id, $entries);
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** @param list<object> $entries */
    private static function requireUniqueIds(string $label, array $entries): void
    {
        $ids = array_map(static fn(object $entry): string => $entry->id, $entries);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new \InvalidArgumentException("duplicate {$label} ids in catalog snapshot");
        }
    }
}
