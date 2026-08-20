<?php
declare(strict_types=1);
namespace Packvium\Commerce;

use Packvium\Commerce\Catalog\AmbiguousReferenceException;
use Packvium\Commerce\Catalog\CatalogRegistry;
use Packvium\Commerce\Catalog\NoEffectiveVersionException;
use Packvium\Commerce\Catalog\Version;
use Packvium\Commerce\Catalog\VersionNotFoundException as CatalogVersionNotFoundException;
use Packvium\Commerce\Policy\Decision;
use Packvium\Commerce\Policy\PolicyRegistry;
use Packvium\Commerce\Policy\PolicyScope;
use Packvium\Commerce\Policy\Rule;
use Packvium\Commerce\Policy\RuleNotFoundException;
use Packvium\Commerce\Policy\VersionNotFoundException as PolicyVersionNotFoundException;
use Packvium\Commerce\Rating\CarrierRegistry;
use Packvium\Commerce\Rating\RatingRequest;
use Packvium\Commerce\Rating\Tariff;
use Packvium\Commerce\Rating\TariffNotFoundException;
use Packvium\Commerce\Rating\UnavailableServiceException;

/**
 * Packvium's exported commercial and control-plane API.
 *
 * Each entry point loads the caller's commerce document into the rating, policy and
 * catalog registries, asks them the question and serializes the answer. No price,
 * decision or version resolution is computed here. The contract -- request shapes,
 * result shapes, rejection codes, complexity and limitations -- is docs/COMMERCE-API.md,
 * and this port is held to byte-identical canonical output against packvium.commerce.
 *
 * The namespaced functions Packvium\Commerce\quote(), evaluatePolicy() and
 * catalogVersionInfo() in functions.php are the public spelling; these static methods
 * are the same code behind them.
 */
final class Api
{
    public const API_VERSION = 1;

    /** The closed set of rejection codes, in the order docs/COMMERCE-API.md tabulates them. */
    public const REJECTION_CODES = [
        'tariff_not_found',
        'no_effective_tariff',
        'unavailable_zone',
        'unavailable_accessorial',
        'policy_rule_not_found',
        'policy_version_not_found',
        'catalog_not_found',
        'catalog_version_not_found',
        'no_effective_catalog_version',
        'ambiguous_catalog_reference',
    ];

    /**
     * Price one shipment against one pinned or effective-dated tariff version.
     *
     * @param  array<string,mixed> $document
     * @param  array<string,mixed> $request
     * @return array<string,mixed>
     */
    public static function quote(array $document, array $request): array
    {
        $loaded = Document::load($document);
        Shape::keys(
            $request, 'request',
            ['carrier_id', 'service_id', 'zone', 'actual_weight_g', 'volume_mm3'],
            ['tariff_version', 'as_of', 'requested_accessorials'],
        );
        $pin = self::exactlyOne($request, 'request', ['tariff_version', 'as_of']);
        $carrierId = Shape::text($request['carrier_id'], 'request.carrier_id');
        $serviceId = Shape::text($request['service_id'], 'request.service_id');
        $accessorials = [];
        foreach (Shape::listOf($request['requested_accessorials'] ?? [], 'request.requested_accessorials') as $index => $entry) {
            $accessorials[] = Shape::text($entry, "request.requested_accessorials[{$index}]");
        }
        $rating = Shape::model('request', static fn() => new RatingRequest(
            Shape::text($request['zone'], 'request.zone'),
            Shape::integer($request['actual_weight_g'], 'request.actual_weight_g'),
            Shape::integer($request['volume_mm3'], 'request.volume_mm3'),
            $accessorials,
        ));

        try {
            $tariff = self::resolveTariff($loaded->carriers, $carrierId, $serviceId, $request, $pin);
            $breakdown = self::rate($tariff, $rating);
        } catch (Rejection $rejection) {
            return self::rejected($rejection);
        }
        return self::ok('quote', $breakdown->toArray());
    }

    /**
     * Decide one eligibility question against a pinned or effective-dated rule set.
     *
     * @param  array<string,mixed> $document
     * @param  array<string,mixed> $request
     * @return array<string,mixed>
     */
    public static function evaluatePolicy(array $document, array $request): array
    {
        $loaded = Document::load($document);
        Shape::keys($request, 'request', ['scope', 'context'], ['as_of', 'rule_versions']);
        $pin = self::exactlyOne($request, 'request', ['as_of', 'rule_versions']);
        $scope = Shape::enumCase(PolicyScope::class, $request['scope'], 'request.scope');
        $context = Shape::map($request['context'], 'request.context');

        try {
            $decision = self::decide($loaded->policies, $scope, $context, $request, $pin);
        } catch (Rejection $rejection) {
            return self::rejected($rejection);
        }
        return self::ok('decision', $decision->toArray());
    }

    /**
     * Report which catalog version a reference resolves to, and what it contains.
     *
     * @param  array<string,mixed> $document
     * @param  array<string,mixed> $request
     * @return array<string,mixed>
     */
    public static function catalogVersionInfo(array $document, array $request): array
    {
        $loaded = Document::load($document);
        Shape::keys($request, 'request', ['catalog_id', 'resolved_at'], ['version', 'as_of']);
        $catalogId = Shape::text($request['catalog_id'], 'request.catalog_id');
        $resolvedAt = Shape::integer($request['resolved_at'], 'request.resolved_at');
        $version = ($request['version'] ?? null) === null ? null : Shape::integer($request['version'], 'request.version');
        $asOf = ($request['as_of'] ?? null) === null ? null : Shape::integer($request['as_of'], 'request.as_of');
        if ($version !== null && $asOf !== null) { Shape::fail('request', "expected at most one of ['version', 'as_of']"); }

        try {
            $resolved = self::resolveCatalog($loaded->catalogs, $catalogId, $version, $asOf);
        } catch (Rejection $rejection) {
            return self::rejected($rejection);
        }
        return self::ok('catalog', self::catalogPayload($catalogId, $resolved, $resolvedAt));
    }

    /**
     * The one byte-comparable spelling of a result document: sorted keys, no padding.
     *
     * @param array<string,mixed> $result
     */
    public static function canonicalJson(array $result): string
    {
        return (string)json_encode(
            self::sortKeys($result),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    // ------------------------------------------------------------------------ internals

    /**
     * @param  array<string,mixed> $request
     * @param  list<string>        $names
     */
    private static function exactlyOne(array $request, string $path, array $names): string
    {
        $present = array_values(array_filter($names, static fn(string $name): bool => ($request[$name] ?? null) !== null));
        if (count($present) !== 1) {
            Shape::fail($path, "expected exactly one of ['" . implode("', '", $names) . "']");
        }
        return $present[0];
    }

    /** @param array<string,mixed> $request */
    private static function resolveTariff(CarrierRegistry $carriers, string $carrierId, string $serviceId, array $request, string $pin): Tariff
    {
        $identity = ['carrier_id' => $carrierId, 'service_id' => $serviceId];
        if ($pin === 'tariff_version') {
            $version = Shape::integer($request['tariff_version'], 'request.tariff_version');
            try {
                return $carriers->tariff($carrierId, $serviceId, $version);
            } catch (TariffNotFoundException) {
                throw new Rejection('tariff_not_found', $identity + ['tariff_version' => $version]);
            }
        }
        $asOf = Shape::integer($request['as_of'], 'request.as_of');
        try {
            $carriers->versions($carrierId, $serviceId);
        } catch (TariffNotFoundException) {
            throw new Rejection('tariff_not_found', $identity);
        }
        try {
            return $carriers->effectiveTariff($carrierId, $serviceId, $asOf);
        } catch (TariffNotFoundException) {
            throw new Rejection('no_effective_tariff', $identity + ['as_of' => $asOf]);
        }
    }

    private static function rate(Tariff $tariff, RatingRequest $request): Rating\RateBreakdown
    {
        try {
            return CarrierRegistry::rateTariff($tariff, $request);
        } catch (UnavailableServiceException $error) {
            $identity = [
                'carrier_id' => $tariff->carrierId,
                'service_id' => $tariff->serviceId,
                'tariff_version' => $tariff->version,
            ];
            if ($error->zone !== null) {
                throw new Rejection('unavailable_zone', $identity + ['zone' => $error->zone]);
            }
            throw new Rejection('unavailable_accessorial', $identity + ['accessorial_ids' => $error->accessorialIds]);
        }
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $request
     */
    private static function decide(PolicyRegistry $policies, PolicyScope $scope, array $context, array $request, string $pin): Decision
    {
        if ($pin === 'as_of') {
            return $policies->evaluate($scope, $context, Shape::integer($request['as_of'], 'request.as_of'));
        }
        return PolicyRegistry::decide(self::pinnedRules($policies, $request['rule_versions']), $scope, $context);
    }

    /** @return list<Rule> */
    private static function pinnedRules(PolicyRegistry $policies, mixed $value): array
    {
        $pins = [];
        foreach (Shape::listOf($value, 'request.rule_versions') as $index => $entry) {
            $path = "request.rule_versions[{$index}]";
            $pair = Shape::listOf($entry, $path);
            if (count($pair) !== 2) { Shape::fail($path, 'expected a [rule_id, version] pair'); }
            $pins[] = [Shape::text($pair[0], "{$path}[0]"), Shape::integer($pair[1], "{$path}[1]")];
        }
        try {
            return $policies->resolveVersions($pins);
        } catch (\InvalidArgumentException $error) {
            throw new CommerceInputException('request.rule_versions: ' . $error->getMessage(), 0, $error);
        } catch (PolicyVersionNotFoundException $error) {
            throw new Rejection('policy_version_not_found', ['rule_id' => $error->ruleId, 'version' => $error->version]);
        } catch (RuleNotFoundException $error) {
            throw new Rejection('policy_rule_not_found', ['rule_id' => $error->ruleId]);
        }
    }

    /** @param array<string,CatalogRegistry> $catalogs */
    private static function resolveCatalog(array $catalogs, string $catalogId, ?int $version, ?int $asOf): Version
    {
        $registry = $catalogs[$catalogId] ?? null;
        if ($registry === null) { throw new Rejection('catalog_not_found', ['catalog_id' => $catalogId]); }
        $selector = ['catalog_id' => $catalogId];
        if ($version !== null) { $selector['version'] = $version; }
        if ($asOf !== null) { $selector['as_of'] = $asOf; }
        try {
            return $registry->resolve(0, $version, $asOf);
        } catch (CatalogVersionNotFoundException) {
            throw new Rejection('catalog_version_not_found', $selector);
        } catch (NoEffectiveVersionException) {
            throw new Rejection('no_effective_catalog_version', $selector);
        } catch (AmbiguousReferenceException) {
            throw new Rejection('ambiguous_catalog_reference', $selector);
        }
    }

    /** @return array<string,mixed> */
    private static function catalogPayload(string $catalogId, Version $version, int $resolvedAt): array
    {
        $snapshot = $version->snapshot;
        return [
            'catalog_id' => $catalogId,
            'version' => $version->number,
            'effective_at' => $version->effectiveAt,
            'published_at' => $version->publishedAt,
            'resolved_at' => $resolvedAt,
            'rolled_back_from' => $version->rolledBackFrom,
            'note' => $version->note,
            'entry_counts' => [
                'items' => count($snapshot->items),
                'cartons' => count($snapshot->cartons),
                'pallets' => count($snapshot->pallets),
                'exclusions' => count($snapshot->exclusions),
                'overrides' => count($snapshot->overrides),
            ],
            'item_ids' => $snapshot->itemIds(),
            'carton_ids' => $snapshot->cartonIds(),
            'pallet_ids' => $snapshot->palletIds(),
        ];
    }

    /**
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function ok(string $key, array $payload): array
    {
        return ['api_version' => self::API_VERSION, 'status' => 'ok', $key => $payload];
    }

    /** @return array<string,mixed> */
    private static function rejected(Rejection $rejection): array
    {
        return [
            'api_version' => self::API_VERSION,
            'status' => 'rejected',
            'error' => ['code' => $rejection->rejectionCode, 'fields' => $rejection->fields],
        ];
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        if (array_is_list($value)) { return array_map(self::sortKeys(...), $value); }
        ksort($value, SORT_STRING);
        return array_map(self::sortKeys(...), $value);
    }
}
