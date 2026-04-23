<?php

declare(strict_types=1);

namespace IndoctrinateTest\Rule\Integrity;

use Indoctrinate\Rule\Integrity\EnsureUnifiedPrimaryKeyNameRule;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class EnsureUnifiedPrimaryKeyNameRuleTest extends TestCase
{
    public function testSkipsTableWhosePkIsAlreadyNamedId(): void
    {
        $pdo = $this->buildPdo(
            tables: ['users'],
            pkCols: ['id'], // PK is 'id', not 'uuid' → skip
        );

        $logs = (new EnsureUnifiedPrimaryKeyNameRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testSkipsTableWithNoPrimaryKey(): void
    {
        $pdo = $this->buildPdo(
            tables: ['logs'],
            pkCols: [],
        );

        $logs = (new EnsureUnifiedPrimaryKeyNameRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testSkipsTableMatchingSkipTableLike(): void
    {
        // %session% is in default skip_table_like
        $pdo = $this->buildPdo(
            tables: ['sessions'],
            pkCols: ['uuid'],
        );

        $logs = (new EnsureUnifiedPrimaryKeyNameRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testRenamesUuidPkToIdInDryMode(): void
    {
        $pdo = $this->buildPdo(
            tables: ['products'],
            pkCols: ['uuid'],
            uuidColInfo: ['COLUMN_NAME' => 'uuid', 'COLUMN_TYPE' => 'char(36)', 'IS_NULLABLE' => 'NO', 'EXTRA' => ''],
            idColExists: false,
            childFkMeta: [],
        );

        $logs = (new EnsureUnifiedPrimaryKeyNameRule())->apply($pdo, new NullOutput(), ['dry' => true]);

        $this->assertCount(1, $logs);
        $this->assertSame('products', $logs[0]->getTable());
        $this->assertSame('uuid→id', $logs[0]->getColumn());
    }

    public function testSkipsUuidPkThatIsNotChar36(): void
    {
        // PK named 'uuid' but wrong type (e.g. varchar) → skip
        $pdo = $this->buildPdo(
            tables: ['things'],
            pkCols: ['uuid'],
            uuidColInfo: ['COLUMN_NAME' => 'uuid', 'COLUMN_TYPE' => 'varchar(36)', 'IS_NULLABLE' => 'NO', 'EXTRA' => ''],
        );

        $logs = (new EnsureUnifiedPrimaryKeyNameRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    /**
     * @param string[] $tables
     * @param string[] $pkCols
     * @param array<string,string>|null $uuidColInfo
     * @param array<int,array<string,string>> $childFkMeta
     */
    private function buildPdo(
        array $tables,
        array $pkCols,
        ?array $uuidColInfo = null,
        bool $idColExists = false,
        array $childFkMeta = []
    ): PDO {
        $tableStmt = $this->createMock(PDOStatement::class);
        $tableStmt->method('fetchAll')->willReturn($tables);

        $pkStmt = $this->createMock(PDOStatement::class);
        $pkStmt->method('execute')->willReturn(true);
        $pkStmt->method('fetchAll')->willReturn(array_map(fn($c) => ['COLUMN_NAME' => $c], $pkCols));

        $colInfoStmt = $this->createMock(PDOStatement::class);
        $colInfoStmt->method('execute')->willReturn(true);
        $colInfoStmt->method('fetch')->willReturn($uuidColInfo ?? false);

        $idExistsStmt = $this->createMock(PDOStatement::class);
        $idExistsStmt->method('execute')->willReturn(true);
        $idExistsStmt->method('fetchColumn')->willReturn($idColExists ? '1' : false);

        $childFkStmt = $this->createMock(PDOStatement::class);
        $childFkStmt->method('execute')->willReturn(true);
        $childFkStmt->method('fetchAll')->willReturn($childFkMeta);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($tableStmt);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($pkStmt, $colInfoStmt, $idExistsStmt, $childFkStmt) {
                if (strpos($sql, 'CONSTRAINT_TYPE') !== false) {
                    return $pkStmt;
                }
                if (strpos($sql, "COLUMN_NAME = :c") !== false) {
                    return $colInfoStmt;
                }
                if (strpos($sql, 'REFERENCED_COLUMN_NAME = :pk') !== false) {
                    return $childFkStmt;
                }
                // columnExists check
                return $idExistsStmt;
            }
        );

        return $pdo;
    }
}
