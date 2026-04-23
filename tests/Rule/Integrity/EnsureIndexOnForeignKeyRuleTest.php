<?php

declare(strict_types=1);

namespace IndoctrinateTest\Rule\Integrity;

use Indoctrinate\Rule\Integrity\EnsureIndexOnForeignKeyRule;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class EnsureIndexOnForeignKeyRuleTest extends TestCase
{
    public function testReturnsEmptyWhenAllForeignKeysAreIndexed(): void
    {
        $pdo = $this->pdoWithRows([]);

        $logs = (new EnsureIndexOnForeignKeyRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testFlagsUnindexedForeignKeyColumn(): void
    {
        $pdo = $this->pdoWithRows([
            ['TABLE_NAME' => 'orders', 'COLUMN_NAME' => 'user_id', 'CONSTRAINT_NAME' => 'fk_orders_user', 'REFERENCED_TABLE_NAME' => 'users', 'REFERENCED_COLUMN_NAME' => 'id'],
        ]);

        $logs = (new EnsureIndexOnForeignKeyRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $this->assertSame('orders', $logs[0]->getTable());
        $this->assertSame('user_id', $logs[0]->getColumn());
        $this->assertStringContainsString('FK(fk_orders_user)', $logs[0]->getFrom());
        $this->assertStringContainsString('users.id', $logs[0]->getFrom());
        $this->assertStringContainsString('no index', $logs[0]->getFrom());
        $this->assertSame('ALTER TABLE `orders` ADD INDEX `idx_orders_user_id` (`user_id`)', $logs[0]->getTo());
    }

    public function testFlagsMultipleUnindexedColumns(): void
    {
        $pdo = $this->pdoWithRows([
            ['TABLE_NAME' => 'orders', 'COLUMN_NAME' => 'user_id', 'CONSTRAINT_NAME' => 'fk1', 'REFERENCED_TABLE_NAME' => 'users', 'REFERENCED_COLUMN_NAME' => 'id'],
            ['TABLE_NAME' => 'orders', 'COLUMN_NAME' => 'product_id', 'CONSTRAINT_NAME' => 'fk2', 'REFERENCED_TABLE_NAME' => 'products', 'REFERENCED_COLUMN_NAME' => 'id'],
        ]);

        $logs = (new EnsureIndexOnForeignKeyRule())->apply($pdo, new NullOutput());

        $this->assertCount(2, $logs);
        $this->assertSame('user_id', $logs[0]->getColumn());
        $this->assertSame('product_id', $logs[1]->getColumn());
    }

    public function testSkipsTableMatchingSkipTableLike(): void
    {
        $pdo = $this->pdoWithRows([
            ['TABLE_NAME' => 'cache_entries', 'COLUMN_NAME' => 'user_id', 'CONSTRAINT_NAME' => 'fk1', 'REFERENCED_TABLE_NAME' => 'users', 'REFERENCED_COLUMN_NAME' => 'id'],
        ]);

        // default skip_table_like includes %cache%
        $logs = (new EnsureIndexOnForeignKeyRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testSkipsExactTableInSkipTables(): void
    {
        $pdo = $this->pdoWithRows([
            ['TABLE_NAME' => 'legacy_orders', 'COLUMN_NAME' => 'user_id', 'CONSTRAINT_NAME' => 'fk1', 'REFERENCED_TABLE_NAME' => 'users', 'REFERENCED_COLUMN_NAME' => 'id'],
        ]);

        $logs = (new EnsureIndexOnForeignKeyRule())->apply($pdo, new NullOutput(), [
            'skip_tables' => ['legacy_orders'],
        ]);

        $this->assertCount(0, $logs);
    }

    public function testOnlyTablesFilterIsRespected(): void
    {
        $pdo = $this->pdoWithRows([
            ['TABLE_NAME' => 'orders', 'COLUMN_NAME' => 'user_id', 'CONSTRAINT_NAME' => 'fk1', 'REFERENCED_TABLE_NAME' => 'users', 'REFERENCED_COLUMN_NAME' => 'id'],
            ['TABLE_NAME' => 'comments', 'COLUMN_NAME' => 'post_id', 'CONSTRAINT_NAME' => 'fk2', 'REFERENCED_TABLE_NAME' => 'posts', 'REFERENCED_COLUMN_NAME' => 'id'],
        ]);

        $logs = (new EnsureIndexOnForeignKeyRule())->apply($pdo, new NullOutput(), [
            'only_tables' => ['orders'],
        ]);

        $this->assertCount(1, $logs);
        $this->assertSame('orders', $logs[0]->getTable());
    }

    public function testGeneratedIndexNameIsTruncatedWhenTooLong(): void
    {
        $longTable = 'very_long_table_name_that_exceeds_mysql_identifier_limit_abcdef';
        $longColumn = 'very_long_column_name_that_exceeds_mysql_limit';

        $pdo = $this->pdoWithRows([
            ['TABLE_NAME' => $longTable, 'COLUMN_NAME' => $longColumn, 'CONSTRAINT_NAME' => 'fk1', 'REFERENCED_TABLE_NAME' => 'parent', 'REFERENCED_COLUMN_NAME' => 'id'],
        ]);

        $logs = (new EnsureIndexOnForeignKeyRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        // Extract index name from the ALTER TABLE statement
        preg_match('/ADD INDEX `([^`]+)`/', $logs[0]->getTo(), $m);
        $this->assertNotEmpty($m[1]);
        $this->assertLessThanOrEqual(64, \strlen($m[1]));
    }

    /** @param array<int, array<string, string>> $rows */
    private function pdoWithRows(array $rows): PDO
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($stmt);

        return $pdo;
    }
}
