<?php

declare(strict_types=1);

namespace IndoctrinateTest\Service;

use Indoctrinate\Service\SchemaDiscoveryService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class SchemaDiscoveryServiceTest extends TestCase
{
    private SchemaDiscoveryService $service;

    protected function setUp(): void
    {
        $this->service = new SchemaDiscoveryService();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @param list<mixed> $rows */
    private function stmtReturning(array $rows, int $fetchMode = PDO::FETCH_COLUMN): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->with($fetchMode)->willReturn($rows);
        return $stmt;
    }

    /** @param list<mixed> $rows */
    private function preparableStmt(array $rows, int $fetchMode = PDO::FETCH_ASSOC): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->with($fetchMode)->willReturn($rows);
        return $stmt;
    }

    // ── discoverTables ────────────────────────────────────────────────────────

    public function testDiscoverTablesReturnsAllTablesWhenNoFilters(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($this->stmtReturning(['users', 'orders', 'products']));

        $result = $this->service->discoverTables($pdo, [], []);

        self::assertSame(['users', 'orders', 'products'], $result);
    }

    public function testDiscoverTablesFiltersToOnlyTables(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($this->stmtReturning(['users', 'orders', 'products']));

        $result = $this->service->discoverTables($pdo, ['users', 'products'], []);

        self::assertSame(['users', 'products'], $result);
    }

    public function testDiscoverTablesSkipsTableMatchingLikePattern(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn(
            $this->stmtReturning(['users', 'user_sessions', 'products', 'product_cache'])
        );

        $result = $this->service->discoverTables($pdo, [], ['%session%', '%cache%']);

        self::assertSame(['users', 'products'], $result);
    }

    public function testDiscoverTablesSkipsPatternWithWildcardAtStart(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($this->stmtReturning(['tmp_orders', 'orders']));

        $result = $this->service->discoverTables($pdo, [], ['tmp_%']);

        self::assertSame(['orders'], $result);
    }

    public function testDiscoverTablesReturnsEmptyWhenAllSkipped(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($this->stmtReturning(['cache_items', 'cache_tags']));

        $result = $this->service->discoverTables($pdo, [], ['cache_%']);

        self::assertSame([], $result);
    }

    public function testDiscoverTablesCombinesOnlyAndSkipFilters(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn(
            $this->stmtReturning(['users', 'user_logs', 'orders', 'order_logs'])
        );

        // Only users and user_logs, but skip anything with _logs
        $result = $this->service->discoverTables($pdo, ['users', 'user_logs'], ['%_logs']);

        self::assertSame(['users'], $result);
    }

    // ── getColumns ────────────────────────────────────────────────────────────

    public function testGetColumnsReturnsColumnData(): void
    {
        $columns = [
            [
                'COLUMN_NAME' => 'id',
                'DATA_TYPE' => 'int',
                'IS_NULLABLE' => 'NO',
                'COLUMN_KEY' => 'PRI',
                'EXTRA' => 'auto_increment',
            ],
            [
                'COLUMN_NAME' => 'name',
                'DATA_TYPE' => 'varchar',
                'IS_NULLABLE' => 'NO',
                'COLUMN_KEY' => '',
                'EXTRA' => '',
            ],
        ];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($this->preparableStmt($columns));

        $result = $this->service->getColumns($pdo, 'users');

        self::assertCount(2, $result);
        self::assertSame('id', $result[0]['COLUMN_NAME']);
        self::assertSame('name', $result[1]['COLUMN_NAME']);
    }

    public function testGetColumnsReturnsEmptyArrayForUnknownTable(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($this->preparableStmt([], PDO::FETCH_ASSOC));

        $result = $this->service->getColumns($pdo, 'nonexistent');

        self::assertSame([], $result);
    }

    // ── getForeignKeys ────────────────────────────────────────────────────────

    public function testGetForeignKeysReturnsFkData(): void
    {
        $fks = [
            [
                'COLUMN_NAME' => 'user_id',
                'REFERENCED_TABLE_NAME' => 'users',
                'REFERENCED_COLUMN_NAME' => 'id',
            ],
        ];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($this->preparableStmt($fks));

        $result = $this->service->getForeignKeys($pdo, 'orders');

        self::assertCount(1, $result);
        self::assertSame('user_id', $result[0]['COLUMN_NAME']);
        self::assertSame('users', $result[0]['REFERENCED_TABLE_NAME']);
    }

    public function testGetForeignKeysReturnsEmptyForTableWithNoFks(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($this->preparableStmt([]));

        $result = $this->service->getForeignKeys($pdo, 'standalone_table');

        self::assertSame([], $result);
    }

    // ── getUniqueColumns ──────────────────────────────────────────────────────

    public function testGetUniqueColumnsReturnsColumnNames(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn(
            $this->preparableStmt(['email', 'username'], PDO::FETCH_COLUMN)
        );

        $result = $this->service->getUniqueColumns($pdo, 'users');

        self::assertSame(['email', 'username'], $result);
    }

    public function testGetUniqueColumnsReturnsEmptyWhenNone(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn(
            $this->preparableStmt([], PDO::FETCH_COLUMN)
        );

        $result = $this->service->getUniqueColumns($pdo, 'basic_table');

        self::assertSame([], $result);
    }
}
