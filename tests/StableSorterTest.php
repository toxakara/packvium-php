<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Support\StableSorter;

/**
 * Ordering helpers.
 *
 * PHP's `usort` is not required to be stable across engine versions, and the
 * multi-start orderings must be byte-identical to Python's `sorted`, so ties are
 * broken on the original index rather than left to the sort implementation.
 */
final class StableSorterTest extends TestCase
{
    public static function testTiesKeepTheirOriginalOrder(): void
    {
        $values = [['k' => 1, 'tag' => 'a'], ['k' => 1, 'tag' => 'b'], ['k' => 1, 'tag' => 'c']];
        $sorted = StableSorter::sort($values, static fn(array $x, array $y): int => $x['k'] <=> $y['k']);
        self::assertSame(['a', 'b', 'c'], array_column($sorted, 'tag'));
    }

    public static function testSortingIsByTheComparator(): void
    {
        $sorted = StableSorter::sort([3, 1, 2], static fn(int $a, int $b): int => $a <=> $b);
        self::assertSame([1, 2, 3], $sorted);
    }

    public static function testSortByBuildsEachKeyOnce(): void
    {
        $built = 0;
        $sorted = StableSorter::sortBy([3, 1, 2], static function (int $value) use (&$built): array {
            $built++;
            return [$value];
        });
        self::assertSame([1, 2, 3], $sorted);
        self::assertSame(3, $built, 'the key must be built per element, not per comparison');
    }

    public static function testSortByCompoundKeysLexicographically(): void
    {
        $values = [['b', 2], ['a', 2], ['a', 1]];
        self::assertSame([['a', 1], ['a', 2], ['b', 2]],
            StableSorter::sortBy($values, static fn(array $v): array => $v));
    }

    public static function testSortByIsStable(): void
    {
        $values = [['k' => 1, 'tag' => 'a'], ['k' => 0, 'tag' => 'b'], ['k' => 1, 'tag' => 'c']];
        $sorted = StableSorter::sortBy($values, static fn(array $v): array => [$v['k']]);
        self::assertSame(['b', 'a', 'c'], array_column($sorted, 'tag'));
    }

    public static function testAnEmptyListSortsToAnEmptyList(): void
    {
        self::assertSame([], StableSorter::sort([], static fn($a, $b): int => 0));
        self::assertSame([], StableSorter::sortBy([], static fn($v): array => [$v]));
    }

    public static function testTheResultIsAReindexedList(): void
    {
        $sorted = StableSorter::sortBy([3, 1, 2], static fn(int $v): array => [$v]);
        self::assertSame([0, 1, 2], array_keys($sorted));
    }
}
