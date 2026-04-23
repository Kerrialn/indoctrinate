<?php

declare(strict_types=1);

namespace IndoctrinateTest\Rule\MySQL\Integrity;

use Indoctrinate\Rule\MySQL\Integrity\EnsureCharsetCollationRule;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class EnsureCharsetCollationRuleTest extends TestCase
{
    public function testDetectsTableWithWrongCharset(): void
    {
        $pdo = $this->buildPdo(
            tables: [
                ['TABLE_NAME' => 'users', 'TABLE_COLLATION' => 'latin1_swedish_ci', 'CHARACTER_SET_NAME' => 'latin1'],
            ],
            columns: []
        );

        $logs = (new EnsureCharsetCollationRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $this->assertSame('users', $logs[0]->getTable());
        $this->assertSame('(table)', $logs[0]->getColumn());
        $this->assertSame('latin1 / latin1_swedish_ci', $logs[0]->getFrom());
        $this->assertSame(
            'ALTER TABLE `users` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $logs[0]->getTo()
        );
    }

    public function testSkipsTableWithCorrectCharset(): void
    {
        $pdo = $this->buildPdo(
            tables: [
                ['TABLE_NAME' => 'users', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'CHARACTER_SET_NAME' => 'utf8mb4'],
            ],
            columns: []
        );

        $logs = (new EnsureCharsetCollationRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testDetectsColumnWithWrongCharset(): void
    {
        $pdo = $this->buildPdo(
            tables: [],
            columns: [
                [
                    'TABLE_NAME' => 'users',
                    'COLUMN_NAME' => 'name',
                    'COLUMN_TYPE' => 'varchar(255)',
                    'CHARACTER_SET_NAME' => 'latin1',
                    'COLLATION_NAME' => 'latin1_swedish_ci',
                ],
            ]
        );

        $logs = (new EnsureCharsetCollationRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $this->assertSame('users', $logs[0]->getTable());
        $this->assertSame('name', $logs[0]->getColumn());
        $this->assertStringContainsString('latin1', $logs[0]->getFrom());
        $this->assertSame(
            'ALTER TABLE `users` MODIFY COLUMN `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $logs[0]->getTo()
        );
    }

    public function testSkipsTableMatchingSkipTableLikePattern(): void
    {
        $pdo = $this->buildPdo(
            tables: [
                ['TABLE_NAME' => 'cache_items', 'TABLE_COLLATION' => 'latin1_swedish_ci', 'CHARACTER_SET_NAME' => 'latin1'],
            ],
            columns: []
        );

        // Default skip_table_like includes %cache%
        $logs = (new EnsureCharsetCollationRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testSkipsExactTableInSkipTables(): void
    {
        $pdo = $this->buildPdo(
            tables: [
                ['TABLE_NAME' => 'legacy', 'TABLE_COLLATION' => 'latin1_swedish_ci', 'CHARACTER_SET_NAME' => 'latin1'],
            ],
            columns: []
        );

        $logs = (new EnsureCharsetCollationRule())->apply($pdo, new NullOutput(), [
            'skip_tables' => ['legacy'],
        ]);

        $this->assertCount(0, $logs);
    }

    public function testOnlyTablesFilterLimitsScope(): void
    {
        $pdo = $this->buildPdo(
            tables: [
                ['TABLE_NAME' => 'users', 'TABLE_COLLATION' => 'latin1_swedish_ci', 'CHARACTER_SET_NAME' => 'latin1'],
                ['TABLE_NAME' => 'orders', 'TABLE_COLLATION' => 'latin1_swedish_ci', 'CHARACTER_SET_NAME' => 'latin1'],
            ],
            columns: []
        );

        $logs = (new EnsureCharsetCollationRule())->apply($pdo, new NullOutput(), [
            'only_tables' => ['users'],
        ]);

        $this->assertCount(1, $logs);
        $this->assertSame('users', $logs[0]->getTable());
    }

    public function testColumnCheckCanBeDisabled(): void
    {
        $tableStmt = $this->createMock(PDOStatement::class);
        $tableStmt->method('fetchAll')->willReturn([]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($tableStmt);
        $pdo->expects($this->never())->method('prepare');

        $logs = (new EnsureCharsetCollationRule())->apply($pdo, new NullOutput(), [
            'check_columns' => false,
        ]);

        $this->assertCount(0, $logs);
    }

    public function testCustomTargetCharsetAndCollation(): void
    {
        $pdo = $this->buildPdo(
            tables: [
                ['TABLE_NAME' => 'users', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'CHARACTER_SET_NAME' => 'utf8mb4'],
            ],
            columns: []
        );

        // Targeting a different collation means utf8mb4_unicode_ci tables should now be flagged
        $logs = (new EnsureCharsetCollationRule())->apply($pdo, new NullOutput(), [
            'target_charset' => 'utf8mb4',
            'target_collation' => 'utf8mb4_0900_ai_ci',
        ]);

        $this->assertCount(1, $logs);
        $this->assertStringContainsString('utf8mb4_0900_ai_ci', $logs[0]->getTo());
    }

    public function testTableAndColumnMismatchesAreReturnedTogether(): void
    {
        $pdo = $this->buildPdo(
            tables: [
                ['TABLE_NAME' => 'users', 'TABLE_COLLATION' => 'latin1_swedish_ci', 'CHARACTER_SET_NAME' => 'latin1'],
            ],
            columns: [
                [
                    'TABLE_NAME' => 'users',
                    'COLUMN_NAME' => 'bio',
                    'COLUMN_TYPE' => 'text',
                    'CHARACTER_SET_NAME' => 'latin1',
                    'COLLATION_NAME' => 'latin1_swedish_ci',
                ],
            ]
        );

        $logs = (new EnsureCharsetCollationRule())->apply($pdo, new NullOutput());

        $this->assertCount(2, $logs);
        $this->assertSame('(table)', $logs[0]->getColumn());
        $this->assertSame('bio', $logs[1]->getColumn());
    }

    /**
     * @param array<int, array<string, string>> $tables
     * @param array<int, array<string, string>> $columns
     */
    private function buildPdo(array $tables, array $columns): PDO
    {
        $tableStmt = $this->createMock(PDOStatement::class);
        $tableStmt->method('fetchAll')->willReturn($tables);

        $colStmt = $this->createMock(PDOStatement::class);
        $colStmt->method('execute')->willReturn(true);
        $colStmt->method('fetchAll')->willReturn($columns);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($tableStmt);
        $pdo->method('prepare')->willReturn($colStmt);

        return $pdo;
    }
}
