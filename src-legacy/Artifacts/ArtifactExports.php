<?php
declare(strict_types=1);
namespace Packvium\Artifacts;

use Packvium\Support\JsonValue;

/**
 * JSON, CSV and print-ready work orders from an operational artifact.
 *
 * Held byte-identical to `packvium.artifact_exports`. Each export is a pure function of the
 * artifact: no solver, carrier, renderer or clock, so an export is replayable from the
 * artifact it came from and four engines emit the same bytes.
 *
 * - JSON is the artifact's RFC 8785 canonical form.
 * - CSV has one row per packing step and one per unplaced item. It is RFC 4180: a header row,
 *   CRLF after every row, and a field quoted only when it holds a comma, quote, CR or LF.
 * - The work order is one self-contained HTML document: inline print styles, no script, no
 *   external resource, entities for the few typographic characters.
 *
 * Values are copied, never re-rendered or reinterpreted. That includes a CSV field beginning
 * with `=`: prefixing it would change an identifier a warehouse system matches on. Only a
 * string, an integer or null is written as text; a boolean, a float, an array or an object
 * has a different default spelling in each language and is refused with `invalid_value`.
 *
 * Every export takes the artifact {@see OperationalArtifact::build()} returned, or one read
 * back with {@see OperationalArtifact::parse()}. O(N) for an artifact of N values.
 */
final class ArtifactExports
{
    public const CSV_COLUMNS = [
        'record', 'container_index', 'container_type', 'sequence', 'item_type', 'orientation',
        'x_ticks', 'y_ticks', 'z_ticks', 'x', 'y', 'z', 'length', 'width', 'height', 'length_unit',
        'reason', 'proof_level',
    ];

    private const CSV_SPECIAL = ",\"\r\n";

    private const STYLE = 'body{font-family:system-ui,sans-serif;margin:24px;color:#111}'
        . 'h1{font-size:20px}h2{font-size:16px;margin-top:24px}'
        . 'table{border-collapse:collapse;width:100%;margin:8px 0 16px}'
        . 'th,td{border:1px solid #999;padding:4px 6px;text-align:left;font-size:12px;vertical-align:top}'
        . 'th{background:#eee}'
        . '.facts td:first-child{width:28%;font-weight:600}'
        . '@media print{body{margin:0}section.container{break-after:page}tr{break-inside:avoid}}';

    private const DASH = '&mdash;';

    private const HTML_ENTITIES = ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;'];

    /**
     * @param mixed $artifact
     * @throws OperationalArtifactException
     */
    public static function json($artifact): string
    {
        return OperationalArtifact::canonicalJson(self::requireArtifact($artifact));
    }

    /**
     * @param mixed $artifact
     * @throws OperationalArtifactException
     */
    public static function csv($artifact): string
    {
        $document = self::requireArtifact($artifact);
        $workOrder = self::field($document, 'work_order');
        $rows = [self::CSV_COLUMNS];
        foreach (self::items(self::field($workOrder, 'containers')) as $container) {
            foreach (self::items(self::field($container, 'lines')) as $line) {
                $rows[] = self::stepRow($workOrder, $container, $line);
            }
        }
        foreach (self::items(self::field(self::field($document, 'plan'), 'unplaced')) as $unplaced) {
            $facts = self::field($unplaced, 'facts');
            $rows[] = [
                'unplaced', null, null, null, self::field($facts, 'item_type'),
                null, null, null, null, null, null, null, null, null, null, null,
                self::field($facts, 'reason'), self::field($facts, 'proof_level'),
            ];
        }
        $text = '';
        foreach ($rows as $row) {
            $fields = [];
            foreach ($row as $value) {
                $fields[] = self::csvField($value);
            }
            $text .= \implode(',', $fields) . "\r\n";
        }
        return $text;
    }

    /**
     * @param mixed $artifact
     * @throws OperationalArtifactException
     */
    public static function workOrderHtml($artifact): string
    {
        $document = self::requireArtifact($artifact);
        $plan = self::field($document, 'plan');
        $workOrder = self::field($document, 'work_order');
        $provenance = self::field($document, 'provenance');
        $lines = [
            '<!DOCTYPE html>',
            '<html lang="en">',
            '<head>',
            '<meta charset="utf-8">',
            '<title>Packing work order</title>',
            '<style>' . self::STYLE . '</style>',
            '</head>',
            '<body>',
            '<h1>Packing work order</h1>',
            '<table class="facts">',
        ];
        self::append($lines, self::factRows($document, $plan, $provenance));
        $lines[] = '</table>';
        $planContainers = self::items(self::field($plan, 'containers'));
        $workOrderContainers = self::items(self::field($workOrder, 'containers'));
        // As the reference's `zip`: one section per pair, as many as the shorter list holds.
        $sections = \min(\count($planContainers), \count($workOrderContainers));
        for ($index = 0; $index < $sections; $index++) {
            self::append($lines, self::containerSection($planContainers[$index], $workOrderContainers[$index], $workOrder));
        }
        self::append($lines, self::unplacedSection(self::items(self::field($plan, 'unplaced'))));
        $lines[] = '</body>';
        $lines[] = '</html>';
        return \implode("\n", $lines) . "\n";
    }

    // --------------------------------------------------------------------------------- CSV

    /**
     * @param mixed $workOrder
     * @param mixed $container
     * @param mixed $line
     * @return list<mixed>
     */
    private static function stepRow($workOrder, $container, $line): array
    {
        $reference = self::field($line, 'placement');
        $ticks = self::field($reference, 'position_ticks');
        $position = self::field($line, 'position');
        $dimensions = self::field($line, 'dimensions');
        return [
            'step', self::field($reference, 'container_index'), self::field($container, 'container_type'),
            JsonValue::get($line, 'sequence'),
            self::field($reference, 'item_type'), self::field($reference, 'orientation'),
            self::field($ticks, 'x'), self::field($ticks, 'y'), self::field($ticks, 'z'),
            self::field($position, 'x'), self::field($position, 'y'), self::field($position, 'z'),
            self::field($dimensions, 'length'), self::field($dimensions, 'width'), self::field($dimensions, 'height'),
            self::field($workOrder, 'length_unit'),
            null, null,
        ];
    }

    /** @param mixed $value */
    private static function csvField($value): string
    {
        $text = self::text($value);
        if (\strpbrk($text, self::CSV_SPECIAL) === false) {
            return $text;
        }
        return '"' . \str_replace('"', '""', $text) . '"';
    }

    // -------------------------------------------------------------------------------- HTML

    /**
     * @param mixed $document
     * @param mixed $plan
     * @param mixed $provenance
     * @return list<string>
     */
    private static function factRows($document, $plan, $provenance): array
    {
        $facts = self::field($plan, 'facts');
        $replay = self::field($provenance, 'replay');
        $solver = self::field($provenance, 'solver');
        $feasibility = self::field($facts, 'feasibility');
        $because = self::field($replay, 'because');
        $replayText = self::escape(self::field($replay, 'level'))
            . (self::isTruthy($because) ? ' (' . self::escape($because) . ')' : '');
        $solverText = self::DASH;
        if (self::isTruthy($solver)) {
            $solverText = self::escape(self::field($solver, 'profile')) . ' / '
                . self::escape(self::field($solver, 'solver')) . ' / seed ' . self::escape(self::field($solver, 'seed'));
        }
        $catalogs = [];
        foreach (self::items(self::field($provenance, 'catalog_versions_used')) as $catalog) {
            $catalogs[] = self::escape(JsonValue::get($catalog, 'catalog_id')) . ' v' . self::escape(JsonValue::get($catalog, 'version'));
        }
        $catalogText = \implode(', ', $catalogs);
        $terms = [];
        foreach (self::items(self::field($facts, 'score')) as $term) {
            $terms[] = self::escape($term);
        }
        $rows = [
            ['Status', self::escape(self::field($facts, 'status'))],
            ['Objective', self::orDash(self::field($plan, 'objective'))],
            ['Containers', self::escape(self::field($facts, 'container_count'))],
            ['Score', '[' . \implode(', ', $terms) . ']'],
            ['Feasibility', self::orDash(JsonValue::isObject($feasibility) ? JsonValue::get($feasibility, 'code') : null)],
            ['Replay', $replayText],
            ['Solver', $solverText],
            ['Catalogs', $catalogText !== '' ? $catalogText : self::DASH],
            ['Packvium', self::escape(self::field($document, 'suite_version'))],
        ];
        $html = [];
        foreach ($rows as [$label, $value]) {
            $html[] = "<tr><td>{$label}</td><td>{$value}</td></tr>";
        }
        return $html;
    }

    /**
     * @param mixed $planContainer
     * @param mixed $container
     * @param mixed $workOrder
     * @return list<string>
     */
    private static function containerSection($planContainer, $container, $workOrder): array
    {
        $facts = self::field($planContainer, 'facts');
        $weightUnit = self::escape(self::field($workOrder, 'weight_unit'));
        $lengthUnit = self::escape(self::field($workOrder, 'length_unit'));
        $lines = [
            '<section class="container">',
            '<h2>Container ' . self::successor(self::field($container, 'container_index')) . ': '
                . self::orDash(self::field($container, 'container_type')) . '</h2>',
            '<p>Payload ' . self::escape(self::field($container, 'payload_weight')) . " {$weightUnit} &middot; "
                . 'Gross ' . self::escape(self::field($container, 'gross_weight')) . " {$weightUnit} &middot; "
                . 'Utilization ' . self::orDash(self::field($facts, 'volume_utilization')) . '</p>',
        ];
        if (self::field($planContainer, 'order') === 'loading') {
            $lines[] = '<p>Order: loading. Follow the steps in sequence.</p>';
        } else {
            $lines[] = '<p>Order: unavailable. No safe loading order was supplied, so the steps are not numbered.</p>';
        }
        $lines[] = '<table>';
        $lines[] = "<thead><tr><th>Step</th><th>Item</th><th>Orientation</th><th>Position x, y, z ({$lengthUnit})</th>"
            . "<th>Size l &times; w &times; h ({$lengthUnit})</th><th>Done</th></tr></thead>";
        $lines[] = '<tbody>';
        foreach (self::items(self::field($container, 'lines')) as $line) {
            $lines[] = self::stepHtmlRow($line);
        }
        $lines[] = '</tbody>';
        $lines[] = '</table>';
        $lines[] = '</section>';
        return $lines;
    }

    /** @param mixed $line */
    private static function stepHtmlRow($line): string
    {
        $reference = self::field($line, 'placement');
        $position = self::field($line, 'position');
        $size = self::field($line, 'dimensions');
        $sequence = JsonValue::has($line, 'sequence') ? JsonValue::get($line, 'sequence') : '';
        return '<tr><td>' . self::escape($sequence) . '</td><td>' . self::escape(self::field($reference, 'item_type')) . '</td>'
            . '<td>' . self::escape(self::field($reference, 'orientation')) . '</td>'
            . '<td>' . self::escape(self::field($position, 'x')) . ', ' . self::escape(self::field($position, 'y'))
            . ', ' . self::escape(self::field($position, 'z')) . '</td>'
            . '<td>' . self::escape(self::field($size, 'length')) . ' &times; ' . self::escape(self::field($size, 'width'))
            . ' &times; ' . self::escape(self::field($size, 'height')) . '</td>'
            . '<td>&#9744;</td></tr>';
    }

    /**
     * @param list<mixed> $entries
     * @return list<string>
     */
    private static function unplacedSection(array $entries): array
    {
        $lines = ['<section>', '<h2>Not packed</h2>'];
        if ($entries === []) {
            $lines[] = '<p>Every item was packed.</p>';
            $lines[] = '</section>';
            return $lines;
        }
        $lines[] = '<table>';
        $lines[] = '<thead><tr><th>Item</th><th>Reason</th><th>Proof</th></tr></thead>';
        $lines[] = '<tbody>';
        foreach ($entries as $entry) {
            $facts = self::field($entry, 'facts');
            $lines[] = '<tr><td>' . self::orDash(self::field($facts, 'item_type')) . '</td><td>'
                . self::orDash(self::field($facts, 'reason')) . '</td><td>'
                . self::orDash(self::field($facts, 'proof_level')) . '</td></tr>';
        }
        $lines[] = '</tbody>';
        $lines[] = '</table>';
        $lines[] = '</section>';
        return $lines;
    }

    /** @param mixed $value */
    private static function escape($value): string
    {
        return \strtr(self::text($value), self::HTML_ENTITIES);
    }

    /** @param mixed $value */
    private static function orDash($value): string
    {
        return $value === null ? self::DASH : self::escape($value);
    }

    /**
     * A container's one-based number on the sheet. Computed, so it is checked like `text()`:
     * `0.5 + 1` and `true + 1` would each print differently in one engine and be refused in
     * another. `is_int` is false for a boolean.
     *
     * @param mixed $index
     */
    private static function successor($index): string
    {
        if (!\is_int($index)) {
            throw new OperationalArtifactException('invalid_value', 'a container_index is not an integer');
        }
        return (string) ($index + 1);
    }

    // ----------------------------------------------------------------------------- helpers

    /**
     * The one rendering every engine shares: a string as itself, an integer in decimal, null as
     * nothing. A boolean, a float, an array or an object has a different default spelling in
     * each language, so it is refused rather than printed four ways.
     *
     * @param mixed $value
     */
    private static function text($value): string
    {
        if ($value === null) {
            return '';
        }
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value)) {
            return (string) $value;
        }
        $type = \is_object($value) ? \get_class($value) : \gettype($value);
        throw new OperationalArtifactException('invalid_value', "a {$type} has no single rendering in a work order");
    }

    /**
     * Python's truthiness for a JSON value: null, false, zero, an empty string, an empty array
     * and an empty object are false. PHP's own rule differs at `"0"`.
     *
     * @param mixed $value
     */
    private static function isTruthy($value): bool
    {
        if ($value instanceof \stdClass) {
            return JsonValue::members($value) !== [];
        }
        if (\is_string($value)) {
            return $value !== '';
        }
        return (bool) $value;
    }

    /**
     * @param list<string> $lines
     * @param list<string> $more
     */
    private static function append(array &$lines, array $more): void
    {
        foreach ($more as $line) {
            $lines[] = $line;
        }
    }

    /**
     * An export reads only a document it knows how to read, and says so when it cannot.
     *
     * @param mixed $artifact
     * @return mixed
     */
    private static function requireArtifact($artifact)
    {
        $found = JsonValue::get($artifact, 'format');
        if ($found !== OperationalArtifact::FORMAT) {
            $named = \is_string($found) ? "'{$found}'" : 'none';
            throw new OperationalArtifactException(
                'unknown_format',
                "cannot export format {$named}; this exporter reads " . OperationalArtifact::FORMAT
            );
        }
        return $artifact;
    }

    /**
     * A member a v1 document always has. Its absence means the document is not one this
     * exporter knows how to read.
     *
     * @param mixed $object
     * @return mixed
     */
    private static function field($object, string $name)
    {
        if (!JsonValue::has($object, $name)) {
            throw new OperationalArtifactException('unknown_format', "the document has no {$name} where the format requires one");
        }
        return JsonValue::get($object, $name);
    }

    /**
     * @param mixed $value
     * @return list<mixed>
     */
    private static function items($value): array
    {
        if (!JsonValue::isList($value)) {
            throw new OperationalArtifactException('unknown_format', 'the document has an object where the format requires a list');
        }
        return $value;
    }
}
