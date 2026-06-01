<?php

declare(strict_types=1);

namespace IndoctrinateTest\Rule\MySQL\Integrity;

use Indoctrinate\Rule\MySQL\Integrity\EnsureUnifiedPrimaryKeyNameRule;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class EnsureUnifiedPrimaryKeyNameRuleTest extends TestCase
{
    public function testSkipsTableWhosePkIsAlreadyNamedIdWithNoUuidColumn(): void
    {
        // id is PK, uuid column gone — migration already complete
        $pdo = $this->buildPdo(['users'], ['id'], null, false, []);

        $logs = (new EnsureUnifiedPrimaryKeyNameRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testSkipsTableWithNoPrimaryKey(): void
    {
        $pdo = $this->buildPdo(['logs'], [], null, false, []);

        $logs = (new EnsureUnifiedPrimaryKeyNameRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testSkipsTableMatchingSkipTableLike(): void
    {
        $pdo = $this->buildPdo(['sessions'], ['uuid'], null, false, []);

        $logs = (new EnsureUnifiedPrimaryKeyNameRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    public function testSkipsUuidPkThatIsNotChar36(): void
    {
        $pdo = $this->buildPdo(
            ['things'],
            ['uuid'],
            [
                'COLUMN_NAME' => 'uuid',
                'COLUMN_TYPE' => 'varchar(36)',
                'IS_NULLABLE' => 'NO',
                'EXTRA' => '',
            ],
            false,
            []
        );

        $logs = (new EnsureUnifiedPrimaryKeyNameRule())->apply($pdo, new NullOutput());

        $this->assertCount(0, $logs);
    }

    // --- Auto-detect: expand phase ---

    public function testAutoDetectRunsExpandWhenIdColumnMissing(): void
    {
        // uuid is PK, id does not exist → auto-detect triggers expand
        $pdo = $this->buildPdo(
            ['products'],
            ['uuid'],
            [
                'COLUMN_NAME' => 'uuid',
                'COLUMN_TYPE' => 'char(36)',
                'IS_NULLABLE' => 'NO',
                'EXTRA' => '',
            ],
            false,
            []
        );

        $logs = (new EnsureUnifiedPrimaryKeyNameRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
        ]);

        $this->assertCount(1, $logs);
        $this->assertSame('products', $logs[0]->getTable());
        $this->assertSame('expand', $logs[0]->getFrom());
    }

    // --- Auto-detect: contract phase ---

    public function testAutoDetectRunsContractWhenIdColumnAlreadyExists(): void
    {
        // uuid is PK, id already exists (expand already ran) → auto-detect triggers contract
        $pdo = $this->buildPdo(
            ['products'],
            ['uuid'],
            [
                'COLUMN_NAME' => 'id',
                'COLUMN_TYPE' => 'char(36)',
                'IS_NULLABLE' => 'YES',
                'EXTRA' => '',
            ],
            true,
            []
        );

        $logs = (new EnsureUnifiedPrimaryKeyNameRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
        ]);

        $this->assertCount(1, $logs);
        $this->assertSame('contract', $logs[0]->getFrom());
    }

    // --- Auto-detect: remove phase ---

    public function testAutoDetectRunsRemoveWhenIdIsPkAndUuidStillPresent(): void
    {
        // id is PK, uuid column still exists → auto-detect triggers remove
        $pdo = $this->buildPdoForRemove(['orders'], true);

        $logs = (new EnsureUnifiedPrimaryKeyNameRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
        ]);

        $this->assertCount(1, $logs);
        $this->assertSame('remove', $logs[0]->getFrom());
    }

    // --- Explicit phase flags ---

    public function testExplicitExpandOnlySkipsContractEvenIfIdExists(): void
    {
        // expand: true, contract not set → only expand runs, contract skipped
        $pdo = $this->buildPdo(
            ['products'],
            ['uuid'],
            [
                'COLUMN_NAME' => 'uuid',
                'COLUMN_TYPE' => 'char(36)',
                'IS_NULLABLE' => 'NO',
                'EXTRA' => '',
            ],
            false,
            []
        );

        $logs = (new EnsureUnifiedPrimaryKeyNameRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
            'expand' => true,
        ]);

        $this->assertCount(1, $logs);
        $this->assertSame('expand', $logs[0]->getFrom());
    }

    public function testExplicitContractOnlySkipsExpandEvenIfIdMissing(): void
    {
        // contract: true, id does not exist → expand skipped, contract also skipped (hasId=false)
        $pdo = $this->buildPdo(
            ['products'],
            ['uuid'],
            [
                'COLUMN_NAME' => 'uuid',
                'COLUMN_TYPE' => 'char(36)',
                'IS_NULLABLE' => 'NO',
                'EXTRA' => '',
            ],
            false,
            []
        );

        $logs = (new EnsureUnifiedPrimaryKeyNameRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
            'contract' => true,
        ]);

        // No log: expand skipped (not requested), contract skipped (id doesn't exist yet)
        $this->assertCount(0, $logs);
    }

    public function testExplicitRemoveFalseLogsWarningWhenUuidStillPresent(): void
    {
        $pdo = $this->buildPdoForRemove(['orders'], true);

        $logs = (new EnsureUnifiedPrimaryKeyNameRule())->apply($pdo, new NullOutput(), [
            'remove' => false,
        ]);

        $this->assertCount(1, $logs);
        $this->assertSame('pending remove', $logs[0]->getFrom());
    }

    public function testAllThreePhasesCanRunTogetherInDryMode(): void
    {
        // expand: true, contract: true, remove: false (uuid PK, no id yet) → expand runs, contract skipped (no id after dry expand), remove skipped
        $pdo = $this->buildPdo(
            ['products'],
            ['uuid'],
            [
                'COLUMN_NAME' => 'uuid',
                'COLUMN_TYPE' => 'char(36)',
                'IS_NULLABLE' => 'NO',
                'EXTRA' => '',
            ],
            false,
            []
        );

        $logs = (new EnsureUnifiedPrimaryKeyNameRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
            'expand' => true,
            'contract' => true,
        ]);

        // Expand logs; contract skipped because dry expand does not create the id column in DB
        $this->assertCount(1, $logs);
        $this->assertSame('expand', $logs[0]->getFrom());
    }

    // --- helpers ---

    /**
     * @param string[] $tables
     * @param string[] $pkCols
     * @param array<string,string>|null $colInfo  info returned by getColumnInfo (uuid or id depending on phase)
     * @param array<int,array<string,string>> $childFkMeta
     */
    private function buildPdo(
        array $tables,
        array $pkCols,
        ?array $colInfo,
        bool $idColExists,
        array $childFkMeta
    ): PDO {
        $tableStmt = $this->createMock(PDOStatement::class);
        $tableStmt->method('fetchAll')->willReturn($tables);

        $pkStmt = $this->createMock(PDOStatement::class);
        $pkStmt->method('execute')->willReturn(true);
        $pkStmt->method('fetchAll')->willReturn(array_map(fn ($c) => [
            'COLUMN_NAME' => $c,
        ], $pkCols));

        // columnExists — SELECT 1 queries
        $existsStmt = $this->createMock(PDOStatement::class);
        $existsStmt->method('execute')->willReturn(true);
        $existsStmt->method('fetchColumn')->willReturn($idColExists ? '1' : false);

        // getColumnInfo — SELECT COLUMN_NAME, COLUMN_TYPE queries
        $colInfoStmt = $this->createMock(PDOStatement::class);
        $colInfoStmt->method('execute')->willReturn(true);
        $colInfoStmt->method('fetch')->willReturn($colInfo ?? false);

        $childFkStmt = $this->createMock(PDOStatement::class);
        $childFkStmt->method('execute')->willReturn(true);
        $childFkStmt->method('fetchAll')->willReturn($childFkMeta);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($tableStmt);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($pkStmt, $existsStmt, $colInfoStmt, $childFkStmt) {
                if (strpos($sql, 'CONSTRAINT_TYPE') !== false) {
                    return $pkStmt;
                }
                if (strpos($sql, 'REFERENCED_COLUMN_NAME') !== false) {
                    return $childFkStmt;
                }
                if (strpos($sql, 'COLUMN_TYPE') !== false) {
                    return $colInfoStmt;
                }
                // SELECT 1 — columnExists
                return $existsStmt;
            }
        );

        return $pdo;
    }

    /**
     * Build a PDO mock wired for the remove phase: id is PK, uuid column optionally present.
     *
     * @param string[] $tables
     */
    private function buildPdoForRemove(array $tables, bool $uuidStillPresent): PDO
    {
        $tableStmt = $this->createMock(PDOStatement::class);
        $tableStmt->method('fetchAll')->willReturn($tables);

        // PK is 'id'
        $pkStmt = $this->createMock(PDOStatement::class);
        $pkStmt->method('execute')->willReturn(true);
        $pkStmt->method('fetchAll')->willReturn([[
            'COLUMN_NAME' => 'id',
        ]]);

        // columnExists('uuid') check
        $existsStmt = $this->createMock(PDOStatement::class);
        $existsStmt->method('execute')->willReturn(true);
        $existsStmt->method('fetchColumn')->willReturn($uuidStillPresent ? '1' : false);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($tableStmt);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($pkStmt, $existsStmt) {
                if (strpos($sql, 'CONSTRAINT_TYPE') !== false) {
                    return $pkStmt;
                }
                return $existsStmt;
            }
        );

        return $pdo;
    }
}
