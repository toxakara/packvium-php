<?php
declare(strict_types=1);
namespace Packvium\Commerce;

use Packvium\Commerce\Catalog\CartonMaster;
use Packvium\Commerce\Catalog\CatalogRegistry;
use Packvium\Commerce\Catalog\ExclusionRule;
use Packvium\Commerce\Catalog\ExclusionScope;
use Packvium\Commerce\Catalog\FacilityOverride;
use Packvium\Commerce\Catalog\ItemMaster;
use Packvium\Commerce\Catalog\PalletMaster;
use Packvium\Commerce\Catalog\Snapshot;
use Packvium\Commerce\Policy\PolicyAction;
use Packvium\Commerce\Policy\PolicyOperator;
use Packvium\Commerce\Policy\PolicyRegistry;
use Packvium\Commerce\Policy\PolicyScope;
use Packvium\Commerce\Policy\Predicate;
use Packvium\Commerce\Rating\AccessorialCharge;
use Packvium\Commerce\Rating\CarrierRegistry;

/**
 * Parse one canonical commerce document into the three registries.
 *
 * Does no commercial arithmetic: it validates shape, then hands every field to the
 * rating, policy and catalog models. A version's number is its 1-based position in its
 * versions list, which is how those registries already number a publish().
 *
 * Parsing is strict in both directions -- a missing required key and an unrecognised
 * extra key are both input errors, because a field this contract does not define must
 * never be silently ignored. Complexity: one pass, O(size of the document).
 */
final class Document
{
    /**
     * @readonly
     * @var \Packvium\Commerce\Rating\CarrierRegistry
     */
    public $carriers;
    /**
     * @readonly
     * @var \Packvium\Commerce\Policy\PolicyRegistry
     */
    public $policies;
    /**
     * @var array<string, CatalogRegistry>
     * @readonly
     */
    public $catalogs;
    /** @param array<string,CatalogRegistry> $catalogs */
    private function __construct(CarrierRegistry $carriers, PolicyRegistry $policies, array $catalogs)
    {
        $this->carriers = $carriers;
        $this->policies = $policies;
        $this->catalogs = $catalogs;
    }

    /** @param array<string,mixed> $document */
    public static function load(array $document): self
    {
        Shape::keys($document, 'document', [], ['tariffs', 'policy_rules', 'catalogs']);
        return new self(
            self::loadTariffs($document['tariffs'] ?? []),
            self::loadPolicyRules($document['policy_rules'] ?? []),
            self::loadCatalogs($document['catalogs'] ?? []),
        );
    }

    /**
     * @param mixed $value
     */
    private static function loadTariffs($value): CarrierRegistry
    {
        $registry = new CarrierRegistry();
        $seen = [];
        foreach (Shape::listOf($value, 'document.tariffs') as $index => $entry) {
            $path = "document.tariffs[{$index}]";
            $fields = Shape::map($entry, $path);
            Shape::keys($fields, $path, ['carrier_id', 'service_id', 'versions']);
            $carrierId = Shape::text($fields['carrier_id'], "{$path}.carrier_id");
            $serviceId = Shape::text($fields['service_id'], "{$path}.service_id");
            $key = $carrierId . "\0" . $serviceId;
            if (isset($seen[$key])) { Shape::fail($path, "duplicate tariff history for {$carrierId}/{$serviceId}"); }
            $seen[$key] = true;
            self::publishTariffVersions($registry, $carrierId, $serviceId, $fields['versions'], $path);
        }
        return $registry;
    }

    /**
     * @param mixed $value
     */
    private static function publishTariffVersions(CarrierRegistry $registry, string $carrierId, string $serviceId, $value, string $parent): void
    {
        $versions = Shape::listOf($value, "{$parent}.versions");
        if ($versions === []) { Shape::fail("{$parent}.versions", 'a tariff history needs at least one version'); }
        foreach ($versions as $index => $entry) {
            $path = "{$parent}.versions[{$index}]";
            $fields = Shape::map($entry, $path);
            Shape::keys(
                $fields, $path,
                ['effective_at', 'dimensional_weight_divisor', 'cost_per_dimensional_kg_minor'],
                ['minimum_charge_minor', 'fuel_surcharge_permille', 'accessorials'],
            );
            $costs = [];
            foreach (Shape::map($fields['cost_per_dimensional_kg_minor'], "{$path}.cost_per_dimensional_kg_minor") as $zone => $cost) {
                $costs[(string)$zone] = Shape::integer($cost, "{$path}.cost_per_dimensional_kg_minor[{$zone}]");
            }
            Shape::model($path, static function () use ($registry, $carrierId, $serviceId, $fields, $path, $costs) {
                return $registry->publish(
                    $carrierId, $serviceId,
                    Shape::integer($fields['effective_at'], "{$path}.effective_at"),
                    Shape::integer($fields['dimensional_weight_divisor'], "{$path}.dimensional_weight_divisor"),
                    $costs,
                    Shape::integer($fields['minimum_charge_minor'] ?? 0, "{$path}.minimum_charge_minor"),
                    Shape::integer($fields['fuel_surcharge_permille'] ?? 0, "{$path}.fuel_surcharge_permille"),
                    self::loadAccessorials($fields['accessorials'] ?? [], "{$path}.accessorials"),
                );
            });
        }
    }

    /** @return array<string,AccessorialCharge>
     * @param mixed $value */
    private static function loadAccessorials($value, string $path): array
    {
        $charges = [];
        foreach (Shape::listOf($value, $path) as $index => $entry) {
            $entryPath = "{$path}[{$index}]";
            $fields = Shape::map($entry, $entryPath);
            Shape::keys($fields, $entryPath, ['accessorial_id'], ['flat_charge_minor', 'permille_of_base']);
            $id = Shape::text($fields['accessorial_id'], "{$entryPath}.accessorial_id");
            if (isset($charges[$id])) { Shape::fail($entryPath, "duplicate accessorial_id '{$id}'"); }
            $flat = $fields['flat_charge_minor'] ?? null;
            $permille = $fields['permille_of_base'] ?? null;
            $charges[$id] = Shape::model($entryPath, static function () use ($id, $flat, $entryPath, $permille) {
                return new AccessorialCharge(
                    $id,
                    $flat === null ? null : Shape::integer($flat, "{$entryPath}.flat_charge_minor"),
                    $permille === null ? null : Shape::integer($permille, "{$entryPath}.permille_of_base"),
                );
            });
        }
        return $charges;
    }

    /**
     * @param mixed $value
     */
    private static function loadPolicyRules($value): PolicyRegistry
    {
        $registry = new PolicyRegistry();
        $seen = [];
        foreach (Shape::listOf($value, 'document.policy_rules') as $index => $entry) {
            $path = "document.policy_rules[{$index}]";
            $fields = Shape::map($entry, $path);
            Shape::keys($fields, $path, ['rule_id', 'versions']);
            $ruleId = Shape::text($fields['rule_id'], "{$path}.rule_id");
            if (isset($seen[$ruleId])) { Shape::fail($path, "duplicate rule history for '{$ruleId}'"); }
            $seen[$ruleId] = true;
            self::publishRuleVersions($registry, $ruleId, $fields['versions'], $path);
        }
        return $registry;
    }

    /**
     * @param mixed $value
     */
    private static function publishRuleVersions(PolicyRegistry $registry, string $ruleId, $value, string $parent): void
    {
        $versions = Shape::listOf($value, "{$parent}.versions");
        if ($versions === []) { Shape::fail("{$parent}.versions", 'a rule history needs at least one version'); }
        foreach ($versions as $index => $entry) {
            $path = "{$parent}.versions[{$index}]";
            $fields = Shape::map($entry, $path);
            Shape::keys($fields, $path, ['scope', 'action', 'predicates', 'priority', 'effective_at'], ['reason']);
            Shape::model($path, static function () use ($registry, $ruleId, $fields, $path) {
                return $registry->publish(
                    $ruleId,
                    Shape::enumCase(PolicyScope::class, $fields['scope'], "{$path}.scope"),
                    Shape::enumCase(PolicyAction::class, $fields['action'], "{$path}.action"),
                    self::loadPredicates($fields['predicates'], "{$path}.predicates"),
                    Shape::integer($fields['priority'], "{$path}.priority"),
                    Shape::integer($fields['effective_at'], "{$path}.effective_at"),
                    Shape::text($fields['reason'] ?? '', "{$path}.reason"),
                );
            });
        }
    }

    /** @return list<Predicate>
     * @param mixed $value */
    private static function loadPredicates($value, string $path): array
    {
        $predicates = [];
        foreach (Shape::listOf($value, $path) as $index => $entry) {
            $entryPath = "{$path}[{$index}]";
            $fields = Shape::map($entry, $entryPath);
            Shape::keys($fields, $entryPath, ['scope', 'field', 'operator'], ['value']);
            $predicates[] = Shape::model($entryPath, static function () use ($fields, $entryPath) {
                return new Predicate(
                    Shape::enumCase(PolicyScope::class, $fields['scope'], "{$entryPath}.scope"),
                    Shape::text($fields['field'], "{$entryPath}.field"),
                    Shape::enumCase(PolicyOperator::class, $fields['operator'], "{$entryPath}.operator"),
                    $fields['value'] ?? null,
                );
            });
        }
        return $predicates;
    }

    /** @return array<string,CatalogRegistry>
     * @param mixed $value */
    private static function loadCatalogs($value): array
    {
        $catalogs = [];
        foreach (Shape::listOf($value, 'document.catalogs') as $index => $entry) {
            $path = "document.catalogs[{$index}]";
            $fields = Shape::map($entry, $path);
            Shape::keys($fields, $path, ['catalog_id', 'versions']);
            $catalogId = Shape::text($fields['catalog_id'], "{$path}.catalog_id");
            if (isset($catalogs[$catalogId])) { Shape::fail($path, "duplicate catalog history for '{$catalogId}'"); }
            $registry = Shape::model($path, static function () use ($catalogId) {
                return new CatalogRegistry($catalogId);
            });
            self::publishCatalogVersions($registry, $fields['versions'], $path);
            $catalogs[$catalogId] = $registry;
        }
        return $catalogs;
    }

    /**
     * @param mixed $value
     */
    private static function publishCatalogVersions(CatalogRegistry $registry, $value, string $parent): void
    {
        $versions = Shape::listOf($value, "{$parent}.versions");
        if ($versions === []) { Shape::fail("{$parent}.versions", 'a catalog history needs at least one version'); }
        foreach ($versions as $index => $entry) {
            $path = "{$parent}.versions[{$index}]";
            $fields = Shape::map($entry, $path);
            if (array_key_exists('rollback_to', $fields)) {
                Shape::keys($fields, $path, ['rollback_to', 'published_at'], ['effective_at', 'note']);
                $effectiveAt = ($fields['effective_at'] ?? null) === null
                    ? null : Shape::integer($fields['effective_at'], "{$path}.effective_at");
                Shape::model($path, static function () use ($registry, $fields, $path, $effectiveAt) {
                    return $registry->rollback(
                        Shape::integer($fields['rollback_to'], "{$path}.rollback_to"),
                        Shape::integer($fields['published_at'], "{$path}.published_at"),
                        $effectiveAt,
                        Shape::text($fields['note'] ?? '', "{$path}.note"),
                    );
                });
                continue;
            }
            Shape::keys($fields, $path, ['effective_at', 'published_at', 'snapshot'], ['note']);
            Shape::model($path, static function () use ($registry, $fields, $path) {
                return $registry->publish(
                    self::loadSnapshot($fields['snapshot'], "{$path}.snapshot"),
                    Shape::integer($fields['effective_at'], "{$path}.effective_at"),
                    Shape::integer($fields['published_at'], "{$path}.published_at"),
                    Shape::text($fields['note'] ?? '', "{$path}.note"),
                );
            });
        }
    }

    /**
     * @param mixed $value
     */
    private static function loadSnapshot($value, string $path): Snapshot
    {
        $fields = Shape::map($value, $path);
        Shape::keys($fields, $path, [], ['items', 'cartons', 'pallets', 'exclusions', 'overrides']);
        $collect = static function (string $key, callable $load) use ($fields, $path): array {
            $entries = [];
            foreach (Shape::listOf($fields[$key] ?? [], "{$path}.{$key}") as $index => $entry) {
                $entryPath = "{$path}.{$key}[{$index}]";
                $entries[] = $load(Shape::map($entry, $entryPath), $entryPath);
            }
            return $entries;
        };
        return Shape::model($path, static function () use ($collect) {
            return new Snapshot(
                $collect('items', \Closure::fromCallable([self::class, 'loadItem'])),
                $collect('cartons', \Closure::fromCallable([self::class, 'loadCarton'])),
                $collect('pallets', \Closure::fromCallable([self::class, 'loadPallet'])),
                $collect('exclusions', \Closure::fromCallable([self::class, 'loadExclusion'])),
                $collect('overrides', \Closure::fromCallable([self::class, 'loadOverride'])),
            );
        });
    }

    /** @param array<string,mixed> $fields */
    private static function loadItem(array $fields, string $path): ItemMaster
    {
        Shape::keys($fields, $path, ['id', 'dimensions_mm', 'weight_g'], ['description']);
        return Shape::model($path, static function () use ($fields, $path) {
            return new ItemMaster(
                Shape::text($fields['id'], "{$path}.id"),
                Shape::axes($fields['dimensions_mm'], "{$path}.dimensions_mm", 3),
                Shape::integer($fields['weight_g'], "{$path}.weight_g"),
                Shape::text($fields['description'] ?? '', "{$path}.description"),
            );
        });
    }

    /** @param array<string,mixed> $fields */
    private static function loadCarton(array $fields, string $path): CartonMaster
    {
        Shape::keys($fields, $path, ['id', 'inner_dimensions_mm', 'max_payload_g'], ['cost_minor']);
        return Shape::model($path, static function () use ($fields, $path) {
            return new CartonMaster(
                Shape::text($fields['id'], "{$path}.id"),
                Shape::axes($fields['inner_dimensions_mm'], "{$path}.inner_dimensions_mm", 3),
                Shape::integer($fields['max_payload_g'], "{$path}.max_payload_g"),
                Shape::integer($fields['cost_minor'] ?? 0, "{$path}.cost_minor"),
            );
        });
    }

    /** @param array<string,mixed> $fields */
    private static function loadPallet(array $fields, string $path): PalletMaster
    {
        Shape::keys($fields, $path, ['id', 'deck_dimensions_mm', 'max_payload_g'], ['max_stack_height_mm']);
        $height = $fields['max_stack_height_mm'] ?? null;
        return Shape::model($path, static function () use ($fields, $path, $height) {
            return new PalletMaster(
                Shape::text($fields['id'], "{$path}.id"),
                Shape::axes($fields['deck_dimensions_mm'], "{$path}.deck_dimensions_mm", 2),
                Shape::integer($fields['max_payload_g'], "{$path}.max_payload_g"),
                $height === null ? null : Shape::integer($height, "{$path}.max_stack_height_mm"),
            );
        });
    }

    /** @param array<string,mixed> $fields */
    private static function loadExclusion(array $fields, string $path): ExclusionRule
    {
        Shape::keys($fields, $path, ['id', 'scope', 'subject_id', 'excluded_id'], ['reason']);
        return Shape::model($path, static function () use ($fields, $path) {
            return new ExclusionRule(
                Shape::text($fields['id'], "{$path}.id"),
                Shape::enumCase(ExclusionScope::class, $fields['scope'], "{$path}.scope"),
                Shape::text($fields['subject_id'], "{$path}.subject_id"),
                Shape::text($fields['excluded_id'], "{$path}.excluded_id"),
                Shape::text($fields['reason'] ?? '', "{$path}.reason"),
            );
        });
    }

    /** @param array<string,mixed> $fields */
    private static function loadOverride(array $fields, string $path): FacilityOverride
    {
        Shape::keys($fields, $path, ['id', 'facility_id', 'entry_id', 'kind', 'override']);
        $kind = Shape::text($fields['kind'], "{$path}.kind");
        $payload = Shape::map($fields['override'], "{$path}.override");
        switch ($kind) {
            case 'item':
                $override = self::loadItem($payload, "{$path}.override");
                break;
            case 'carton':
                $override = self::loadCarton($payload, "{$path}.override");
                break;
            case 'pallet':
                $override = self::loadPallet($payload, "{$path}.override");
                break;
            default:
                $override = Shape::fail("{$path}.kind", "expected one of ['carton', 'item', 'pallet']");
                break;
        }
        return Shape::model($path, static function () use ($fields, $path, $override) {
            return new FacilityOverride(
                Shape::text($fields['id'], "{$path}.id"),
                Shape::text($fields['facility_id'], "{$path}.facility_id"),
                Shape::text($fields['entry_id'], "{$path}.entry_id"),
                $override,
            );
        });
    }
}
