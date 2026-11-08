<?php
declare(strict_types=1);
namespace Packvium\Domain;

/**
 * The 24 proper rotations an item may be packed in, in canonical table order (1.10).
 *
 * A box has six distinguishable orientations and a convex hull has 24, so the vocabulary is the
 * six legacy codes followed by 18 signed ones. A legacy code names the declared side each
 * container axis takes (`LHW`: x spans the length, y the height, z the width) and keeps the
 * matrix it has always had: an even permutation carries no sign, an odd one negates x. A signed
 * code spells the sign out per axis -- `-L-W+H` is the half-turn about the vertical -- and only
 * a `convex_hull` may ask for one, because on a box it is a legacy box under another name.
 *
 * Held to `packvium.orientations`. A leaf: the hull geometry and the box geometry both read it,
 * so the two can never disagree on what a code means. Keyed by code string so the PHP 7.4
 * lowering, where a `Rotation` is its code, reads the same table.
 */
final class Orientations
{
    public const LEGACY_CODES = ['LWH', 'LHW', 'WLH', 'WHL', 'HLW', 'HWL'];

    /** Every code in table order: `[source axes of x, y, z (L=0, W=1, H=2), signs]`. */
    public const TABLE = [
        'LWH' => [[0, 1, 2], [1, 1, 1]], 'LHW' => [[0, 2, 1], [-1, 1, 1]],
        'WLH' => [[1, 0, 2], [-1, 1, 1]], 'WHL' => [[1, 2, 0], [1, 1, 1]],
        'HLW' => [[2, 0, 1], [1, 1, 1]], 'HWL' => [[2, 1, 0], [-1, 1, 1]],
        '+L-W-H' => [[0, 1, 2], [1, -1, -1]], '-L+W-H' => [[0, 1, 2], [-1, 1, -1]],
        '-L-W+H' => [[0, 1, 2], [-1, -1, 1]],
        '+L-H+W' => [[0, 2, 1], [1, -1, 1]], '+L+H-W' => [[0, 2, 1], [1, 1, -1]],
        '-L-H-W' => [[0, 2, 1], [-1, -1, -1]],
        '+W-L+H' => [[1, 0, 2], [1, -1, 1]], '+W+L-H' => [[1, 0, 2], [1, 1, -1]],
        '-W-L-H' => [[1, 0, 2], [-1, -1, -1]],
        '+W-H-L' => [[1, 2, 0], [1, -1, -1]], '-W+H-L' => [[1, 2, 0], [-1, 1, -1]],
        '-W-H+L' => [[1, 2, 0], [-1, -1, 1]],
        '+H-L-W' => [[2, 0, 1], [1, -1, -1]], '-H+L-W' => [[2, 0, 1], [-1, 1, -1]],
        '-H-L+W' => [[2, 0, 1], [-1, -1, 1]],
        '+H-W+L' => [[2, 1, 0], [1, -1, 1]], '+H+W-L' => [[2, 1, 0], [1, 1, -1]],
        '-H-W-L' => [[2, 1, 0], [-1, -1, -1]],
    ];

    /** The codes that keep the item's own height vertical and pointing up: z takes `+H`. */
    public const UPRIGHT_CODES = ['LWH', 'WLH', '-L-W+H', '+W-L+H'];

    /** What a signed code on anything but a hull is refused with, at its own pointer. */
    public const SIGNED_CODE_NEEDS_A_HULL = 'a signed rotation code requires shape_type convex_hull';

    /** The codes an item may name: all 24 for a hull, the six legacy codes otherwise. @return list<string> */
    public static function vocabulary(bool $hull): array
    {
        return $hull ? \array_keys(self::TABLE) : self::LEGACY_CODES;
    }

    /**
     * Why `$value` cannot orient an item of this shape, or null when it can: `invalid_value`
     * for a signed code beside a non-hull -- a rule broken, not an unknown word -- and
     * `not_allowed` for anything outside the item's vocabulary. O(1).
     *
     * @param mixed $value
     */
    public static function refusalReason($value, bool $hull): ?string
    {
        if (!\is_string($value) || !\array_key_exists($value, self::TABLE)) {
            return 'not_allowed';
        }
        if (!$hull && !\in_array($value, self::LEGACY_CODES, true)) {
            return 'invalid_value';
        }
        return null;
    }

    /** @return array{0:int,1:int,2:int} */
    public static function sourceAxes(string $rotation): array
    {
        return self::TABLE[$rotation][0];
    }

    /** @return array{0:int,1:int,2:int} */
    public static function signs(string $rotation): array
    {
        return self::TABLE[$rotation][1];
    }

    public static function isSigned(string $rotation): bool
    {
        return !\in_array($rotation, self::LEGACY_CODES, true);
    }

    public static function isUpright(string $rotation): bool
    {
        return \in_array($rotation, self::UPRIGHT_CODES, true);
    }
}
