<?php
declare(strict_types=1);
namespace Packvium\Commerce;

/**
 * The public function spelling of Packvium's exported commercial and control-plane API.
 *
 * Loaded two ways, and it has to survive both happening in one process. Composer's
 * `autoload.files` uses a plain `require`, while `packvium-php/autoload.php` uses
 * `require_once`; a consumer that loads the source autoloader first and Composer's
 * second would otherwise hit a fatal redeclaration, because `require_once` marks the
 * path as included but Composer tracks its own identifier and requires it anyway. The
 * `function_exists` guard is what makes the order irrelevant.
 *
 * Each function is the same code as the matching {@see Api} method; see that class and
 * docs/COMMERCE-API.md for the contract.
 */

if (!\function_exists(__NAMESPACE__ . '\\quote')) {
    /**
     * Price one shipment against one pinned or effective-dated tariff version.
     *
     * ```php
     * $document = ['tariffs' => [[
     *     'carrier_id' => 'acme', 'service_id' => 'ground',
     *     'versions' => [['effective_at' => 0, 'dimensional_weight_divisor' => 5000,
     *                     'cost_per_dimensional_kg_minor' => ['zone-a' => 450]]],
     * ]]];
     * $result = Packvium\Commerce\quote($document, [
     *     'carrier_id' => 'acme', 'service_id' => 'ground', 'tariff_version' => 1,
     *     'zone' => 'zone-a', 'actual_weight_g' => 2000, 'volume_mm3' => 1_000_000,
     * ]);
     * $result['quote']['total_minor']; // 900
     * ```
     *
     * @param  array<string,mixed> $document
     * @param  array<string,mixed> $request
     * @return array<string,mixed>
     * @throws CommerceInputException when the document or request is malformed
     */
    function quote(array $document, array $request): array
    {
        return Api::quote($document, $request);
    }

    /**
     * Decide one eligibility question against a pinned or effective-dated rule set.
     *
     * ```php
     * $result = Packvium\Commerce\evaluatePolicy($document, [
     *     'scope' => 'hazmat', 'context' => ['un_class' => '1.4'], 'as_of' => 0,
     * ]);
     * $result['decision']['allowed'];              // false
     * $result['decision']['citation']['rule_id'];  // the rule that said so
     * ```
     *
     * @param  array<string,mixed> $document
     * @param  array<string,mixed> $request
     * @return array<string,mixed>
     * @throws CommerceInputException when the document or request is malformed
     */
    function evaluatePolicy(array $document, array $request): array
    {
        return Api::evaluatePolicy($document, $request);
    }

    /**
     * Report which catalog version a reference resolves to, and what it contains.
     *
     * ```php
     * $result = Packvium\Commerce\catalogVersionInfo($document, [
     *     'catalog_id' => 'dc-12', 'version' => 2, 'resolved_at' => 1700,
     * ]);
     * $result['catalog']['rolled_back_from']; // 1 when this version reverted another
     * ```
     *
     * @param  array<string,mixed> $document
     * @param  array<string,mixed> $request
     * @return array<string,mixed>
     * @throws CommerceInputException when the document or request is malformed
     */
    function catalogVersionInfo(array $document, array $request): array
    {
        return Api::catalogVersionInfo($document, $request);
    }

    /**
     * The one byte-comparable spelling of a result document: sorted keys, no padding.
     *
     * Use it to store, log or compare a result. Two callers that agree on the answer
     * produce the same bytes; two that do not, cannot look as if they do.
     *
     * @param array<string,mixed> $result
     */
    function canonicalJson(array $result): string
    {
        return Api::canonicalJson($result);
    }
}
