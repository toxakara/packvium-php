<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

use Packvium\Validation\ResultIssue;

/**
 * Every reported placement carries its `placement_id` (1.10), and no two carry the same one.
 *
 * The id is the canonical JSON text of `[container id, x, y, z, item type, orientation]` with the
 * position in ticks, spelled here rather than by the engine's canonical writer so the validator
 * shares no code with the engine. A PHP string holds no lone UTF-16 surrogate, so the
 * reference's surrogate escape never arises here.
 */
final class PlacementIds
{
    /**
     * Read from the result as reported: a lattice summary has no placement objects, so it has no
     * ids to check. O(placements).
     *
     * @param mixed $result @param list<ResultIssue> $issues
     */
    public static function check($result, array &$issues): void
    {
        $seen = [];
        foreach (Json::items(Json::at($result, 'containers'), 'result.containers') as $container) {
            $containerId = Json::get($container, 'id', '');
            foreach (Json::items(Json::at($container, 'placements'), 'placements') as $placement) {
                $expected = self::expected($containerId, $placement);
                $reported = Json::get($placement, 'placement_id');
                $where = PyFormat::str(Json::at($placement, 'item_id')) . ' in ' . PyFormat::str($containerId);
                if (!\is_string($reported)) {
                    $issues[] = new ResultIssue('placement_id_mismatch', "{$where}: no placement_id, expected {$expected}");
                    continue;
                }
                if ($reported !== $expected) {
                    $issues[] = new ResultIssue('placement_id_mismatch', "{$where}: reported {$reported}, expected {$expected}");
                }
                if (isset($seen[$reported])) {
                    $issues[] = new ResultIssue('duplicate_placement_id', $reported);
                }
                $seen[$reported] = true;
            }
        }
    }

    /** @param mixed $containerId @param mixed $placement */
    private static function expected($containerId, $placement): string
    {
        $position = Json::at($placement, 'position');
        $parts = [self::spelled(PyFormat::str($containerId))];
        foreach (['x', 'y', 'z'] as $axis) {
            $parts[] = PyFormat::str(Json::at(Json::at($position, $axis), 'ticks'));
        }
        $parts[] = self::spelled(PyFormat::str(Json::at($placement, 'item_type')));
        $parts[] = self::spelled(PyFormat::str(Json::at($placement, 'orientation')));
        return '[' . \implode(',', $parts) . ']';
    }

    /** A string as RFC 8785 spells it: `"`, `\` and U+0000..U+001F escaped, the rest as UTF-8. */
    private static function spelled(string $text): string
    {
        return '"' . \strtr($text, self::escapes()) . '"';
    }

    /** @return array<string,string> */
    private static function escapes(): array
    {
        static $escapes = null;
        if ($escapes === null) {
            $escapes = [
                '"' => '\\"', '\\' => '\\\\', "\x08" => '\\b', "\t" => '\\t',
                "\n" => '\\n', "\x0C" => '\\f', "\r" => '\\r',
            ];
            for ($code = 0; $code < 0x20; $code++) {
                $escapes[\chr($code)] = $escapes[\chr($code)] ?? \sprintf('\\u%04x', $code);
            }
        }
        return $escapes;
    }
}
