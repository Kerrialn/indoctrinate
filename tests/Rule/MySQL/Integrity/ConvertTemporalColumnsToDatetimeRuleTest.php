<?php

declare(strict_types=1);

namespace IndoctrinateTest\Rule\MySQL\Integrity;

use Indoctrinate\Rule\MySQL\Integrity\ConvertTemporalColumnsToDatetimeRule;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class ConvertTemporalColumnsToDatetimeRuleTest extends TestCase
{
    public function testReturnsEmptyWhenNoTemporalColumns(): void
    {
        $pdo = $this->buildPdo([], false);

        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
        ]);

        $this->assertCount(0, $logs);
    }

    // --- Auto-detect: expand phase ---

    public function testAutoDetectExpandsDateColumnWhenDtColumnMissing(): void
    {
        $pdo = $this->buildPdo([
            [
                'TABLE_NAME' => 'users',
                'COLUMN_NAME' => 'dob',
                'DATA_TYPE' => 'date',
                'COLUMN_TYPE' => 'date',
                'IS_NULLABLE' => 'YES',
                'COLUMN_DEFAULT' => null,
                'EXTRA' => '',
            ],
        ], false);

        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
        ]);

        $this->assertCount(1, $logs);
        $this->assertSame('users', $logs[0]->getTable());
        $this->assertSame('dob', $logs[0]->getColumn());
        $this->assertSame('expand', $logs[0]->getFrom());
    }

    public function testAutoDetectExpandsTimestampColumnWhenDtColumnMissing(): void
    {
        $pdo = $this->buildPdo([
            [
                'TABLE_NAME' => 'posts',
                'COLUMN_NAME' => 'created_at',
                'DATA_TYPE' => 'timestamp',
                'COLUMN_TYPE' => 'timestamp',
                'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => null,
                'EXTRA' => '',
            ],
        ], false);

        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
        ]);

        $this->assertCount(1, $logs);
        $this->assertSame('expand', $logs[0]->getFrom());
    }

    // --- Auto-detect: remove phase ---

    public function testAutoDetectRemovesWhenDtColumnAlreadyExists(): void
    {
        // {col}_dt already present means expand already ran — auto-detect triggers remove
        $pdo = $this->buildPdo([
            [
                'TABLE_NAME' => 'users',
                'COLUMN_NAME' => 'dob',
                'DATA_TYPE' => 'date',
                'COLUMN_TYPE' => 'date',
                'IS_NULLABLE' => 'YES',
                'COLUMN_DEFAULT' => null,
                'EXTRA' => '',
            ],
        ], true);

        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
        ]);

        $this->assertCount(1, $logs);
        $this->assertSame('remove', $logs[0]->getFrom());
    }

    // --- Explicit phase flags ---

    public function testExplicitExpandProducesExpandLog(): void
    {
        $pdo = $this->buildPdo([
            [
                'TABLE_NAME' => 'users',
                'COLUMN_NAME' => 'dob',
                'DATA_TYPE' => 'date',
                'COLUMN_TYPE' => 'date',
                'IS_NULLABLE' => 'YES',
                'COLUMN_DEFAULT' => null,
                'EXTRA' => '',
            ],
        ], false);

        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
            'expand' => true,
        ]);

        $this->assertCount(1, $logs);
        $this->assertSame('expand', $logs[0]->getFrom());
    }

    public function testExplicitContractProducesContractLog(): void
    {
        $pdo = $this->buildPdo([
            [
                'TABLE_NAME' => 'users',
                'COLUMN_NAME' => 'dob',
                'DATA_TYPE' => 'date',
                'COLUMN_TYPE' => 'date',
                'IS_NULLABLE' => 'YES',
                'COLUMN_DEFAULT' => null,
                'EXTRA' => '',
            ],
        ], true);

        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
            'contract' => true,
        ]);

        $this->assertCount(1, $logs);
        $this->assertSame('contract', $logs[0]->getFrom());
    }

    public function testExplicitRemoveProducesRemoveLog(): void
    {
        $pdo = $this->buildPdo([
            [
                'TABLE_NAME' => 'users',
                'COLUMN_NAME' => 'dob',
                'DATA_TYPE' => 'date',
                'COLUMN_TYPE' => 'date',
                'IS_NULLABLE' => 'YES',
                'COLUMN_DEFAULT' => null,
                'EXTRA' => '',
            ],
        ], true);

        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
            'remove' => true,
        ]);

        $this->assertCount(1, $logs);
        $this->assertSame('remove', $logs[0]->getFrom());
    }

    public function testExpandSkippedWhenDtColumnAlreadyExistsAndExplicitExpandSet(): void
    {
        // expand: true but {col}_dt already exists — skip expand, don't log
        $pdo = $this->buildPdo([
            [
                'TABLE_NAME' => 'users',
                'COLUMN_NAME' => 'dob',
                'DATA_TYPE' => 'date',
                'COLUMN_TYPE' => 'date',
                'IS_NULLABLE' => 'YES',
                'COLUMN_DEFAULT' => null,
                'EXTRA' => '',
            ],
        ], true);

        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
            'expand' => true,
        ]);

        $this->assertCount(0, $logs);
    }

    // --- Existing DATETIME default-fixing (no E/C, keeps existing behaviour) ---

    public function testExistingDatetimeWithZeroDefaultGetsFixed(): void
    {
        $pdo = $this->buildPdo([
            [
                'TABLE_NAME' => 'events',
                'COLUMN_NAME' => 'starts_at',
                'DATA_TYPE' => 'datetime',
                'COLUMN_TYPE' => 'datetime',
                'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => '0000-00-00 00:00:00',
                'EXTRA' => '',
            ],
        ], false);

        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
        ]);

        $this->assertCount(1, $logs);
        $this->assertStringContainsString('zero', $logs[0]->getTo());
    }

    // --- _dt helper column is not processed independently ---

    public function testDtHelperColumnIsNotProcessedWhenSourceColumnExists(): void
    {
        // Simulate the state mid-migration: temporalColumns() returns BOTH the source
        // DATE column AND its _dt DATETIME helper that was added during expand.
        // The rule must filter out created_at_dt and produce exactly one log (the remove
        // for created_at), not a second log from the default-fixer branch treating
        // created_at_dt as a standalone DATETIME column.
        $pdo = $this->buildPdo([
            [
                'TABLE_NAME' => 'users',
                'COLUMN_NAME' => 'created_at',
                'DATA_TYPE' => 'date',
                'COLUMN_TYPE' => 'date',
                'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => null,
                'EXTRA' => '',
            ],
            [
                'TABLE_NAME' => 'users',
                'COLUMN_NAME' => 'created_at_dt',
                'DATA_TYPE' => 'datetime',
                'COLUMN_TYPE' => 'datetime',
                'IS_NULLABLE' => 'YES',
                'COLUMN_DEFAULT' => null,
                'EXTRA' => '',
            ],
        ], true); // created_at_dt exists → auto-detect triggers remove for created_at

        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
        ]);

        $this->assertCount(1, $logs);
        $this->assertSame('created_at', $logs[0]->getColumn());
        $this->assertSame('remove', $logs[0]->getFrom());
    }

    public function testUnrelatedDatetimeColumnWithBadDefaultIsStillFixed(): void
    {
        // A DATETIME column whose name happens to end in _dt but whose base name
        // has NO DATE/TIMESTAMP sibling should still be processed by the default-fixer.
        $pdo = $this->buildPdo([
            [
                'TABLE_NAME' => 'events',
                'COLUMN_NAME' => 'scheduled_dt',
                'DATA_TYPE' => 'datetime',
                'COLUMN_TYPE' => 'datetime',
                'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => '0000-00-00 00:00:00',
                'EXTRA' => '',
            ],
        ], false);

        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
        ]);

        // scheduled_dt has no DATE/TIMESTAMP sibling named 'scheduled', so it should
        // pass through to the default-fixer and produce a log for the zero default.
        $this->assertCount(1, $logs);
        $this->assertSame('scheduled_dt', $logs[0]->getColumn());
        $this->assertStringContainsString('zero', $logs[0]->getTo());
    }

    // --- Filter behaviour ---

    public function testSkipsTableMatchingSkipTableLike(): void
    {
        $pdo = $this->buildPdo([
            [
                'TABLE_NAME' => 'cache_data',
                'COLUMN_NAME' => 'created_at',
                'DATA_TYPE' => 'timestamp',
                'COLUMN_TYPE' => 'timestamp',
                'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => null,
                'EXTRA' => '',
            ],
        ], false);

        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
        ]);

        $this->assertCount(0, $logs);
    }

    public function testOnlyTablesFilterIsRespected(): void
    {
        $pdo = $this->buildPdo([
            [
                'TABLE_NAME' => 'users',
                'COLUMN_NAME' => 'created_at',
                'DATA_TYPE' => 'date',
                'COLUMN_TYPE' => 'date',
                'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => null,
                'EXTRA' => '',
            ],
            [
                'TABLE_NAME' => 'posts',
                'COLUMN_NAME' => 'published_at',
                'DATA_TYPE' => 'date',
                'COLUMN_TYPE' => 'date',
                'IS_NULLABLE' => 'YES',
                'COLUMN_DEFAULT' => null,
                'EXTRA' => '',
            ],
        ], false);

        $logs = (new ConvertTemporalColumnsToDatetimeRule())->apply($pdo, new NullOutput(), [
            'dry' => true,
            'only_tables' => ['users'],
        ]);

        foreach ($logs as $log) {
            $this->assertSame('users', $log->getTable());
        }
    }

    // --- helpers ---

    /**
     * @param list<array<string, mixed>> $columns
     */
    private function buildPdo(array $columns, bool $dtColExists): PDO
    {
        // query() is used by temporalColumns()
        $colStmt = $this->createMock(PDOStatement::class);
        $colStmt->method('fetchAll')->willReturn($columns);

        // prepare() + fetchColumn() is used by columnExists()
        $existsStmt = $this->createMock(PDOStatement::class);
        $existsStmt->method('execute')->willReturn(true);
        $existsStmt->method('fetchColumn')->willReturn($dtColExists ? '1' : false);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($colStmt);
        $pdo->method('prepare')->willReturn($existsStmt);

        return $pdo;
    }
}
