<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

use Packvium\Validation\MalformedResult;

/**
 * Convex hulls in ticks, written the slow, obvious way the reference writes them: a validator
 * that took the engine's shortcuts would stop being a second opinion.
 */
final class Geometry
{
    /** Axis permutation each orientation code applies to (length, width, height). */
    public const ROTATIONS = [
        'LWH' => [0, 1, 2], 'LHW' => [0, 2, 1], 'WLH' => [1, 0, 2],
        'WHL' => [1, 2, 0], 'HLW' => [2, 0, 1], 'HWL' => [2, 1, 0],
    ];

    /** `ROTATIONS[orientation]`. @return array{0:int,1:int,2:int} */
    public static function axes(string $orientation): array
    {
        if (!\array_key_exists($orientation, self::ROTATIONS)) {
            throw MalformedResult::shape(\sprintf('unknown orientation "%s"', $orientation));
        }
        return self::ROTATIONS[$orientation];
    }

    /** `orientation in ROTATIONS`, for any JSON value; an unhashable one is Python's `TypeError`. @param mixed $orientation */
    public static function isRotation($orientation): bool
    {
        Json::hashKey($orientation);
        return \is_string($orientation) && \array_key_exists($orientation, self::ROTATIONS);
    }

    /** @param array<int,int|string> $left @param array<int,int|string> $right @return array{0:int|string,1:int|string,2:int|string} */
    public static function difference(array $left, array $right): array
    {
        return [Exact::sub($left[0], $right[0]), Exact::sub($left[1], $right[1]), Exact::sub($left[2], $right[2])];
    }

    /** @param array<int,int|string> $left @param array<int,int|string> $right @return array{0:int|string,1:int|string,2:int|string} */
    public static function cross(array $left, array $right): array
    {
        return [
            Exact::sub(Exact::mul($left[1], $right[2]), Exact::mul($left[2], $right[1])),
            Exact::sub(Exact::mul($left[2], $right[0]), Exact::mul($left[0], $right[2])),
            Exact::sub(Exact::mul($left[0], $right[1]), Exact::mul($left[1], $right[0])),
        ];
    }

    /** @param array<int,int|string> $left @param array<int,int|string> $right @return int|string */
    public static function dot(array $left, array $right)
    {
        return Exact::add(Exact::add(Exact::mul($left[0], $right[0]), Exact::mul($left[1], $right[1])), Exact::mul($left[2], $right[2]));
    }

    /** The axis reduced by its gcd and signed so its first non-zero component is positive. @param array<int,int|string> $axis @return array{0:int|string,1:int|string,2:int|string} */
    public static function primitiveAxis(array $axis): array
    {
        $divisor = Exact::gcd(Exact::gcd($axis[0], $axis[1]), $axis[2]);
        if (Exact::sign($divisor) === 0) {
            return [0, 0, 0];
        }
        $reduced = [Exact::floorDiv($axis[0], $divisor), Exact::floorDiv($axis[1], $divisor), Exact::floorDiv($axis[2], $divisor)];
        foreach ($reduced as $value) {
            if (Exact::sign($value) !== 0) {
                return Exact::sign($value) < 0 ? [Exact::negate($reduced[0]), Exact::negate($reduced[1]), Exact::negate($reduced[2])] : $reduced;
            }
        }
        return [0, 0, 0];
    }

    /**
     * Reorient a hull the way `ROTATIONS` reorients its box, flipping one axis on an odd
     * permutation so it stays a proper rotation, then translated so its bounds start at 0.
     *
     * @param list<array{0:int|string,1:int|string,2:int|string}> $vertices
     * @param array{0:int,1:int,2:int} $axes
     * @return list<array{0:int|string,1:int|string,2:int|string}>
     */
    public static function rotateHull(array $vertices, array $axes): array
    {
        $inversions = 0;
        for ($first = 0; $first < 3; ++$first) {
            for ($second = $first + 1; $second < 3; ++$second) {
                if ($axes[$first] > $axes[$second]) {
                    ++$inversions;
                }
            }
        }
        $turned = [];
        foreach ($vertices as $vertex) {
            $x = $vertex[$axes[0]];
            $turned[] = [$inversions % 2 === 1 ? Exact::negate($x) : $x, $vertex[$axes[1]], $vertex[$axes[2]]];
        }
        if ($turned === []) {
            throw MalformedResult::shape('a hull without vertices');
        }
        $low = [];
        for ($axis = 0; $axis < 3; ++$axis) {
            $low[$axis] = $turned[0][$axis];
            foreach ($turned as $vertex) {
                if (Exact::compare($vertex[$axis], $low[$axis]) < 0) {
                    $low[$axis] = $vertex[$axis];
                }
            }
        }
        return \array_map(static function (array $vertex) use ($low): array { return self::difference($vertex, $low); }, $turned);
    }

    /** Whether two convex solids share interior volume; touching is contact, not collision. @param list<array> $left @param list<array> $right */
    public static function solidsOverlap(array $left, array $right): bool
    {
        foreach (self::hullAxes($left, $right) as $axis) {
            [$leftLow, $leftHigh] = self::extent($left, $axis);
            [$rightLow, $rightHigh] = self::extent($right, $axis);
            if (Exact::compare($leftHigh, $rightLow) <= 0 || Exact::compare($rightHigh, $leftLow) <= 0) {
                return false;
            }
        }
        return true;
    }

    /** Exact volume of a convex hull, `6V = sum of a . (b x c)` over its outward faces. @param list<array> $vertices @return int|string */
    public static function hullVolume(array $vertices)
    {
        $normals = [];
        foreach (self::triples($vertices) as [$first, $second, $third]) {
            $axis = self::primitiveAxis(self::cross(self::difference($second, $first), self::difference($third, $first)));
            if (Exact::sign($axis[0]) !== 0 || Exact::sign($axis[1]) !== 0 || Exact::sign($axis[2]) !== 0) {
                $normals[self::axisKey($axis)] = $axis;
            }
        }
        $sixVolumes = 0;
        foreach ($normals as $axis) {
            foreach ([$axis, [Exact::negate($axis[0]), Exact::negate($axis[1]), Exact::negate($axis[2])]] as $outward) {
                [, $highest] = self::extent($vertices, $outward);
                $face = [];
                foreach ($vertices as $vertex) {
                    if (Exact::compare(self::dot($vertex, $outward), $highest) === 0) {
                        $face[] = $vertex;
                    }
                }
                if (\count($face) < 3) {
                    continue;
                }
                $ordered = self::windFace($face, $outward);
                for ($index = 1; $index + 1 < \count($ordered); ++$index) {
                    $sixVolumes = Exact::add($sixVolumes, self::dot($ordered[0], self::cross($ordered[$index], $ordered[$index + 1])));
                }
            }
        }
        return Exact::floorDiv(Exact::abs($sixVolumes), 6);
    }

    /**
     * Corners of one planar convex face in cyclic order seen from outside, by gift-wrapping from
     * the lexicographically smallest vertex; collinear candidates resolve to the farthest.
     *
     * @param list<array> $face @param array<int,int|string> $outward @return list<array>
     */
    private static function windFace(array $face, array $outward): array
    {
        $start = $face[0];
        foreach ($face as $vertex) {
            if (self::compareVertices($vertex, $start) < 0) {
                $start = $vertex;
            }
        }
        $ordered = [$start];
        $current = $start;
        for ($step = 0, $count = \count($face); $step < $count; ++$step) {
            $following = null;
            foreach ($face as $candidate) {
                if (self::compareVertices($candidate, $current) === 0) {
                    continue;
                }
                if ($following === null) {
                    $following = $candidate;
                    continue;
                }
                $turn = self::dot(self::cross(self::difference($following, $current), self::difference($candidate, $current)), $outward);
                $reachVector = self::difference($candidate, $current);
                $heldVector = self::difference($following, $current);
                $reach = self::dot($reachVector, $reachVector);
                $held = self::dot($heldVector, $heldVector);
                if (Exact::sign($turn) < 0 || (Exact::sign($turn) === 0 && Exact::compare($reach, $held) > 0)) {
                    $following = $candidate;
                }
            }
            if ($following === null || self::compareVertices($following, $start) === 0) {
                break;
            }
            $ordered[] = $following;
            $current = $following;
        }
        return $ordered;
    }

    /** The complete separating-axis superset: face normals of each hull and cross products of their edges. @param list<array> $left @param list<array> $right @return list<array> */
    private static function hullAxes(array $left, array $right): array
    {
        $axes = [];
        foreach ([$left, $right] as $hull) {
            foreach (self::triples($hull) as [$first, $second, $third]) {
                $axis = self::primitiveAxis(self::cross(self::difference($second, $first), self::difference($third, $first)));
                $axes[self::axisKey($axis)] = $axis;
            }
        }
        $rightDirections = self::pairDirections($right);
        foreach (self::pairDirections($left) as $one) {
            foreach ($rightDirections as $other) {
                $axis = self::primitiveAxis(self::cross($one, $other));
                $axes[self::axisKey($axis)] = $axis;
            }
        }
        unset($axes[self::axisKey([0, 0, 0])]);
        return \array_values($axes);
    }

    /** @param list<array> $points @return list<array> */
    private static function pairDirections(array $points): array
    {
        $directions = [];
        for ($first = 0, $count = \count($points); $first < $count; ++$first) {
            for ($second = $first + 1; $second < $count; ++$second) {
                $directions[] = self::difference($points[$second], $points[$first]);
            }
        }
        return $directions;
    }

    /** Every 3-combination, in `itertools.combinations` order. @param list<array> $points @return \Generator<list<array>> */
    private static function triples(array $points): \Generator
    {
        for ($first = 0, $count = \count($points); $first < $count; ++$first) {
            for ($second = $first + 1; $second < $count; ++$second) {
                for ($third = $second + 1; $third < $count; ++$third) {
                    yield [$points[$first], $points[$second], $points[$third]];
                }
            }
        }
    }

    /** @param list<array> $points @param array<int,int|string> $axis @return array{0:int|string,1:int|string} */
    private static function extent(array $points, array $axis): array
    {
        $low = $high = null;
        foreach ($points as $point) {
            $value = self::dot($point, $axis);
            if ($low === null || Exact::compare($value, $low) < 0) {
                $low = $value;
            }
            if ($high === null || Exact::compare($value, $high) > 0) {
                $high = $value;
            }
        }
        return [$low, $high];
    }

    /** @param array<int,int|string> $axis */
    private static function axisKey(array $axis): string
    {
        return $axis[0] . ',' . $axis[1] . ',' . $axis[2];
    }

    /** @param array<int,int|string> $left @param array<int,int|string> $right */
    private static function compareVertices(array $left, array $right): int
    {
        for ($axis = 0; $axis < 3; ++$axis) {
            $order = Exact::compare($left[$axis], $right[$axis]);
            if ($order !== 0) {
                return $order;
            }
        }
        return 0;
    }
}
