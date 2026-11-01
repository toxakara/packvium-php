<?php
declare(strict_types=1);
namespace Packvium\Tests;

use Packvium\Commerce\CommerceInputException;
use function Packvium\Commerce\catalogVersionInfo;

final class CommerceCatalogDatesTest extends TestCase
{
    public static function testNegativeResolutionTimeIsAnInputErrorBeforeCatalogLookup(): void
    {
        $document = self::document();
        foreach (['c', 'missing'] as $catalogId) {
            self::assertThrows(CommerceInputException::class, static fn() => catalogVersionInfo(
                $document, ['catalog_id' => $catalogId, 'version' => 1, 'resolved_at' => -1],
            ));
        }
    }

    public static function testNegativeRollbackDatesAreInputErrors(): void
    {
        foreach ([
            ['rollback_to' => 1, 'published_at' => 0, 'effective_at' => -1],
            ['rollback_to' => 1, 'published_at' => -1, 'effective_at' => 0],
            ['rollback_to' => 1, 'published_at' => -1],
        ] as $rollback) {
            self::assertThrows(CommerceInputException::class, static fn() => catalogVersionInfo(
                self::document($rollback), ['catalog_id' => 'c', 'version' => 2, 'resolved_at' => 0],
            ));
        }
    }

    public static function testZeroDatesAreValidForARollback(): void
    {
        $result = catalogVersionInfo(
            self::document(['rollback_to' => 1, 'published_at' => 0]),
            ['catalog_id' => 'c', 'as_of' => 0, 'resolved_at' => 0],
        );
        self::assertSame('ok', $result['status']);
        self::assertSame(2, $result['catalog']['version']);
        self::assertSame(0, $result['catalog']['effective_at']);
        self::assertSame(0, $result['catalog']['published_at']);
        self::assertSame(0, $result['catalog']['resolved_at']);
    }

    private static function document(?array $rollback = null): array
    {
        $versions = [['effective_at' => 0, 'published_at' => 0, 'snapshot' => []]];
        if ($rollback !== null) { $versions[] = $rollback; }
        return ['catalogs' => [['catalog_id' => 'c', 'versions' => $versions]]];
    }
}
