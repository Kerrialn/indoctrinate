<?php

declare(strict_types=1);

namespace IndoctrinateTest\Rule\MySQL\Integrity;

use Indoctrinate\Rule\MySQL\Integrity\EnsurePrimaryKeyUuidRule;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class EnsurePrimaryKeyUuidRuleTest extends TestCase
{
    public function testSkipsTableFilteredBySkipTableLike(): void
    {
        // %session% is in default skip_table_like
        $pdo = $this->buildPdo(tables: ['user_sessions'], childFks: [], pkCols: []);

        $logs = (new EnsurePrimaryKeyUuidRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testAlreadyCorrectUuidPkProducesOkLog(): void
    {
        $pdo = $this->buildPdo(
            tables: ['users'],
            childFks: [],
            pkCols: ['id'],
            colInfo: ['COLUMN_NAME' => 'id', 'COLUMN_TYPE' => 'char(36)', 'IS_NULLABLE' => 'NO', 'EXTRA' => ''],
        );

        $logs = (new EnsurePrimaryKeyUuidRule())->apply($pdo, new NullOutput());

        $this->assertCount(1, $logs);
        $this->assertSame('users', $logs[0]->getTable());
        $this->assertSame('id', $logs[0]->getColumn());
        $this->assertSame('already CHAR(36) NOT NULL', $logs[0]->getFrom());
        $this->assertSame('OK', $logs[0]->getTo());
    }

    public function testNullableChar36PkIsMarkedForTighteningInDryMode(): void
    {
        $pdo = $this->buildPdo(
            tables: ['users'],
            childFks: [],
            pkCols: ['id'],
            colInfo: ['COLUMN_NAME' => 'id', 'COLUMN_TYPE' => 'char(36)', 'IS_NULLABLE' => 'YES', 'EXTRA' => ''],
        );

        $logs = (new EnsurePrimaryKeyUuidRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        $this->assertCount(1, $logs);
        $this->assertStringContainsString('DRY', $logs[0]->getTo());
        $this->assertSame('CHAR(36) NULL', $logs[0]->getFrom());
    }

    public function testTableWithNoPrimaryKeyIsLoggedInDryMode(): void
    {
        $pdo = $this->buildPdo(
            tables: ['logs'],
            childFks: [],
            pkCols: [], // no PK
        );

        $logs = (new EnsurePrimaryKeyUuidRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        $this->assertCount(1, $logs);
        $this->assertSame('logs', $logs[0]->getTable());
        $this->assertStringContainsString('DRY', $logs[0]->getTo());
        $this->assertSame('missing', $logs[0]->getFrom());
    }

    public function testNonUuidNonIdSinglePkWithNoChildrenIsLoggedInDryMode(): void
    {
        // PK is 'user_id' (int) with no child FKs → int-like, no children → dry log
        $pdo = $this->buildPdo(
            tables: ['users'],
            childFks: [],
            pkCols: ['user_id'],
            colInfo: ['COLUMN_NAME' => 'user_id', 'COLUMN_TYPE' => 'int unsigned', 'IS_NULLABLE' => 'NO', 'EXTRA' => ''],
        );

        $logs = (new EnsurePrimaryKeyUuidRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        $this->assertNotEmpty($logs);
        $this->assertSame('users', $logs[0]->getTable());
        $this->assertStringContainsString('DRY', $logs[0]->getTo());
    }

    /**
     * Build a PDO mock for EnsurePrimaryKeyUuidRule.
     *
     * Flow:
     *  query #1 → tables list (FETCH_COLUMN)
     *  query #2 → childFkCounts (FETCH_ASSOC)
     *  prepare → getPrimaryKeyColumns (contains CONSTRAINT_TYPE)
     *  prepare → getColumnInfo (contains COLUMN_NAME = :c)
     *
     * @param string[] $tables
     * @param array<string,int> $childFks  parent_table => count
     * @param string[] $pkCols
     * @param array<string,string>|null $colInfo
     */
    private function buildPdo(array $tables, array $childFks, array $pkCols, ?array $colInfo = null): PDO
    {
        $tableStmt = $this->createMock(PDOStatement::class);
        $tableStmt->method('fetchAll')->willReturn($tables);

        $fkCountRows = [];
        foreach ($childFks as $parent => $count) {
            $fkCountRows[] = ['parent_table' => $parent, 'c' => (string) $count];
        }
        $fkCountStmt = $this->createMock(PDOStatement::class);
        $fkCountStmt->method('fetchAll')->willReturn($fkCountRows);

        $pkStmt = $this->createMock(PDOStatement::class);
        $pkStmt->method('execute')->willReturn(true);
        $pkStmt->method('fetchAll')->willReturn(array_map(fn($c) => ['COLUMN_NAME' => $c], $pkCols));

        $colStmt = $this->createMock(PDOStatement::class);
        $colStmt->method('execute')->willReturn(true);
        $colStmt->method('fetch')->willReturn($colInfo ?? false);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnOnConsecutiveCalls($tableStmt, $fkCountStmt);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($pkStmt, $colStmt) {
            if (strpos($sql, 'CONSTRAINT_TYPE') !== false) {
                return $pkStmt;
            }
            return $colStmt;
        });

        return $pdo;
    }
}
