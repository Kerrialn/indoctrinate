<?php

declare(strict_types=1);

namespace IndoctrinateTest\Rule\Integrity;

use Indoctrinate\Rule\Integrity\EnsureAutoIncrementPrimaryKeyRule;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class EnsureAutoIncrementPrimaryKeyRuleTest extends TestCase
{
    public function testSkipsTableWithProperIntAutoIncrementIdPk(): void
    {
        $pdo = $this->buildPdo(
            tables: ['users'],
            prepareCallback: function (string $sql) {
                // getPrimaryKeyColumns → single 'id'
                if (strpos($sql, 'CONSTRAINT_TYPE') !== false) {
                    return $this->stmtReturningRows([['COLUMN_NAME' => 'id']]);
                }
                // getColumnInfo for 'id' → int auto_increment
                return $this->stmtReturningFetch([
                    'COLUMN_NAME' => 'id', 'DATA_TYPE' => 'int', 'COLUMN_TYPE' => 'int unsigned',
                    'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => 'auto_increment',
                ]);
            }
        );

        $logs = (new EnsureAutoIncrementPrimaryKeyRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testFlagsTableWithNoPrimaryKeyInDryMode(): void
    {
        $emptyRowsStmt = $this->stmtReturningRows([]);
        $emptyFetchStmt = $this->stmtReturningFetch(false);

        $pdo = $this->buildPdo(
            tables: ['users'],
            prepareCallback: function (string $sql) use ($emptyRowsStmt, $emptyFetchStmt) {
                // All PK queries return no columns
                if (strpos($sql, 'CONSTRAINT_TYPE') !== false) {
                    return $emptyRowsStmt;
                }
                // getColumnInfo('id') → null (no id column)
                if (strpos($sql, 'COLUMN_NAME = :c') !== false) {
                    return $emptyFetchStmt;
                }
                // STATISTICS query for guessStableOrdering → no indexes
                return $this->stmtReturningRows([]);
            }
        );

        $logs = (new EnsureAutoIncrementPrimaryKeyRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        $this->assertNotEmpty($logs);
        $this->assertSame('users', $logs[0]->getTable());
        $this->assertSame('id', $logs[0]->getColumn());
        $this->assertStringContainsString('no primary key', $logs[0]->getTo());
    }

    public function testFlagsCompositePrimaryKeyInDryMode(): void
    {
        $compositePkStmt = $this->stmtReturningRows([
            ['COLUMN_NAME' => 'user_id'],
            ['COLUMN_NAME' => 'role_id'],
        ]);
        // isPureJoinTable check: getPrimaryKeyColumns returns composite, then getForeignKeys returns only 1 → NOT a join table
        $fkStmt = $this->stmtReturningRows([['COLUMN_NAME' => 'user_id']]); // only 1 FK col → not pure join

        $callCount = 0;
        $pdo = $this->buildPdo(
            tables: ['user_roles'],
            prepareCallback: function (string $sql) use ($compositePkStmt, $fkStmt, &$callCount) {
                if (strpos($sql, 'CONSTRAINT_TYPE') !== false) {
                    return $compositePkStmt; // always 2 PK cols
                }
                if (strpos($sql, 'REFERENCED_TABLE_NAME IS NOT NULL') !== false) {
                    return $fkStmt; // only user_id is FK → not pure join table
                }
                return $this->stmtReturningRows([]); // getAllColumns, statistics, etc.
            }
        );

        $logs = (new EnsureAutoIncrementPrimaryKeyRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        $this->assertNotEmpty($logs);
        $this->assertSame('user_roles', $logs[0]->getTable());
        $this->assertStringContainsString('composite primary key', $logs[0]->getTo());
    }

    public function testRecognizesJoinTableAndEmitsOkLog(): void
    {
        // join table: 2-column composite PK, both are FKs, no other columns
        $pkStmt = $this->stmtReturningRows([['COLUMN_NAME' => 'user_id'], ['COLUMN_NAME' => 'tag_id']]);
        $fkStmt = $this->stmtReturningRows([['COLUMN_NAME' => 'user_id'], ['COLUMN_NAME' => 'tag_id']]);
        $allColsStmt = $this->stmtReturningRows([['COLUMN_NAME' => 'user_id'], ['COLUMN_NAME' => 'tag_id']]);

        $callOrder = 0;
        $pdo = $this->buildPdo(
            tables: ['user_tags'],
            prepareCallback: function (string $sql) use ($pkStmt, $fkStmt, $allColsStmt, &$callOrder) {
                $callOrder++;
                if (strpos($sql, 'CONSTRAINT_TYPE') !== false) {
                    return $pkStmt;
                }
                if (strpos($sql, 'REFERENCED_TABLE_NAME IS NOT NULL') !== false) {
                    return $fkStmt;
                }
                // getAllColumns
                return $allColsStmt;
            }
        );

        $logs = (new EnsureAutoIncrementPrimaryKeyRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $this->assertStringContainsString('composite primary key', $logs[0]->getTo());
    }

    /** @param array<int,array<string,string>> $tables @param callable $prepareCallback */
    private function buildPdo(array $tables, callable $prepareCallback): PDO
    {
        $tableStmt = $this->createMock(PDOStatement::class);
        $tableStmt->method('fetchAll')->willReturn($tables);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($tableStmt);
        $pdo->method('prepare')->willReturnCallback($prepareCallback);

        return $pdo;
    }

    /** @param array<int,array<string,string>> $rows */
    private function stmtReturningRows(array $rows): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn($rows);
        return $stmt;
    }

    /** @param array<string,mixed>|false $row */
    private function stmtReturningFetch($row): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($row);
        return $stmt;
    }
}
