<?php

declare(strict_types=1);

namespace IndoctrinateTest\Rule\MySQL\Integrity;

use Indoctrinate\Rule\MySQL\Integrity\DetectVarcharIndexPrefixRule;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class DetectVarcharIndexPrefixRuleTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Static contract
    // -------------------------------------------------------------------------

    public function testIsNotDestructive(): void
    {
        $this->assertFalse(DetectVarcharIndexPrefixRule::isDestructive());
    }

    public function testDriverIsMySQL(): void
    {
        $this->assertSame('mysql', DetectVarcharIndexPrefixRule::getDriver());
    }

    public function testConstraintClassIsSet(): void
    {
        $this->assertNotEmpty(DetectVarcharIndexPrefixRule::getConstraintClass());
    }

    // -------------------------------------------------------------------------
    // MySQL 8.0+ with DYNAMIC row format (limit: 3072 bytes)
    // -------------------------------------------------------------------------

    public function testDoesNotFlagVarchar255DynamicOnMySQL8(): void
    {
        // 255 * 4 = 1020 bytes < 3072 → clean
        $pdo  = $this->buildPdo([$this->row('users', 'email', 255, 'DYNAMIC')], '8.0.28');
        $logs = (new DetectVarcharIndexPrefixRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testFlagsVarchar769DynamicOnMySQL8(): void
    {
        // 769 * 4 = 3076 bytes > 3072 → flagged; safe max = 768
        $pdo  = $this->buildPdo([$this->row('users', 'slug', 769, 'DYNAMIC')], '8.0.28');
        $logs = (new DetectVarcharIndexPrefixRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $this->assertSame('users', $logs[0]->getTable());
        $this->assertSame('slug', $logs[0]->getColumn());
        $this->assertStringContainsString('VARCHAR(769)', $logs[0]->getFrom());
        $this->assertStringContainsString('3072-byte', $logs[0]->getFrom());
        $this->assertStringContainsString('VARCHAR(768)', $logs[0]->getTo());
    }

    // -------------------------------------------------------------------------
    // MySQL 8.0+ with COMPACT row format (limit: 767 bytes — not DYNAMIC)
    // -------------------------------------------------------------------------

    public function testFlagsVarchar192CompactOnMySQL8(): void
    {
        // 192 * 4 = 768 bytes > 767 → flagged even on MySQL 8 because COMPACT format
        $pdo  = $this->buildPdo([$this->row('orders', 'ref', 192, 'COMPACT')], '8.0.28');
        $logs = (new DetectVarcharIndexPrefixRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $this->assertSame('orders', $logs[0]->getTable());
        $this->assertStringContainsString('VARCHAR(192)', $logs[0]->getFrom());
        $this->assertStringContainsString('767-byte', $logs[0]->getFrom());
        $this->assertStringContainsString('VARCHAR(191)', $logs[0]->getTo());
    }

    public function testDoesNotFlagVarchar191CompactOnMySQL8(): void
    {
        // 191 * 4 = 764 bytes < 767 → clean
        $pdo  = $this->buildPdo([$this->row('orders', 'ref', 191, 'COMPACT')], '8.0.28');
        $logs = (new DetectVarcharIndexPrefixRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    // -------------------------------------------------------------------------
    // MySQL 5.7 without innodb_large_prefix (limit: 767 bytes)
    // -------------------------------------------------------------------------

    public function testFlagsVarchar255OnMySQL57WithoutLargePrefix(): void
    {
        // 255 * 4 = 1020 bytes > 767
        $pdo  = $this->buildPdo(
            rows: [$this->row('products', 'name', 255, 'COMPACT')],
            mysqlVersion: '5.7.42',
            largePrefixValue: 'OFF'
        );
        $logs = (new DetectVarcharIndexPrefixRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $this->assertStringContainsString('VARCHAR(191)', $logs[0]->getTo());
    }

    // -------------------------------------------------------------------------
    // MySQL 5.7 with innodb_large_prefix=ON and DYNAMIC row format (limit: 3072)
    // -------------------------------------------------------------------------

    public function testDoesNotFlagVarchar255OnMySQL57WithLargePrefixDynamic(): void
    {
        // Large prefix enabled + DYNAMIC → limit 3072; 255 * 4 = 1020 < 3072
        $pdo  = $this->buildPdo(
            rows: [$this->row('products', 'name', 255, 'DYNAMIC')],
            mysqlVersion: '5.7.42',
            largePrefixValue: 'ON'
        );
        $logs = (new DetectVarcharIndexPrefixRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testFlagsVarchar255OnMySQL57WithLargePrefixButCompact(): void
    {
        // Large prefix enabled but COMPACT → limit still 767; 255 * 4 = 1020 > 767
        $pdo  = $this->buildPdo(
            rows: [$this->row('products', 'name', 255, 'COMPACT')],
            mysqlVersion: '5.7.42',
            largePrefixValue: 'ON'
        );
        $logs = (new DetectVarcharIndexPrefixRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
    }

    // -------------------------------------------------------------------------
    // Deduplication
    // -------------------------------------------------------------------------

    public function testDeduplicatesColumnAcrossMultipleIndexes(): void
    {
        $pdo = $this->buildPdo([
            $this->row('users', 'email', 255, 'COMPACT', 'PRIMARY'),
            $this->row('users', 'email', 255, 'COMPACT', 'idx_email_status'),
        ]);
        $logs = (new DetectVarcharIndexPrefixRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
    }

    // -------------------------------------------------------------------------
    // Table filters
    // -------------------------------------------------------------------------

    public function testSkipTablesExcludesTable(): void
    {
        $pdo  = $this->buildPdo([$this->row('legacy', 'ref', 255, 'COMPACT')]);
        $logs = (new DetectVarcharIndexPrefixRule())->apply($pdo, new NullOutput(), [
            'skip_tables' => ['legacy'],
        ]);

        $this->assertCount(0, $logs);
    }

    public function testSkipTableLikeExcludesMatchingTable(): void
    {
        // Default skip_table_like includes %cache%
        $pdo  = $this->buildPdo([$this->row('query_cache', 'key', 255, 'COMPACT')]);
        $logs = (new DetectVarcharIndexPrefixRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testOnlyTablesLimitsScope(): void
    {
        $pdo = $this->buildPdo([
            $this->row('users', 'email', 255, 'COMPACT'),
            $this->row('orders', 'ref', 255, 'COMPACT'),
        ]);
        $logs = (new DetectVarcharIndexPrefixRule())->apply($pdo, new NullOutput(), [
            'only_tables' => ['users'],
        ]);

        $this->assertCount(1, $logs);
        $this->assertSame('users', $logs[0]->getTable());
    }

    // -------------------------------------------------------------------------
    // Generated ALTER TABLE correctness
    // -------------------------------------------------------------------------

    public function testAlterStatementPreservesCollationAndNullability(): void
    {
        $pdo  = $this->buildPdo([
            $this->row(
                table: 'articles',
                column: 'slug',
                length: 255,
                rowFormat: 'COMPACT',
                nullable: false,
                default: null,
                collation: 'utf8mb4_unicode_ci'
            ),
        ]);
        $logs = (new DetectVarcharIndexPrefixRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $to = $logs[0]->getTo();
        $this->assertStringContainsString('`articles`', $to);
        $this->assertStringContainsString('`slug`', $to);
        $this->assertStringContainsString('VARCHAR(191)', $to);
        $this->assertStringContainsString('COLLATE utf8mb4_unicode_ci', $to);
        $this->assertStringContainsString('NOT NULL', $to);
        $this->assertStringNotContainsString('DEFAULT', $to);
    }

    public function testAlterStatementIncludesDefaultWhenPresent(): void
    {
        $pdo  = $this->buildPdo([
            $this->row(
                table: 'sessions',
                column: 'token',
                length: 255,
                rowFormat: 'COMPACT',
                nullable: true,
                default: ''
            ),
        ]);
        $logs = (new DetectVarcharIndexPrefixRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $this->assertStringContainsString("DEFAULT ''", $logs[0]->getTo());
        $this->assertStringContainsString('NULL', $logs[0]->getTo());
    }

    public function testFromFieldDescribesIndexAndByteCount(): void
    {
        $pdo  = $this->buildPdo([
            $this->row('users', 'email', 255, 'COMPACT', 'uniq_email', unique: true),
        ]);
        $logs = (new DetectVarcharIndexPrefixRule())->apply($pdo, new NullOutput());

        $from = $logs[0]->getFrom();
        $this->assertStringContainsString('UNIQUE', $from);
        $this->assertStringContainsString('uniq_email', $from);
        $this->assertStringContainsString('1020 bytes', $from);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function buildPdo(
        array $rows,
        string $mysqlVersion = '8.0.28',
        ?string $largePrefixValue = null
    ): PDO {
        $versionStmt = $this->createMock(PDOStatement::class);
        $versionStmt->method('fetchColumn')->willReturn($mysqlVersion);

        $largePrefixRow = $largePrefixValue !== null
            ? ['Variable_name' => 'innodb_large_prefix', 'Value' => $largePrefixValue]
            : false;

        $largePrefixStmt = $this->createMock(PDOStatement::class);
        $largePrefixStmt->method('fetch')->willReturn($largePrefixRow);

        $columnsStmt = $this->createMock(PDOStatement::class);
        $columnsStmt->method('execute')->willReturn(true);
        $columnsStmt->method('fetchAll')->willReturn($rows);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnCallback(
            function (string $sql) use ($versionStmt, $largePrefixStmt): PDOStatement {
                if (stripos($sql, 'VERSION()') !== false) {
                    return $versionStmt;
                }
                return $largePrefixStmt;
            }
        );
        $pdo->method('prepare')->willReturn($columnsStmt);

        return $pdo;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        string $table,
        string $column,
        int $length,
        string $rowFormat = 'COMPACT',
        string $indexName = 'idx_col',
        bool $unique = false,
        bool $nullable = true,
        ?string $default = null,
        string $collation = 'utf8mb4_unicode_ci',
        string $dataType = 'varchar'
    ): array {
        return [
            'TABLE_NAME'              => $table,
            'INDEX_NAME'              => $indexName,
            'NON_UNIQUE'              => $unique ? '0' : '1',
            'COLUMN_NAME'             => $column,
            'DATA_TYPE'               => $dataType,
            'CHARACTER_MAXIMUM_LENGTH' => $length,
            'CHARACTER_SET_NAME'      => 'utf8mb4',
            'COLLATION_NAME'          => $collation,
            'IS_NULLABLE'             => $nullable ? 'YES' : 'NO',
            'COLUMN_DEFAULT'          => $default,
            'ROW_FORMAT'              => $rowFormat,
        ];
    }
}
